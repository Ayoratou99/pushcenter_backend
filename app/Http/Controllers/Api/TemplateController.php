<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Contracts\TemplateRepositoryInterface;
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
     * Display a listing of templates.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);

        // Search
        if ($request->has('search')) {
            $templates = $this->templateRepository->search($request->search, $perPage);
            return $this->successResponse($templates);
        }

        // Filter by business
        if ($request->has('business_id')) {
            $templates = $this->templateRepository->getByBusiness($request->business_id, $perPage);
            return $this->successResponse($templates);
        }

        // Filter by type
        if ($request->has('type')) {
            $templates = $this->templateRepository->getByType($request->type, $perPage);
            return $this->successResponse($templates);
        }

        // Filter by status
        if ($request->has('status')) {
            $templates = $this->templateRepository->getByStatus($request->status, $perPage);
            return $this->successResponse($templates);
        }

        // Filter by category
        if ($request->has('category')) {
            $templates = $this->templateRepository->getByCategory($request->category, $perPage);
            return $this->successResponse($templates);
        }

        // Get all
        $templates = $this->templateRepository->all($perPage);
        return $this->successResponse($templates);
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

        $template = $this->templateRepository->create($request->all());

        return $this->createdResponse($template, 'Template created successfully');
    }

    /**
     * Display the specified template.
     */
    public function show($id): JsonResponse
    {
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
        $template = $this->templateRepository->deactivate($id);

        if (!$template) {
            return $this->notFoundResponse('Template');
        }

        return $this->updatedResponse($template, 'Template deactivated successfully');
    }
}
