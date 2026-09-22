<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendMessagesJob;
use App\Repositories\Contracts\MessageRepositoryInterface;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
            ->with(['business:id,name', 'emailMessage', 'smsMessage', 'whatsappMessage', 'telegramMessage']);

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
                $q->orWhereHas('whatsappMessage', fn ($r) => $r->where('whatsapp_template_id', $templateId))
                    ->orWhereHas('telegramMessage', fn ($r) => $r->where('telegram_template_id', $templateId));
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
                    ->orWhereHas('whatsappMessage', fn ($r) => $r->where('recipient_number', 'like', "%{$recipient}%"))
                    ->orWhereHas('telegramMessage', fn ($r) => $r->where('recipient_label', 'like', "%{$recipient}%")
                        ->orWhere('external_ref', 'like', "%{$recipient}%"));
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
     * @OA\Get(
     *     path="/api/v1/messages/{id}",
     *     tags={"Messages"},
     *     security={{"bearerAuth":{}}},
     *     summary="Show a message with its channel details",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Message"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
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
     * @OA\Delete(
     *     path="/api/v1/messages/{id}",
     *     tags={"Messages"},
     *     security={{"bearerAuth":{}}},
     *     summary="Delete a message",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Deleted"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
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
     * @OA\Post(
     *     path="/api/v1/messages/{id}/retry",
     *     tags={"Messages"},
     *     security={{"bearerAuth":{}}},
     *     summary="Send a failed message again",
     *     description="Queues the failed message for a new delivery attempt (SendMessagesJob): email through the application's SMTP settings, WhatsApp through AyosPush, Telegram through the application's bot. SMS is not delivered yet and answers 501. A WhatsApp message that failed without a clear answer from AyosPush may already have been received: check before retrying it.",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Queued again"),
     *     @OA\Response(response=400, description="The message has not failed"),
     *     @OA\Response(response=404, description="Message not found"),
     *     @OA\Response(response=501, description="Channel not implemented (SMS)")
     * )
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

        if (! in_array($message->message_type, ['email', 'whatsapp', 'telegram'], true)) {
            return $this->errorResponse(
                "Sending {$message->message_type} messages is not implemented yet: only email, WhatsApp and Telegram can be retried.",
                null,
                501
            );
        }

        // A new WhatsApp attempt is a new AyosPush request.
        $message->whatsappMessage?->forceFill([
            'provider_request_id' => null,
            'provider_status' => null,
            'provider_message_id' => null,
            'status_checks' => 0,
            'provider_checked_at' => null,
        ])->save();

        $message = $this->messageRepository->update($id, [
            'status' => 'queued',
            'retry_count' => $message->retry_count + 1,
            'error_message' => null,
            'failed_at' => null,
        ]);

        SendMessagesJob::dispatchFor($message);

        return $this->successResponse($message, 'Message queued again for delivery');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/messages/{id}/cancel",
     *     tags={"Messages"},
     *     security={{"bearerAuth":{}}},
     *     summary="Cancel a pending or queued message",
     *     description="A cancelled message is skipped when its job runs.",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Cancelled"),
     *     @OA\Response(response=400, description="Only pending or queued messages can be cancelled"),
     *     @OA\Response(response=403, description="Application outside the user's scope"),
     *     @OA\Response(response=404, description="Not found")
     * )
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
     * @OA\Get(
     *     path="/api/v1/messages/stats",
     *     tags={"Messages"},
     *     security={{"bearerAuth":{}}},
     *     summary="Message counts by status and channel",
     *     @OA\Parameter(name="business_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Statistics")
     * )
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
