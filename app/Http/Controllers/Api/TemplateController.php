<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Contracts\TemplateRepositoryInterface;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TemplateController extends BaseController
{
    protected $templateRepository;

    public function __construct(TemplateRepositoryInterface $templateRepository)
    {
        $this->templateRepository = $templateRepository;
    }


    /**
     * @OA\Get(
     *     path="/api/v1/templates",
     *     tags={"Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="List templates (generic registry)",
     *     description="Every filter below can be combined.",
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="business_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"email","sms","whatsapp"})),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"draft","active","archived"})),
     *     @OA\Parameter(name="category", in="query", @OA\Schema(type="string", enum={"marketing","transactional","notification"})),
     *     @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="created_from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="created_to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="sort_by", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Paginated templates")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->templateRepository->newQuery()->with('business:id,name');

        QueryFilters::restrictToUserBusinesses($query, $request);
        QueryFilters::exact($query, $request, ['business_id', 'type', 'status', 'category', 'template_identifier']);
        QueryFilters::inList($query, $request, ['type', 'status', 'category', 'business_id']);
        QueryFilters::booleans($query, $request, ['is_active']);
        QueryFilters::search($query, $request->input('search'), [
            'name', 'description', 'template_identifier', 'business.name',
        ]);
        QueryFilters::dateRange($query, $request, 'created_at');
        QueryFilters::numericRange($query, $request, 'usage_count');

        QueryFilters::sort($query, $request, [
            'name', 'type', 'status', 'category', 'usage_count', 'last_used_at', 'created_at', 'updated_at',
        ]);

        return $this->successResponse($query->paginate(QueryFilters::perPage($request)));
    }

    /**
     * Store a newly created template.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:email,sms,whatsapp',
            'category' => 'required|in:marketing,transactional,notification',
            'status' => 'nullable|in:draft,active,archived',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        if ($deny = $this->denyUnlessBusinessAccessible($request->input('business_id'))) {
            return $deny;
        }

        try {
            $data = $request->all();
            
            // Generate template identifier if not provided
            if (empty($data['template_identifier'])) {
                $data['template_identifier'] = \App\Models\Template::generateIdentifier(
                    $data['name'],
                    $data['business_id'],
                    $data['type']
                );
            }
            
            $template = $this->templateRepository->create($data);

            return $this->createdResponse($template, 'Template created successfully');
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle unique constraint violation
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'duplicate key')) {
                return $this->errorResponse(
                    'A template with this name already exists for this business and type. Please choose a different name.',
                    ['name' => ['This template name is already in use for this business and type.']],
                    422
                );
            }
            
            return $this->errorResponse('Failed to create template: ' . $e->getMessage(), null, 500);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create template: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Display the specified template.
     */
    public function show($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Template::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->find($id);

        if (!$template) {
            return $this->notFoundResponse('Template');
        }

        return $this->successResponse($template);
    }

    /**
     * Update the specified template.
     */
    public function update(Request $request, $id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Template::class, $id)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|in:email,sms,whatsapp',
            'category' => 'sometimes|in:marketing,transactional,notification',
            'status' => 'nullable|in:draft,active,archived',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $template = $this->templateRepository->update($id, $request->all());

        if (!$template) {
            return $this->notFoundResponse('Template');
        }

        return $this->updatedResponse($template, 'Template updated successfully');
    }

    /**
     * Remove the specified template.
     */
    public function destroy($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Template::class, $id)) {
            return $deny;
        }

        $deleted = $this->templateRepository->delete($id);

        if (!$deleted) {
            return $this->notFoundResponse('Template');
        }

        return $this->deletedResponse('Template deleted successfully');
    }

    /**
     * Activate a template.
     */
    public function activate($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Template::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->activate($id);

        if (!$template) {
            return $this->notFoundResponse('Template');
        }

        return $this->updatedResponse($template, 'Template activated successfully');
    }

    /**
     * Deactivate a template.
     */
    public function deactivate($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Template::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->deactivate($id);

        if (!$template) {
            return $this->notFoundResponse('Template');
        }

        return $this->updatedResponse($template, 'Template deactivated successfully');
    }
}
