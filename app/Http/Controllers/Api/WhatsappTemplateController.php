<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Models\WhatsappTemplate;
use App\Repositories\Contracts\WhatsappTemplateRepositoryInterface;
use App\Services\AyosPush\AyosPushException;
use App\Services\AyosPush\AyosPushTemplateService;
use App\Support\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WhatsappTemplateController extends BaseController
{
    /**
     * Fields the console may write. Status and provider fields only change
     * through submission and synchronisation with AyosPush.
     */
    private const WRITABLE_FIELDS = [
        'business_id', 'name', 'display_name', 'description', 'language', 'category',
        'header', 'body', 'footer', 'buttons', 'variables', 'sample_data',
        'is_active', 'allow_variables', 'max_variables', 'cost_per_message', 'metadata',
    ];

    public function __construct(
        protected WhatsappTemplateRepositoryInterface $templateRepository,
        protected AyosPushTemplateService $ayosPush,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/whatsapp-templates",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="List WhatsApp templates",
     *     description="Every filter below can be combined.",
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="business_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"draft","pending","approved","rejected","disabled"})),
     *     @OA\Parameter(name="category", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="language", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="created_from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="created_to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="sort_by", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Paginated WhatsApp templates")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->templateRepository->newQuery()->with('business:id,name');

        QueryFilters::restrictToUserBusinesses($query, $request);
        QueryFilters::exact($query, $request, ['business_id', 'status', 'category', 'language', 'facebook_status', 'provider']);
        QueryFilters::inList($query, $request, ['status', 'category', 'language', 'business_id']);
        QueryFilters::booleans($query, $request, ['is_active']);
        QueryFilters::search($query, $request->input('search'), [
            'name', 'display_name', 'description', 'body', 'provider_template_name', 'business.name',
        ]);
        QueryFilters::dateRange($query, $request, 'created_at');
        QueryFilters::numericRange($query, $request, 'usage_count');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true)->where('status', 'approved');
        }

        QueryFilters::sort($query, $request, [
            'name', 'display_name', 'status', 'category', 'language', 'usage_count',
            'last_used_at', 'approved_at', 'submitted_at', 'created_at', 'updated_at',
        ]);

        return $this->successResponse($query->paginate(QueryFilters::perPage($request)));
    }

    /**
     * @OA\Post(
     *     path="/api/v1/whatsapp-templates",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Create a WhatsApp template (draft)",
     *     description="Always created as a draft. Submit it with POST /whatsapp-templates/{id}/submit: AyosPush then submits it to Meta. Variables are numbered ({{1}}, {{2}}…) and need an example value each in sample_data.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"business_id", "name", "display_name", "language", "category"},
     *             @OA\Property(property="business_id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="order_confirmation"),
     *             @OA\Property(property="display_name", type="string", example="Order confirmation"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="language", type="string", enum={"fr", "en"}, example="fr"),
     *             @OA\Property(property="category", type="string", enum={"MARKETING", "UTILITY", "AUTHENTICATION"}, example="UTILITY"),
     *             @OA\Property(property="header", type="object", nullable=true,
     *                 @OA\Property(property="format", type="string", enum={"TEXT", "IMAGE", "VIDEO", "DOCUMENT"}),
     *                 @OA\Property(property="text", type="string", description="TEXT headers, no variable"),
     *                 @OA\Property(property="media_url", type="string", description="URL returned by POST /whatsapp-templates/media")
     *             ),
     *             @OA\Property(property="body", type="string", example="Bonjour {{1}}, votre commande {{2}} est confirmée."),
     *             @OA\Property(property="footer", type="object", nullable=true, @OA\Property(property="text", type="string")),
     *             @OA\Property(property="buttons", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="type", type="string", enum={"QUICK_REPLY", "URL", "PHONE_NUMBER"}),
     *                 @OA\Property(property="text", type="string"),
     *                 @OA\Property(property="url", type="string"),
     *                 @OA\Property(property="phone_number", type="string")
     *             )),
     *             @OA\Property(property="sample_data", type="object", example={"1": "Awa", "2": "CMD-001"}),
     *             @OA\Property(property="metadata", type="object", description="AUTHENTICATION templates: code_expiration_minutes (1-90), add_security_recommendation")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Draft created"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $input = AyosPushTemplateService::normalizeContent($request->all());

        $validator = Validator::make($input, $this->rules(true));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        if ($deny = $this->denyUnlessBusinessAccessible($input['business_id'])) {
            return $deny;
        }

        if ($this->nameTaken((int) $input['business_id'], $input['name'], $input['language'])) {
            return $this->validationErrorResponse([
                'name' => ['This application already has a WhatsApp template with this name in this language (deleted ones included).'],
            ]);
        }

        $data = Arr::only($input, self::WRITABLE_FIELDS);
        $data['status'] = 'draft';

        $template = $this->templateRepository->create($data);

        return $this->createdResponse($template, 'WhatsApp template created successfully');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/whatsapp-templates/{id}",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Show a WhatsApp template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Whatsapp template"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->find($id);

        if (!$template) {
            return $this->notFoundResponse('WhatsApp template');
        }

        return $this->successResponse($template);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/whatsapp-templates/{id}",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="WhatsApp templates cannot be edited",
     *     description="Like on Meta, a WhatsApp template is never modified: create a new one, or delete this one. Always answers 422. Activation goes through /activate and /deactivate.",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=422, description="Not editable")
     * )
     */
    public function update(Request $request, $id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(WhatsappTemplate::class, $id)) {
            return $deny;
        }

        if (! WhatsappTemplate::whereKey($id)->exists()) {
            return $this->notFoundResponse('WhatsApp template');
        }

        return $this->errorResponse(
            'WhatsApp templates cannot be edited: create a new template, or delete this one.',
            null,
            422
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/whatsapp-templates/{id}",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Delete a WhatsApp template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Deleted"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function destroy($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = WhatsappTemplate::find($id);

        if (!$template) {
            return $this->notFoundResponse('WhatsApp template');
        }

        // A draft that never reached AyosPush is gone for good, freeing its
        // name for the corrected template that replaces it. Anything AyosPush
        // knows stays soft-deleted: history, and no re-import at the next sync.
        if ($template->status === 'draft' && ! $template->isSubmitted()) {
            $template->forceDelete();
        } else {
            $template->delete();
        }

        return $this->deletedResponse('WhatsApp template deleted successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/whatsapp-templates/{id}/activate",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Activate a WhatsApp template",
     *     description="Only approved templates can be activated.",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Activated"),
     *     @OA\Response(response=400, description="Cannot be activated in its current state"),
     *     @OA\Response(response=403, description="Application outside the user's scope")
     * )
     */
    public function activate($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->activate($id);

        if (!$template) {
            return $this->errorResponse('WhatsApp template not found or not approved yet');
        }

        return $this->updatedResponse($template, 'WhatsApp template activated successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/whatsapp-templates/{id}/deactivate",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Deactivate a WhatsApp template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Deactivated"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function deactivate($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->deactivate($id);

        if (!$template) {
            return $this->notFoundResponse('WhatsApp template');
        }

        return $this->updatedResponse($template, 'WhatsApp template deactivated successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/whatsapp-templates/{id}/submit",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Submit a template to Meta through AyosPush",
     *     description="Creates the template on AyosPush (POST /v1/templates with submit_for_approval), which submits it to Meta. The template becomes `pending`; its approval is picked up by the synchronisation (every 15 minutes, or on demand).",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Submitted, now pending"),
     *     @OA\Response(response=409, description="Not a draft: already submitted, or rejected (create a new template)"),
     *     @OA\Response(response=422, description="Not acceptable for AyosPush / Meta (errors lists why) or refused by AyosPush"),
     *     @OA\Response(response=429, description="AyosPush rate limit"),
     *     @OA\Response(response=502, description="AyosPush unreachable or failing")
     * )
     */
    public function submitForApproval($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = WhatsappTemplate::find($id);

        if (!$template) {
            return $this->notFoundResponse('WhatsApp template');
        }

        // Only a draft goes to Meta, once. A rejected template is replaced by
        // a new one, like on Meta.
        if ($template->status !== 'draft' || $template->isSubmitted()) {
            return $this->errorResponse(
                $template->status === 'rejected'
                    ? 'Meta rejected this template: create a new template with the corrections.'
                    : "This template was already submitted to AyosPush (status: {$template->status}).",
                null,
                409
            );
        }

        try {
            $template = $this->ayosPush->submit($template);
        } catch (ValidationException $e) {
            return $this->errorResponse('The template cannot be submitted to AyosPush yet.', $e->errors(), 422);
        } catch (AyosPushException $e) {
            return $this->errorResponse($e->getMessage(), $e->context(), $e->consoleStatus());
        }

        return $this->updatedResponse($template, 'WhatsApp template submitted to AyosPush for Meta approval');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/whatsapp-templates/{id}/sync",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Refresh the approval status of a template from AyosPush",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Status refreshed"),
     *     @OA\Response(response=422, description="Not submitted, not configured, or refused by AyosPush")
     * )
     */
    public function sync($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = WhatsappTemplate::find($id);

        if (!$template) {
            return $this->notFoundResponse('WhatsApp template');
        }

        try {
            $template = $this->ayosPush->refresh($template);
        } catch (ValidationException $e) {
            return $this->errorResponse(Arr::first(Arr::flatten($e->errors())) ?? 'Cannot synchronise this template.', $e->errors(), 422);
        } catch (AyosPushException $e) {
            return $this->errorResponse($e->getMessage(), $e->context(), $e->consoleStatus());
        }

        return $this->successResponse($template, 'Status refreshed from AyosPush');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/businesses/{businessId}/whatsapp-templates/sync",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Synchronise the templates of an application with AyosPush",
     *     description="Refreshes every submitted template and, unless import=false, copies the templates that only exist on AyosPush (created in its dashboard) so they can be used here.",
     *     @OA\Parameter(name="businessId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(@OA\Property(property="import", type="boolean", default=true))),
     *     @OA\Response(response=200, description="Counts: updated, unchanged, imported, missing, ignored"),
     *     @OA\Response(response=422, description="Not configured or refused by AyosPush")
     * )
     */
    public function syncBusiness(Request $request, int $businessId): JsonResponse
    {
        if ($deny = $this->denyUnlessBusinessAccessible($businessId)) {
            return $deny;
        }

        $business = Business::find($businessId);

        if (!$business) {
            return $this->notFoundResponse('Application');
        }

        try {
            $counts = $this->ayosPush->syncBusiness($business, $request->boolean('import', true));
        } catch (ValidationException $e) {
            return $this->errorResponse(Arr::first(Arr::flatten($e->errors())) ?? 'Cannot synchronise.', $e->errors(), 422);
        } catch (AyosPushException $e) {
            return $this->errorResponse($e->getMessage(), $e->context(), $e->consoleStatus());
        }

        return $this->successResponse($counts, sprintf(
            '%d updated, %d imported, %d unchanged%s',
            $counts['updated'],
            $counts['imported'],
            $counts['unchanged'],
            $counts['missing'] ? ", {$counts['missing']} no longer on AyosPush" : ''
        ));
    }

    /**
     * @OA\Post(
     *     path="/api/v1/whatsapp-templates/media",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Upload the media of a template header to AyosPush",
     *     description="Forwards the file to AyosPush (POST /v1/templates/upload-media) and returns the media_url to put in header.media_url. Image: JPEG, PNG or WebP up to 8 MB. Video: MP4 or 3GPP up to 25 MB. Document: PDF or Office up to 25 MB.",
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(
     *         required={"business_id", "type", "file"},
     *         @OA\Property(property="business_id", type="integer"),
     *         @OA\Property(property="type", type="string", enum={"image", "video", "document"}),
     *         @OA\Property(property="file", type="string", format="binary")
     *     ))),
     *     @OA\Response(response=200, description="media_url, file_name, file_size, mime_type, type"),
     *     @OA\Response(response=422, description="Invalid file or refused by AyosPush")
     * )
     */
    public function uploadMedia(Request $request): JsonResponse
    {
        $mimes = [
            'image' => ['mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
            'video' => ['mimetypes:video/mp4,video/3gpp', 'max:25600'],
            'document' => [
                'mimetypes:application/pdf,application/msword,'
                    . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
                    . 'application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
                    . 'application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'max:25600',
            ],
        ];

        $validator = Validator::make($request->all(), [
            'business_id' => 'required|integer|exists:businesses,id',
            'type' => 'required|in:image,video,document',
            'file' => array_merge(['required', 'file'], $mimes[$request->input('type')] ?? []),
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        if ($deny = $this->denyUnlessBusinessAccessible($request->input('business_id'))) {
            return $deny;
        }

        try {
            $media = $this->ayosPush->uploadMedia(
                Business::findOrFail($request->input('business_id')),
                $request->file('file'),
                $request->input('type')
            );
        } catch (ValidationException $e) {
            return $this->errorResponse(Arr::first(Arr::flatten($e->errors())) ?? 'Cannot upload.', $e->errors(), 422);
        } catch (AyosPushException $e) {
            return $this->errorResponse($e->getMessage(), $e->context(), $e->consoleStatus());
        }

        return $this->successResponse($media, 'Media uploaded to AyosPush');
    }

    /**
     * Structure rules for the fields sent. What AyosPush and Meta additionally
     * require (numbered variables with examples, no header variable…) is
     * checked on submission, so drafts can be saved while being written.
     *
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'business_id' => $creating ? 'required|integer|exists:businesses,id' : 'prohibited',
            'name' => "{$required}|string|max:255",
            'display_name' => "{$required}|string|max:255",
            'description' => 'nullable|string',
            // Only these two reach AyosPush.
            'language' => [$required, 'string', Rule::in(AyosPushTemplateService::LANGUAGES)],
            'category' => [$required, 'string', Rule::in(AyosPushTemplateService::CATEGORIES)],
            'header' => 'nullable|array',
            'header.format' => ['required_with:header', Rule::in(AyosPushTemplateService::HEADER_FORMATS)],
            'header.text' => 'nullable|string|max:60',
            'header.media_url' => 'nullable|url|max:2048',
            'header.media_file_name' => 'nullable|string|max:255',
            'header.media_file_size' => 'nullable|integer|min:0',
            'body' => "{$required}|string|max:1024",
            'footer' => 'nullable|array',
            'footer.text' => 'nullable|string|max:60',
            'buttons' => 'nullable|array|max:10',
            'buttons.*.type' => ['required', Rule::in(AyosPushTemplateService::BUTTON_TYPES)],
            'buttons.*.text' => 'required|string|max:25',
            'buttons.*.url' => 'nullable|string|max:2000',
            'buttons.*.phone_number' => 'nullable|string|max:25',
            'variables' => 'nullable|array',
            'sample_data' => 'nullable|array',
            'sample_data.*' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft',
            'is_active' => 'nullable|boolean',
            'allow_variables' => 'nullable|boolean',
            'max_variables' => 'nullable|integer|min:0|max:20',
            'cost_per_message' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
            'metadata.code_expiration_minutes' => 'nullable|integer|min:1|max:90',
            'metadata.add_security_recommendation' => 'nullable|boolean',
        ];
    }

    /**
     * Names are unique per application and language, deleted templates
     * included (the database index covers them).
     */
    private function nameTaken(int $businessId, string $name, string $language, ?int $ignoreId = null): bool
    {
        return WhatsappTemplate::withTrashed()
            ->where('business_id', $businessId)
            ->where('name', $name)
            ->where('language', $language)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
