<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Contracts\MessageRepositoryInterface;
use App\Models\WhatsAppMessage;
use App\Models\SmsMessage;
use App\Models\EmailMessage;
use App\Support\QueryFilters;
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
     * @OA\Get(
     *     path="/api/v1/messages",
     *     tags={"Messages"},
     *     security={{"bearerAuth":{}}},
     *     summary="List sent messages",
     *     description="Every filter below can be combined.",
     *     @OA\Parameter(name="search", in="query", description="Message id, external id, campaign or recipient", @OA\Schema(type="string")),
     *     @OA\Parameter(name="business_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="message_type", in="query", @OA\Schema(type="string", enum={"email","sms","whatsapp"})),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"pending","queued","sending","sent","delivered","read","failed","cancelled"})),
     *     @OA\Parameter(name="status_in", in="query", description="Comma separated statuses", @OA\Schema(type="string")),
     *     @OA\Parameter(name="template_id", in="query", description="Template used to build the message", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="is_template", in="query", description="Only template-based (1) or free form (0) messages", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="recipient", in="query", description="Email address or phone number", @OA\Schema(type="string")),
     *     @OA\Parameter(name="campaign_id", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="has_error", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="start_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="sent_from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="sent_to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="min_cost", in="query", @OA\Schema(type="number")),
     *     @OA\Parameter(name="max_cost", in="query", @OA\Schema(type="number")),
     *     @OA\Parameter(name="sort_by", in="query", @OA\Schema(type="string", enum={"created_at","sent_at","delivered_at","status","message_type","cost"})),
     *     @OA\Parameter(name="sort_dir", in="query", @OA\Schema(type="string", enum={"asc","desc"})),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Paginated messages")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->messageRepository->newQuery()
            ->with(['business:id,name', 'emailMessage', 'smsMessage', 'whatsappMessage']);

        QueryFilters::restrictToUserBusinesses($query, $request);
        QueryFilters::exact($query, $request, ['business_id', 'message_type', 'status', 'campaign_id', 'external_id', 'currency']);
        QueryFilters::inList($query, $request, ['status', 'message_type', 'business_id']);
        QueryFilters::search($query, $request->input('search'), [
            'message_id', 'external_id', 'campaign_id', 'business.name',
        ]);
        QueryFilters::dateRange($query, $request, 'created_at');
        QueryFilters::dateRange($query, $request, 'sent_at', 'sent', acceptGenericAliases: false);
        QueryFilters::numericRange($query, $request, 'cost');
        QueryFilters::numericRange($query, $request, 'retry_count');

        if ($request->filled('has_error')) {
            $request->boolean('has_error')
                ? $query->whereNotNull('error_message')
                : $query->whereNull('error_message');
        }

        // Filter by the template that produced the message, on any channel.
        if ($request->filled('template_id')) {
            $templateId = $request->input('template_id');

            $query->where(function ($q) use ($templateId) {
                foreach (['emailMessage', 'smsMessage', 'whatsappMessage'] as $relation) {
                    $q->orWhereHas($relation, fn ($r) => $r->where('template_id', $templateId));
                }
            });
        }

        if ($request->filled('is_template')) {
            $isTemplate = $request->boolean('is_template');

            $query->where(function ($q) use ($isTemplate) {
                foreach (['emailMessage', 'smsMessage', 'whatsappMessage'] as $relation) {
                    $q->orWhereHas($relation, fn ($r) => $r->where('is_template', $isTemplate));
                }
            });
        }

        // Recipient lives on the per-channel table.
        if ($request->filled('recipient')) {
            $recipient = $request->input('recipient');

            $query->where(function ($q) use ($recipient) {
                $q->whereHas('emailMessage', fn ($r) => $r->where('recipient_email', 'like', "%{$recipient}%"))
                    ->orWhereHas('smsMessage', fn ($r) => $r->where('recipient_number', 'like', "%{$recipient}%"))
                    ->orWhereHas('whatsappMessage', fn ($r) => $r->where('recipient_number', 'like', "%{$recipient}%"));
            });
        }

        if ($request->filled('subject')) {
            $subject = $request->input('subject');
            $query->whereHas('emailMessage', fn ($r) => $r->where('subject', 'like', "%{$subject}%"));
        }

        QueryFilters::sort($query, $request, [
            'created_at', 'sent_at', 'delivered_at', 'failed_at', 'status', 'message_type', 'cost', 'retry_count',
        ]);

        return $this->successResponse($query->paginate(QueryFilters::perPage($request)));
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

        if ($deny = $this->denyUnlessBusinessAccessible($request->input('business_id'))) {
            return $deny;
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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Message::class, $id)) {
            return $deny;
        }

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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Message::class, $id)) {
            return $deny;
        }

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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Message::class, $id)) {
            return $deny;
        }

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

        if ($deny = $this->denyUnlessBusinessAccessible($request->input('business_id'))) {
            return $deny;
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

        if ($deny = $this->denyUnlessBusinessAccessible($request->input('business_id'))) {
            return $deny;
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

        if ($deny = $this->denyUnlessBusinessAccessible($request->input('business_id'))) {
            return $deny;
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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Message::class, $id)) {
            return $deny;
        }

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
        if ($deny = $this->denyUnlessRecordAccessible(\App\Models\Message::class, $id)) {
            return $deny;
        }

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
