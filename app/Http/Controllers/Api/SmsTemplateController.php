<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Contracts\SmsTemplateRepositoryInterface;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class SmsTemplateController extends BaseController
{
    protected $templateRepository;

    public function __construct(SmsTemplateRepositoryInterface $templateRepository)
    {
        $this->templateRepository = $templateRepository;
    }


    /**
     * @OA\Get(
     *     path="/api/v1/sms-templates",
     *     tags={"SMS Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="List SMS templates",
     *     description="Every filter below can be combined.",
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="business_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"draft","active","archived"})),
     *     @OA\Parameter(name="category", in="query", @OA\Schema(type="string", enum={"marketing","transactional","otp","notification"})),
     *     @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="created_from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="created_to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="sort_by", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Paginated SMS templates")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->templateRepository->newQuery()->with('business:id,name');

        QueryFilters::restrictToUserBusinesses($query, $request);
        QueryFilters::exact($query, $request, ['business_id', 'status', 'category']);
        QueryFilters::inList($query, $request, ['status', 'category', 'business_id']);
        QueryFilters::booleans($query, $request, ['is_active']);
        QueryFilters::search($query, $request->input('search'), ['name', 'description', 'message', 'business.name']);
        QueryFilters::dateRange($query, $request, 'created_at');
        QueryFilters::numericRange($query, $request, 'usage_count');
        QueryFilters::numericRange($query, $request, 'cost_per_message');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true)->where('status', 'active');
        }

        QueryFilters::sort($query, $request, [
            'name', 'status', 'category', 'usage_count', 'cost_per_message', 'last_used_at', 'created_at', 'updated_at',
        ]);

        return $this->successResponse($query->paginate(QueryFilters::perPage($request)));
    }

    /**
     * @OA\Post(
     *     path="/api/v1/sms-templates",
     *     tags={"SMS Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Create a new SMS template",
     *     description="Creates a new SMS template with content and variables",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"business_id", "name", "content", "category"},
     *             @OA\Property(property="business_id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="SMS Verification Code"),
     *             @OA\Property(property="description", type="string", example="SMS template for verification codes"),
     *             @OA\Property(property="content", type="string", example="Your verification code is {{code}}"),
     *             @OA\Property(property="variables", type="array", @OA\Items(type="string"), example={"code", "company_name"}),
     *             @OA\Property(property="sample_data", type="object", example={"code": "123456", "company_name": "Acme"}),
     *             @OA\Property(property="category", type="string", enum={"marketing", "transactional", "notification"}, example="transactional"),
     *             @OA\Property(property="status", type="string", enum={"draft", "active", "archived"}, example="draft"),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="metadata", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="SMS template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMS template created successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     *
     * Store a newly created SMS template.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'message' => 'required|string|max:1530', // 10 SMS segments max
            'variables' => 'nullable|array',
            'sample_data' => 'nullable|array',
            'category' => 'required|in:marketing,transactional,otp,notification',
            'status' => 'nullable|in:draft,active,archived',
            'is_active' => 'nullable|boolean',
            'sender_id' => 'nullable|string|max:11',
            'cost_per_message' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        if ($deny = $this->denyUnlessBusinessAccessible($request->input('business_id'))) {
            return $deny;
        }

        $template = $this->templateRepository->create($request->all());

        return $this->createdResponse($template, 'SMS template created successfully');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/sms-templates/{id}",
     *     tags={"SMS Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Show a SMS template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Sms template"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\SmsTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->find($id);

        if (!$template) {
            return $this->notFoundResponse('SMS template');
        }

        return $this->successResponse($template);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/sms-templates/{id}",
     *     tags={"SMS Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Update a SMS template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object", description="Fields to change, as for creation")),
     *     @OA\Response(response=200, description="Updated"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, $id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\SmsTemplate::class, $id)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'message' => 'sometimes|string|max:1530',
            'variables' => 'nullable|array',
            'sample_data' => 'nullable|array',
            'category' => 'sometimes|in:marketing,transactional,otp,notification',
            'status' => 'nullable|in:draft,active,archived',
            'is_active' => 'nullable|boolean',
            'sender_id' => 'nullable|string|max:11',
            'cost_per_message' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $template = $this->templateRepository->update($id, $request->all());

        if (!$template) {
            return $this->notFoundResponse('SMS template');
        }

        return $this->updatedResponse($template, 'SMS template updated successfully');
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/sms-templates/{id}",
     *     tags={"SMS Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Delete a SMS template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Deleted"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function destroy($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\SmsTemplate::class, $id)) {
            return $deny;
        }

        $deleted = $this->templateRepository->delete($id);

        if (!$deleted) {
            return $this->notFoundResponse('SMS template');
        }

        return $this->deletedResponse('SMS template deleted successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/sms-templates/{id}/activate",
     *     tags={"SMS Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Activate a SMS template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Activated"),
     *     @OA\Response(response=400, description="Cannot be activated in its current state"),
     *     @OA\Response(response=403, description="Application outside the user's scope")
     * )
     */
    public function activate($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\SmsTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->activate($id);

        if (!$template) {
            return $this->notFoundResponse('SMS template');
        }

        return $this->updatedResponse($template, 'SMS template activated successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/sms-templates/{id}/deactivate",
     *     tags={"SMS Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Deactivate a SMS template",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Deactivated"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function deactivate($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\SmsTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->deactivate($id);

        if (!$template) {
            return $this->notFoundResponse('SMS template');
        }

        return $this->updatedResponse($template, 'SMS template deactivated successfully');
    }
}

