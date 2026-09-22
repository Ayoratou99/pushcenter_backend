<?php

namespace App\Http\Controllers\Api;

use App\Models\Business;
use App\Services\TemplateTransferService;
use App\Support\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export templates to JSON/TXT and import them back into a chosen application.
 *
 * @OA\Tag(name="Template transfer", description="Template export and import")
 */
class TemplateTransferController extends BaseController
{
    public function __construct(private TemplateTransferService $transfer)
    {
    }

    /**
     * @OA\Post(
     *     path="/api/v1/templates/{type}/{id}/duplicate",
     *     tags={"Template transfer"},
     *     security={{"bearerAuth":{}}},
     *     summary="Duplicate a template",
     *     description="Copies the template into the same application as an inactive draft, under a free name. A WhatsApp copy is a new draft to submit to Meta (the original is never edited).",
     *     @OA\Parameter(name="type", in="path", required=true, @OA\Schema(type="string", enum={"email","sms","whatsapp","telegram"})),
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=201, description="The copy"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function duplicate(string $type, $id): JsonResponse
    {
        try {
            $model = $this->transfer->modelFor($type);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        }

        $template = $model::find($id);

        if (! $template) {
            return $this->notFoundResponse('Template');
        }

        if ($deny = $this->denyUnlessBusinessAccessible($template->business_id)) {
            return $deny;
        }

        $copy = $this->transfer->duplicate($template, $type);

        return $this->createdResponse(
            array_merge($copy->fresh()->toArray(), ['type' => $type]),
            "Duplicated as \"{$copy->name}\", an inactive draft."
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/templates/{type}/{id}/export",
     *     tags={"Template transfer"},
     *     security={{"bearerAuth":{}}},
     *     summary="Export one template",
     *     @OA\Parameter(name="type", in="path", required=true, @OA\Schema(type="string", enum={"email","sms","whatsapp","telegram"})),
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="format", in="query", @OA\Schema(type="string", enum={"json","txt"})),
     *     @OA\Parameter(name="download", in="query", description="1 to receive a file attachment, 0 for an inline JSON body", @OA\Schema(type="boolean")),
     *     @OA\Response(response=200, description="Template document")
     * )
     */
    public function export(Request $request, string $type, $id): JsonResponse|StreamedResponse
    {
        $format = $this->resolveFormat($request);

        try {
            $model = $this->transfer->modelFor($type);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        }

        $template = $model::with('business:id,name')->find($id);

        if (! $template) {
            return $this->notFoundResponse('Template');
        }

        if ($deny = $this->denyUnlessBusinessAccessible($template->business_id)) {
            return $deny;
        }

        $document = $this->transfer->export($template, $type);

        if (! $request->boolean('download', true)) {
            return $this->successResponse([
                'filename' => $this->transfer->filename($template->name, $type, $format),
                'format' => $format,
                'content' => $this->transfer->serialize($document, $format),
                'document' => $document,
            ]);
        }

        return $this->streamDocument(
            $this->transfer->serialize($document, $format),
            $this->transfer->filename($template->name, $type, $format),
            $format
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/templates/export",
     *     tags={"Template transfer"},
     *     security={{"bearerAuth":{}}},
     *     summary="Export several templates of the same type at once",
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"type","ids"},
     *         @OA\Property(property="type", type="string", enum={"email","sms","whatsapp"}),
     *         @OA\Property(property="ids", type="array", @OA\Items(type="integer")),
     *         @OA\Property(property="format", type="string", enum={"json","txt"})
     *     )),
     *     @OA\Response(response=200, description="Bundle document")
     * )
     */
    public function exportMany(Request $request): JsonResponse|StreamedResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', Rule::in(array_keys(TemplateTransferService::TYPES))],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'format' => ['nullable', Rule::in([TemplateTransferService::FORMAT_JSON, TemplateTransferService::FORMAT_TXT])],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $type = $request->input('type');
        $format = $this->resolveFormat($request);
        $model = $this->transfer->modelFor($type);

        $query = $model::whereIn('id', $request->input('ids'));
        QueryFilters::restrictToUserBusinesses($query, $request);

        $templates = $query->get();

        if ($templates->isEmpty()) {
            return $this->notFoundResponse('Template');
        }

        $document = $this->transfer->exportMany($templates, $type);
        $filename = sprintf('%s-templates-%s.%s', $type, now()->format('Ymd-His'), $format);

        if (! $request->boolean('download', true)) {
            return $this->successResponse([
                'filename' => $filename,
                'format' => $format,
                'content' => $this->transfer->serialize($document, $format),
                'document' => $document,
            ]);
        }

        return $this->streamDocument($this->transfer->serialize($document, $format), $filename, $format);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/templates/import/preview",
     *     tags={"Template transfer"},
     *     security={{"bearerAuth":{}}},
     *     summary="Read an export file without saving anything",
     *     description="Returns the detected type and the template names, so the UI can ask which application to attach them to.",
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(
     *         @OA\Property(property="file", type="string", format="binary"),
     *         @OA\Property(property="content", type="string", description="Raw file content, when no upload is used")
     *     ))),
     *     @OA\Response(response=200, description="Parsed preview")
     * )
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            $contents = $this->readPayload($request);
            $document = $this->transfer->parse($contents);
            $extracted = $this->transfer->extract($document);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        }

        $type = $extracted['type'] ?? $this->transfer->guessType($extracted['templates'][0]);

        if (! $type || ! isset(TemplateTransferService::TYPES[$type])) {
            return $this->errorResponse(
                'Could not tell which kind of template this file holds. Pick the type manually.',
                ['type' => ['Unknown template type.']],
                422
            );
        }

        return $this->successResponse([
            'type' => $type,
            'count' => count($extracted['templates']),
            'templates' => collect($extracted['templates'])->map(fn ($t) => [
                'name' => $t['name'] ?? 'Untitled template',
                'description' => $t['description'] ?? null,
                'category' => $t['category'] ?? null,
                'language' => $t['language'] ?? null,
                'subject' => $t['subject'] ?? null,
            ])->values(),
            'source' => $document['source'] ?? null,
            'exported_at' => $document['exported_at'] ?? null,
        ], 'File read successfully. Choose the application to attach these templates to.');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/templates/import",
     *     tags={"Template transfer"},
     *     security={{"bearerAuth":{}}},
     *     summary="Import templates into an application",
     *     description="Only the target application is required; the type is taken from the file. Imported templates always land as inactive drafts.",
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(
     *         required={"business_id"},
     *         @OA\Property(property="business_id", type="integer"),
     *         @OA\Property(property="file", type="string", format="binary"),
     *         @OA\Property(property="content", type="string"),
     *         @OA\Property(property="type", type="string", enum={"email","sms","whatsapp"}, description="Overrides the type declared in the file"),
     *         @OA\Property(property="name", type="string", description="Rename a single imported template")
     *     ))),
     *     @OA\Response(response=201, description="Imported templates")
     * )
     */
    public function import(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|integer|exists:businesses,id',
            'type' => ['nullable', Rule::in(array_keys(TemplateTransferService::TYPES))],
            'name' => 'nullable|string|max:200',
            'file' => 'nullable|file|max:10240|mimetypes:application/json,text/plain,text/json,application/octet-stream',
            'content' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $business = Business::find($request->input('business_id'));

        if (! $business) {
            return $this->notFoundResponse('Business');
        }

        if ($deny = $this->denyUnlessBusinessAccessible($business->getKey())) {
            return $deny;
        }

        try {
            $contents = $this->readPayload($request);
            $document = $this->transfer->parse($contents);
            $extracted = $this->transfer->extract($document);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        }

        $type = $request->input('type')
            ?? $extracted['type']
            ?? $this->transfer->guessType($extracted['templates'][0]);

        if (! $type || ! isset(TemplateTransferService::TYPES[$type])) {
            return $this->errorResponse(
                'Could not tell which kind of template this file holds. Pick the type manually.',
                ['type' => ['Unknown template type.']],
                422
            );
        }

        $model = $this->transfer->modelFor($type);
        $payloads = $extracted['templates'];
        $nameOverride = count($payloads) === 1 ? $request->input('name') : null;

        try {
            $created = DB::transaction(function () use ($payloads, $type, $business, $nameOverride, $model) {
                $rows = [];

                foreach ($payloads as $payload) {
                    $attributes = $this->transfer->prepareAttributes($payload, $type, $business, $nameOverride);
                    $rows[] = $model::create($attributes);
                }

                return $rows;
            });
        } catch (\Throwable $e) {
            return $this->errorResponse('Import failed: ' . $e->getMessage(), null, 500);
        }

        return $this->createdResponse([
            'type' => $type,
            'business' => ['id' => $business->id, 'name' => $business->name],
            'imported' => count($created),
            'templates' => $created,
        ], count($created) . ' template(s) imported into ' . $business->name . ' as inactive drafts.');
    }

    /* ------------------------------------------------------------------ */

    private function readPayload(Request $request): string
    {
        if ($request->hasFile('file')) {
            $file = $request->file('file');

            if (! $file->isValid()) {
                throw ValidationException::withMessages(['file' => ['The uploaded file is not readable.']]);
            }

            return (string) file_get_contents($file->getRealPath());
        }

        if ($request->filled('content')) {
            return (string) $request->input('content');
        }

        throw ValidationException::withMessages([
            'file' => ['Attach a .json or .txt export file, or send its content in the "content" field.'],
        ]);
    }

    private function resolveFormat(Request $request): string
    {
        return $request->input('format') === TemplateTransferService::FORMAT_TXT
            ? TemplateTransferService::FORMAT_TXT
            : TemplateTransferService::FORMAT_JSON;
    }

    private function streamDocument(string $body, string $filename, string $format): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print($body),
            $filename,
            [
                'Content-Type' => $format === TemplateTransferService::FORMAT_TXT
                    ? 'text/plain; charset=UTF-8'
                    : 'application/json; charset=UTF-8',
            ]
        );
    }
}
