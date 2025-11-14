<?php

namespace App\Http\Controllers\Api\V1;

use App\Repositories\Contracts\MessageRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MessageController extends BaseController
{
    protected $messageRepository;

    public function __construct(MessageRepositoryInterface $messageRepository)
    {
        $this->messageRepository = $messageRepository;
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $businessId = $request->get('business_id');
        $channel = $request->get('channel');
        $status = $request->get('status');

        if ($businessId) {
            $messages = $this->messageRepository->getByBusiness($businessId, $perPage);
        } elseif ($channel) {
            $messages = $this->messageRepository->getByChannel($channel, $perPage);
        } elseif ($status) {
            $messages = $this->messageRepository->getByStatus($status, $perPage);
        } else {
            $messages = $this->messageRepository->all($perPage);
        }

        return $this->successResponse($messages, 'Messages retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'message_id' => 'required|string|unique:messages,message_id',
            'channel' => 'required|in:email,sms,whatsapp',
            'type' => 'required|in:text,template,media,interactive',
            'recipient' => 'required|string',
            'recipient_name' => 'nullable|string',
            'sender' => 'nullable|string',
            'subject' => 'nullable|string',
            'content' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $message = $this->messageRepository->create($validator->validated());

        return $this->successResponse($message, 'Message created successfully');
    }

    public function show(int $id): JsonResponse
    {
        $message = $this->messageRepository->find($id);

        if (!$message) {
            return $this->errorResponse('Message not found', 404);
        }

        return $this->successResponse($message, 'Message retrieved successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:pending,queued,sending,sent,delivered,read,failed,cancelled',
            'error_message' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $message = $this->messageRepository->update($id, $validator->validated());

        if (!$message) {
            return $this->errorResponse('Message not found', 404);
        }

        return $this->successResponse($message, 'Message updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->messageRepository->delete($id);

        if (!$deleted) {
            return $this->errorResponse('Message not found', 404);
        }

        return $this->successResponse(null, 'Message deleted successfully');
    }
}

