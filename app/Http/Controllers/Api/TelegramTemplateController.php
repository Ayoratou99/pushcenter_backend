<?php

namespace App\Http\Controllers\Api;

use App\Models\TelegramTemplate;
use App\Services\Telegram\TelegramComposer;
use App\Support\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram templates. Unlike WhatsApp nothing has to be approved: a template
 * can be edited, and is ready as soon as it is active.
 */
class TelegramTemplateController extends BaseController
{
    private const WRITABLE = [
        'business_id', 'name', 'description', 'category', 'body', 'parse_mode', 'buttons',
        'disable_web_page_preview', 'media_type', 'media_source', 'sample_data', 'status', 'is_active', 'metadata',
    ];

    /**
     * Files a template can keep. Telegram takes photos up to 10 MB and other
     * files up to 50 MB; 25 MB is what the upload limits of the server allow.
     */
    private const FILE_RULES = [
        'photo' => ['mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
        'video' => ['mimetypes:video/mp4', 'max:25600'],
        'document' => ['mimes:pdf,zip,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,rtf,txt,csv,jpg,jpeg,png,gif', 'max:25600'],
    ];

    public function __construct(private readonly TelegramComposer $composer)
    {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/telegram-templates",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="List Telegram templates",
     *     description="Every filter below can be combined.",
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="business_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"draft","active"})),
     *     @OA\Parameter(name="category", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="parse_mode", in="query", @OA\Schema(type="string", enum={"HTML","plain"})),
     *     @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="created_from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="created_to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Paginated Telegram templates")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = TelegramTemplate::query()->with('business:id,name');

        QueryFilters::restrictToUserBusinesses($query, $request);
        QueryFilters::exact($query, $request, ['business_id', 'status', 'category', 'parse_mode']);
        QueryFilters::inList($query, $request, ['status', 'category', 'business_id']);
        QueryFilters::booleans($query, $request, ['is_active']);
        QueryFilters::search($query, $request->input('search'), ['name', 'description', 'body', 'business.name']);
        QueryFilters::dateRange($query, $request, 'created_at');
        QueryFilters::sort($query, $request, ['name', 'status', 'category', 'usage_count', 'last_used_at', 'created_at', 'updated_at']);

        return $this->successResponse($query->paginate(QueryFilters::perPage($request)));
    }

    /**
     * @OA\Post(
     *     path="/api/v1/telegram-templates",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Create a Telegram template",
     *     description="body uses {{ variable }} placeholders and Telegram's HTML (b, i, u, s, a, code, pre, blockquote, tg-spoiler), or plain text. Variable values are escaped when sending. Buttons are links shown under the message. With media_type, a photo, video or document goes with the text, which becomes its caption (1024 characters, may be empty): media_source `file` keeps one file with the template (upload it with POST /telegram-templates/{id}/media), `url` takes a link from the application with each message (media_url).",
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"business_id", "name"},
     *         @OA\Property(property="business_id", type="integer"),
     *         @OA\Property(property="name", type="string", example="Rendez-vous confirmé"),
     *         @OA\Property(property="description", type="string"),
     *         @OA\Property(property="category", type="string", enum={"notification","transactional","marketing","otp"}),
     *         @OA\Property(property="body", type="string", example="<b>Bonjour {{ name }}</b>, votre rendez-vous est le {{ date }}."),
     *         @OA\Property(property="parse_mode", type="string", enum={"HTML","plain"}),
     *         @OA\Property(property="buttons", type="array", @OA\Items(type="object", @OA\Property(property="text", type="string"), @OA\Property(property="url", type="string"))),
     *         @OA\Property(property="disable_web_page_preview", type="boolean"),
     *         @OA\Property(property="media_type", type="string", nullable=true, enum={"photo","video","document"}),
     *         @OA\Property(property="media_source", type="string", nullable=true, enum={"file","url"}, description="Required with media_type"),
     *         @OA\Property(property="sample_data", type="object", example={"name": "Awa", "date": "12 octobre"})
     *     )),
     *     @OA\Response(response=201, description="Created"),
     *     @OA\Response(response=422, description="Validation error, or content Telegram would refuse")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(true));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        if ($deny = $this->denyUnlessBusinessAccessible($request->input('business_id'))) {
            return $deny;
        }

        $input = $this->withMedia($request->all(), null);

        if ($problems = $this->composer->problems($input) + $this->mediaProblems($input)) {
            return $this->validationErrorResponse($problems);
        }

        if (TelegramTemplate::withTrashed()->where('business_id', $request->input('business_id'))->where('name', $request->input('name'))->exists()) {
            return $this->validationErrorResponse(['name' => ['This application already has a Telegram template with this name (deleted ones included).']]);
        }

        $data = Arr::only($input, self::WRITABLE);
        $data['body'] = (string) ($data['body'] ?? '');
        $data['variables'] = $this->composer->placeholders($data);

        $template = TelegramTemplate::create($data);

        return $this->createdResponse($template->load('business:id,name'), 'Telegram template created successfully');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/telegram-templates/{id}",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Show a Telegram template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Telegram template"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(TelegramTemplate::class, $id)) {
            return $deny;
        }

        $template = TelegramTemplate::with('business:id,name')->find($id);

        return $template ? $this->successResponse($template) : $this->notFoundResponse('Telegram template');
    }

    /**
     * @OA\Put(
     *     path="/api/v1/telegram-templates/{id}",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Update a Telegram template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object", description="Fields to change, as for creation")),
     *     @OA\Response(response=200, description="Updated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, $id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(TelegramTemplate::class, $id)) {
            return $deny;
        }

        $template = TelegramTemplate::find($id);

        if (! $template) {
            return $this->notFoundResponse('Telegram template');
        }

        $input = $request->except('business_id');
        $validator = Validator::make($input, $this->rules(false));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $input = $this->withMedia($input, $template);
        $merged = array_merge(
            $template->only(['body', 'parse_mode', 'buttons', 'media_type', 'media_source']),
            Arr::only($input, ['body', 'parse_mode', 'buttons', 'media_type', 'media_source'])
        );

        if ($problems = $this->composer->problems($merged) + $this->mediaProblems($merged)) {
            return $this->validationErrorResponse($problems);
        }

        if (isset($input['name']) && TelegramTemplate::withTrashed()->where('business_id', $template->business_id)
            ->where('name', $input['name'])->whereKeyNot($template->getKey())->exists()) {
            return $this->validationErrorResponse(['name' => ['This application already has a Telegram template with this name (deleted ones included).']]);
        }

        $data = Arr::only($input, self::WRITABLE);
        if (array_key_exists('body', $data)) {
            $data['body'] = (string) ($data['body'] ?? '');
        }
        $data['variables'] = $this->composer->placeholders($merged);

        // Another kind of file, or no file kept any more: the old one goes.
        if ($template->media_path !== null
            && ($merged['media_type'] !== $template->media_type || $merged['media_source'] !== 'file')) {
            $template->forgetMediaFile();
        }

        $template->update($data);

        return $this->updatedResponse($template->fresh('business'), 'Telegram template updated successfully');
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/telegram-templates/{id}",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Delete a Telegram template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Deleted")
     * )
     */
    public function destroy($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(TelegramTemplate::class, $id)) {
            return $deny;
        }

        $template = TelegramTemplate::find($id);

        if (! $template) {
            return $this->notFoundResponse('Telegram template');
        }

        $template->forgetMediaFile();
        $template->save();
        $template->delete();

        return $this->deletedResponse('Telegram template deleted successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/telegram-templates/{id}/media",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Attach the file of a Telegram template",
     *     description="The photo, video or document sent with every message of this template (media_source becomes `file`), replacing the previous one. Kept on the server: the first message uploads it to Telegram, the next ones reuse it. Photos: JPEG, PNG or WebP up to 10 MB, width + height at most 10000 px. Videos: MP4 up to 25 MB. Documents: PDF, Office, OpenDocument, ZIP, text or images up to 25 MB.",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(
     *         required={"file"},
     *         @OA\Property(property="file", type="string", format="binary"),
     *         @OA\Property(property="type", type="string", enum={"photo","video","document"}, description="Defaults to the template's media_type")
     *     ))),
     *     @OA\Response(response=200, description="Template with its file"),
     *     @OA\Response(response=422, description="Wrong kind, size or dimensions, or a text too long for a caption")
     * )
     */
    public function uploadMedia(Request $request, $id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(TelegramTemplate::class, $id)) {
            return $deny;
        }

        $template = TelegramTemplate::find($id);

        if (! $template) {
            return $this->notFoundResponse('Telegram template');
        }

        $type = $request->input('type', $template->media_type);

        if (! in_array($type, TelegramTemplate::MEDIA_TYPES, true)) {
            return $this->validationErrorResponse(['type' => ['Say what the file is: photo, video or document.']]);
        }

        $validator = Validator::make($request->all(), [
            'file' => array_merge(['required', 'file'], self::FILE_RULES[$type]),
        ], [
            'file.mimetypes' => $type === 'photo' ? 'A photo is a JPEG, PNG or WebP image.' : 'A video is an MP4 file.',
            'file.mimes' => 'Documents: PDF, Word, Excel, PowerPoint, OpenDocument, RTF, text, CSV, ZIP or an image.',
            'file.max' => $type === 'photo' ? 'Telegram takes photos up to 10 MB.' : 'Files are limited to 25 MB.',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');

        if ($type === 'photo' && ($problem = $this->photoProblem($file))) {
            return $this->validationErrorResponse(['file' => [$problem]]);
        }

        // With a file the text becomes a caption.
        if ($this->composer->visibleLength((string) $template->body, $template->parse_mode === 'plain' ? null : $template->parse_mode) > TelegramComposer::MAX_CAPTION_LENGTH) {
            return $this->validationErrorResponse(['body' => ['With a file the text is a caption: shorten it to 1024 characters first.']]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin'));
        $path = TelegramTemplate::mediaDisk()->putFileAs("telegram-media/{$template->business_id}", $file, Str::uuid() . '.' . $extension);

        if (! $path) {
            return $this->errorResponse('The file could not be stored on the server.', null, 500);
        }

        // The new file is kept: the previous one can go.
        $template->forgetMediaFile();
        $template->forceFill([
            'media_type' => $type,
            'media_source' => 'file',
            'media_path' => $path,
            'media_name' => $this->fileName($file, $extension),
            'media_mime' => $file->getMimeType(),
            'media_size' => $file->getSize(),
            'media_file_ids' => null,
        ])->save();

        return $this->successResponse($template->fresh('business'), 'File attached to the template');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/telegram-templates/{id}/media",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Download the file of a Telegram template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="The file"),
     *     @OA\Response(response=404, description="No file kept for this template")
     * )
     */
    public function media($id): Response
    {
        if ($deny = $this->denyUnlessRecordAccessible(TelegramTemplate::class, $id)) {
            return $deny;
        }

        $template = TelegramTemplate::find($id);

        if (! $template?->media_path || ! TelegramTemplate::mediaDisk()->exists($template->media_path)) {
            return $this->notFoundResponse('Template file');
        }

        return TelegramTemplate::mediaDisk()->response(
            $template->media_path,
            $template->media_name,
            [
                'Content-Type' => $template->media_mime ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
            $template->media_type === 'document' ? 'attachment' : 'inline'
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/telegram-templates/{id}/media",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Remove the attachment of a Telegram template",
     *     description="The template goes back to a text message; a kept file is deleted.",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Template without attachment"),
     *     @OA\Response(response=422, description="The template has no text to fall back on")
     * )
     */
    public function destroyMedia($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(TelegramTemplate::class, $id)) {
            return $deny;
        }

        $template = TelegramTemplate::find($id);

        if (! $template) {
            return $this->notFoundResponse('Telegram template');
        }

        if (trim((string) $template->body) === '') {
            return $this->validationErrorResponse(['body' => ['Write the message first: without its file the template would be empty.']]);
        }

        $template->forgetMediaFile();
        $template->forceFill(['media_type' => null, 'media_source' => null])->save();

        return $this->successResponse($template->fresh('business'), 'Attachment removed');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/telegram-templates/{id}/activate",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Activate a Telegram template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Activated")
     * )
     */
    public function activate($id): JsonResponse
    {
        return $this->toggle($id, true);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/telegram-templates/{id}/deactivate",
     *     tags={"Telegram Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Deactivate a Telegram template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Deactivated")
     * )
     */
    public function deactivate($id): JsonResponse
    {
        return $this->toggle($id, false);
    }

    private function toggle($id, bool $active): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(TelegramTemplate::class, $id)) {
            return $deny;
        }

        $template = TelegramTemplate::find($id);

        if (! $template) {
            return $this->notFoundResponse('Telegram template');
        }

        $template->update($active ? ['is_active' => true, 'status' => 'active'] : ['is_active' => false]);

        return $this->updatedResponse(
            $template->fresh('business'),
            $active ? 'Telegram template activated successfully' : 'Telegram template deactivated successfully'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'business_id' => $creating ? 'required|integer|exists:businesses,id' : 'prohibited',
            'name' => "{$required}|string|max:255",
            'description' => 'nullable|string',
            'category' => ['nullable', Rule::in(['notification', 'transactional', 'marketing', 'otp'])],
            // May be empty when a file goes with it (checked by the composer).
            'body' => 'nullable|string|max:10000',
            'parse_mode' => ['nullable', Rule::in(['HTML', 'plain'])],
            'buttons' => 'nullable|array|max:' . TelegramComposer::MAX_BUTTONS,
            'buttons.*.text' => 'required|string|max:64',
            'buttons.*.url' => 'required|string|max:2048',
            'disable_web_page_preview' => 'nullable|boolean',
            'media_type' => ['nullable', Rule::in(TelegramTemplate::MEDIA_TYPES)],
            'media_source' => ['nullable', Rule::in(TelegramTemplate::MEDIA_SOURCES)],
            'sample_data' => 'nullable|array',
            'status' => ['nullable', Rule::in(['draft', 'active'])],
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ];
    }

    /**
     * No attachment clears its source; a source is kept from the template
     * when only the type changes.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function withMedia(array $input, ?TelegramTemplate $template): array
    {
        if (array_key_exists('media_type', $input) && empty($input['media_type'])) {
            $input['media_type'] = null;
            $input['media_source'] = null;
        } elseif (! empty($input['media_type']) && empty($input['media_source']) && $template?->media_source) {
            $input['media_source'] = $template->media_source;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, string>>
     */
    private function mediaProblems(array $input): array
    {
        if (! empty($input['media_type']) && empty($input['media_source'])) {
            return ['media_source' => ['Say where the file comes from: kept with the template (file) or given with each message (url).']];
        }

        if (empty($input['media_type']) && ! empty($input['media_source'])) {
            return ['media_type' => ['Say what the file is: photo, video or document.']];
        }

        return [];
    }

    /**
     * What Telegram refuses in a photo: width + height over 10000 pixels, or
     * one side more than 20 times the other.
     */
    private function photoProblem(UploadedFile $file): ?string
    {
        $size = @getimagesize($file->getRealPath());

        if (! $size || ! $size[0] || ! $size[1]) {
            return 'This image cannot be read.';
        }

        [$width, $height] = $size;

        if ($width + $height > 10000) {
            return 'Telegram refuses photos whose width and height add up to more than 10000 pixels.';
        }

        if (max($width, $height) / min($width, $height) > 20) {
            return 'Telegram refuses photos more than 20 times longer than wide.';
        }

        return null;
    }

    /**
     * The name Telegram shows for a document: the original one, without path
     * or control characters.
     */
    private function fileName(UploadedFile $file, string $extension): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename((string) $file->getClientOriginalName())));

        return Str::limit($name !== '' ? $name : 'file.' . $extension, 200, '');
    }
}
