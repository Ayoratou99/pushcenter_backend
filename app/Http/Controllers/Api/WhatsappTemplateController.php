<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Contracts\WhatsappTemplateRepositoryInterface;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class WhatsappTemplateController extends BaseController
{
    protected $templateRepository;

    public function __construct(WhatsappTemplateRepositoryInterface $templateRepository)
    {
        $this->templateRepository = $templateRepository;
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
        QueryFilters::exact($query, $request, ['business_id', 'status', 'category', 'language', 'facebook_status']);
        QueryFilters::inList($query, $request, ['status', 'category', 'language', 'business_id']);
        QueryFilters::booleans($query, $request, ['is_active']);
        QueryFilters::search($query, $request->input('search'), [
            'name', 'display_name', 'description', 'body', 'business.name',
        ]);
        QueryFilters::dateRange($query, $request, 'created_at');
        QueryFilters::numericRange($query, $request, 'usage_count');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true)->where('status', 'approved');
        }

        QueryFilters::sort($query, $request, [
            'name', 'display_name', 'status', 'category', 'language', 'usage_count',
            'last_used_at', 'approved_at', 'created_at', 'updated_at',
        ]);

        return $this->successResponse($query->paginate(QueryFilters::perPage($request)));
    }

    /**
     * @OA\Post(
     *     path="/api/v1/whatsapp-templates",
     *     tags={"WhatsApp Templates"},
     *     security={{"bearerAuth":{}}},
     *     summary="Create a new WhatsApp template",
     *     description="Creates a new WhatsApp template with header, body, footer, and buttons",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"business_id", "name", "display_name", "language", "category"},
     *             @OA\Property(property="business_id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="order_confirmation"),
     *             @OA\Property(property="display_name", type="string", example="Order Confirmation"),
     *             @OA\Property(property="description", type="string", example="WhatsApp template for order confirmations"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="category", type="string", enum={"marketing", "transactional", "notification"}, example="transactional"),
     *             @OA\Property(property="header_type", type="string", enum={"text", "image", "video", "document"}, example="text"),
     *             @OA\Property(property="header_content", type="string", example="Order #{{order_id}}"),
     *             @OA\Property(property="body", type="string", example="Your order has been confirmed. Total: {{amount}}"),
     *             @OA\Property(property="footer", type="string", example="Thank you for shopping with us!"),
     *             @OA\Property(property="buttons", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="variables", type="array", @OA\Items(type="string"), example={"order_id", "amount"}),
     *             @OA\Property(property="sample_data", type="object", example={"order_id": "12345", "amount": "$99.99"}),
     *             @OA\Property(property="status", type="string", enum={"draft", "active", "archived"}, example="draft"),
     *             @OA\Property(property="approval_status", type="string", enum={"pending", "approved", "rejected"}, example="pending"),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="metadata", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="WhatsApp template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="WhatsApp template created successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     *
     * Store a newly created WhatsApp template.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'language' => 'required|string|max:10',
            'category' => 'required|string|in:MARKETING,UTILITY,AUTHENTICATION',
            'header' => 'nullable|array',
            'body' => 'required|string',
            'footer' => 'nullable|array',
            'buttons' => 'nullable|array',
            'components' => 'nullable|array',
            'variables' => 'nullable|array',
            'sample_data' => 'nullable|array',
            'status' => 'nullable|in:draft,pending,approved,rejected,disabled',
            'is_active' => 'nullable|boolean',
            'allow_variables' => 'nullable|boolean',
            'max_variables' => 'nullable|integer|min:0|max:20',
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

        return $this->createdResponse($template, 'WhatsApp template created successfully');
    }

    /**
     * Display the specified WhatsApp template.
     */
    public function show($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->find($id);

        if (!$template) {
            return $this->notFoundResponse('WhatsApp template');
        }

        return $this->successResponse($template);
    }

    /**
     * Update the specified WhatsApp template.
     */
    public function update(Request $request, $id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'display_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'language' => 'sometimes|string|max:10',
            'category' => 'sometimes|string|in:MARKETING,UTILITY,AUTHENTICATION',
            'header' => 'nullable|array',
            'body' => 'sometimes|string',
            'footer' => 'nullable|array',
            'buttons' => 'nullable|array',
            'components' => 'nullable|array',
            'variables' => 'nullable|array',
            'sample_data' => 'nullable|array',
            'status' => 'nullable|in:draft,pending,approved,rejected,disabled',
            'is_active' => 'nullable|boolean',
            'allow_variables' => 'nullable|boolean',
            'max_variables' => 'nullable|integer|min:0|max:20',
            'cost_per_message' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $template = $this->templateRepository->update($id, $request->all());

        if (!$template) {
            return $this->notFoundResponse('WhatsApp template');
        }

        return $this->updatedResponse($template, 'WhatsApp template updated successfully');
    }

    /**
     * Remove the specified WhatsApp template.
     */
    public function destroy($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $deleted = $this->templateRepository->delete($id);

        if (!$deleted) {
            return $this->notFoundResponse('WhatsApp template');
        }

        return $this->deletedResponse('WhatsApp template deleted successfully');
    }

    /**
     * Activate a WhatsApp template.
     */
    public function activate($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->activate($id);

        if (!$template) {
            return $this->errorResponse('WhatsApp template not found or not approved yet');
        }

        return $this->updatedResponse($template, 'WhatsApp template activated successfully');
    }

    /**
     * Deactivate a WhatsApp template.
     */
    public function deactivate($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->deactivate($id);

        if (!$template) {
            return $this->notFoundResponse('WhatsApp template');
        }

        return $this->updatedResponse($template, 'WhatsApp template deactivated successfully');
    }

    /**
     * Submit template for approval to Meta.
     */
    public function submitForApproval($id): JsonResponse
    {
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\WhatsappTemplate::class, $id)) {
            return $deny;
        }

        $template = $this->templateRepository->submitForApproval($id);

        if (!$template) {
            return $this->notFoundResponse('WhatsApp template');
        }

        return $this->updatedResponse($template, 'WhatsApp template submitted for approval');
    }
}

