<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Contracts\MessageRepositoryInterface;
use App\Models\WhatsAppMessage;
use App\Models\SmsMessage;
use App\Models\EmailMessage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MessageController extends BaseController
{
    protected $messageRepository;

    public function __construct(MessageRepositoryInterface $messageRepository)
    {
        $this->messageRepository = $messageRepository;
    }

    /**
     * Display a listing of messages.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);

        // Filter by business
        if ($request->has('business_id')) {
            $messages = $this->messageRepository->getByBusiness($request->business_id, $perPage);
            return $this->successResponse($messages);
        }

        // Filter by channel
        if ($request->has('message_type')) {
            $messages = $this->messageRepository->getByChannel($request->message_type, $perPage);
            return $this->successResponse($messages);
        }

        // Filter by status
        if ($request->has('status')) {
            $messages = $this->messageRepository->getByStatus($request->status, $perPage);
            return $this->successResponse($messages);
        }

        // Get all
        $messages = $this->messageRepository->all($perPage);
        return $this->successResponse($messages);
    }

    /**
     * Store a newly created message.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'message_type' => 'required|in:email,sms,whatsapp',
            'status' => 'nullable|in:pending,queued,sending,sent,delivered,read,failed,cancelled',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $messageData = $request->all();
        $messageData['message_id'] = Str::uuid();
        
        $message = $this->messageRepository->create($messageData);

        return $this->createdResponse($message, 'Message created successfully');
    }

    /**
     * Display the specified message.
     */
    public function show($id): JsonResponse
    {
        $message = $this->messageRepository->find($id);

        if (!$message) {
            return $this->notFoundResponse('Message');
        }

        return $this->successResponse($message);
    }

    /**
     * Update the specified message.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $message = $this->messageRepository->update($id, $request->all());

        if (!$message) {
            return $this->notFoundResponse('Message');
        }

        return $this->updatedResponse($message, 'Message updated successfully');
    }

    /**
     * Remove the specified message.
     */
    public function destroy($id): JsonResponse
    {
        $deleted = $this->messageRepository->delete($id);

        if (!$deleted) {
            return $this->notFoundResponse('Message');
        }

        return $this->deletedResponse('Message deleted successfully');
    }

    /**
     * Send WhatsApp message.
     */
    public function sendWhatsApp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'whatsapp_phone_number_id' => 'required',
            'recipient_number' => 'required|string',
            'content' => 'required_without:template_id|string',
            'template_id' => 'required_without:content|exists:templates,id',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        // Create main message
        $message = $this->messageRepository->create([
            'business_id' => $request->business_id,
            'message_id' => Str::uuid(),
            'message_type' => 'whatsapp',
            'status' => 'pending',
        ]);

        // Create WhatsApp specific message
        WhatsAppMessage::create([
            'message_id' => $message->id,
            'template_id' => $request->template_id,
            'is_template' => !empty($request->template_id),
            'whatsapp_phone_number_id' => $request->whatsapp_phone_number_id,
            'recipient_number' => $request->recipient_number,
            'recipient_name' => $request->recipient_name,
            'content' => $request->content,
            'media_url' => $request->media_url,
            'button_url' => $request->button_url,
            'button_text' => $request->button_text,
            'template_variables' => $request->template_variables,
            'metadata' => $request->metadata,
        ]);

        $message->load('whatsappMessage');

        return $this->createdResponse($message, 'WhatsApp message queued successfully');
    }

    /**
     * Send SMS message.
     */
    public function sendSms(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'sms_phone_number_id' => 'required',
            'recipient_number' => 'required|string',
            'content' => 'required_without:template_id|string',
            'template_id' => 'required_without:content|exists:templates,id',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        // Create main message
        $message = $this->messageRepository->create([
            'business_id' => $request->business_id,
            'message_id' => Str::uuid(),
            'message_type' => 'sms',
            'status' => 'pending',
        ]);

        // Create SMS specific message
        SmsMessage::create([
            'message_id' => $message->id,
            'template_id' => $request->template_id,
            'is_template' => !empty($request->template_id),
            'sms_phone_number_id' => $request->sms_phone_number_id,
            'recipient_number' => $request->recipient_number,
            'recipient_name' => $request->recipient_name,
            'content' => $request->content,
            'template_variables' => $request->template_variables,
            'message_count' => $request->message_count ?? 1,
        ]);

        $message->load('smsMessage');

        return $this->createdResponse($message, 'SMS message queued successfully');
    }

    /**
     * Send Email message.
     */
    public function sendEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|exists:businesses,id',
            'recipient_email' => 'required|email',
            'subject' => 'required|string',
            'content' => 'required_without:template_id|string',
            'template_id' => 'required_without:content|exists:templates,id',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        // Create main message
        $message = $this->messageRepository->create([
            'business_id' => $request->business_id,
            'message_id' => Str::uuid(),
            'message_type' => 'email',
            'status' => 'pending',
        ]);

        // Create Email specific message
        EmailMessage::create([
            'message_id' => $message->id,
            'template_id' => $request->template_id,
            'is_template' => !empty($request->template_id),
            'recipient_email' => $request->recipient_email,
            'recipient_name' => $request->recipient_name,
            'sender_email' => $request->sender_email,
            'sender_name' => $request->sender_name,
            'subject' => $request->subject,
            'content' => $request->content,
            'template_variables' => $request->template_variables,
            'attachments' => $request->attachments,
            'cc' => $request->cc,
            'bcc' => $request->bcc,
        ]);

        $message->load('emailMessage');

        return $this->createdResponse($message, 'Email message queued successfully');
    }

    /**
     * Retry a failed message.
     */
    public function retry($id): JsonResponse
    {
        $message = $this->messageRepository->find($id);

        if (!$message) {
            return $this->notFoundResponse('Message');
        }

        if ($message->status !== 'failed') {
            return $this->errorResponse('Only failed messages can be retried', null, 400);
        }

        $message = $this->messageRepository->update($id, [
            'status' => 'pending',
            'retry_count' => $message->retry_count + 1,
            'error_message' => null,
        ]);

        return $this->successResponse($message, 'Message retry queued successfully');
    }

    /**
     * Cancel a pending message.
     */
    public function cancel($id): JsonResponse
    {
        $message = $this->messageRepository->find($id);

        if (!$message) {
            return $this->notFoundResponse('Message');
        }

        if (!in_array($message->status, ['pending', 'queued'])) {
            return $this->errorResponse('Only pending or queued messages can be cancelled', null, 400);
        }

        $message = $this->messageRepository->update($id, [
            'status' => 'cancelled',
        ]);

        return $this->successResponse($message, 'Message cancelled successfully');
    }

    /**
     * Get message statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $businessId = $request->get('business_id');
        $perPage = null; // Get all for stats

        $query = $businessId 
            ? $this->messageRepository->getByBusiness($businessId, $perPage)
            : $this->messageRepository->all($perPage);

        $messages = is_object($query) && method_exists($query, 'toArray') ? $query : collect($query);

        $stats = [
            'total' => $messages->count(),
            'by_type' => [
                'email' => $messages->where('message_type', 'email')->count(),
                'sms' => $messages->where('message_type', 'sms')->count(),
                'whatsapp' => $messages->where('message_type', 'whatsapp')->count(),
            ],
            'by_status' => [
                'pending' => $messages->where('status', 'pending')->count(),
                'sent' => $messages->where('status', 'sent')->count(),
                'delivered' => $messages->where('status', 'delivered')->count(),
                'failed' => $messages->where('status', 'failed')->count(),
            ],
        ];

        return $this->successResponse($stats);
    }
}
