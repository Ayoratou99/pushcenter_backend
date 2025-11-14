<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Contracts\SmsTemplateRepositoryInterface;
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
     * Display a listing of SMS templates.
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

        // Get active only
        if ($request->boolean('active_only')) {
            $templates = $this->templateRepository->getActive($perPage);
            return $this->successResponse($templates);
        }

        // Get all
        $templates = $this->templateRepository->all($perPage);
        return $this->successResponse($templates);
    }

    /**
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

        $template = $this->templateRepository->create($request->all());

        return $this->createdResponse($template, 'SMS template created successfully');
    }

    /**
     * Display the specified SMS template.
     */
    public function show($id): JsonResponse
    {
        $template = $this->templateRepository->find($id);

        if (!$template) {
            return $this->notFoundResponse('SMS template');
        }

        return $this->successResponse($template);
    }

    /**
     * Update the specified SMS template.
     */
    public function update(Request $request, $id): JsonResponse
    {
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
     * Remove the specified SMS template.
     */
    public function destroy($id): JsonResponse
    {
        $deleted = $this->templateRepository->delete($id);

        if (!$deleted) {
            return $this->notFoundResponse('SMS template');
        }

        return $this->deletedResponse('SMS template deleted successfully');
    }

    /**
     * Activate an SMS template.
     */
    public function activate($id): JsonResponse
    {
        $template = $this->templateRepository->activate($id);

        if (!$template) {
            return $this->notFoundResponse('SMS template');
        }

        return $this->updatedResponse($template, 'SMS template activated successfully');
    }

    /**
     * Deactivate an SMS template.
     */
    public function deactivate($id): JsonResponse
    {
        $template = $this->templateRepository->deactivate($id);

        if (!$template) {
            return $this->notFoundResponse('SMS template');
        }

        return $this->updatedResponse($template, 'SMS template deactivated successfully');
    }
}

