<?php

namespace App\Http\Controllers\Api;

use App\Models\Message;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Dashboard",
 *     description="API Endpoints for dashboard statistics and analytics"
 * )
 */
class DashboardController extends BaseController
{
    /**
     * @OA\Get(
     *     path="/api/v1/dashboard/stats",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     summary="Get dashboard statistics",
     *     description="Returns overall statistics for messages, businesses, and costs",
     *     @OA\Parameter(
     *         name="business_id",
     *         in="query",
     *         description="Filter by business ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Start date for filtering (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="End date for filtering (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Operation successful"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="total_messages", type="integer"),
     *                 @OA\Property(property="sent_messages", type="integer"),
     *                 @OA\Property(property="delivered_messages", type="integer"),
     *                 @OA\Property(property="failed_messages", type="integer"),
     *                 @OA\Property(property="pending_messages", type="integer"),
     *                 @OA\Property(property="total_cost", type="number", format="float"),
     *                 @OA\Property(property="messages_by_type", type="object"),
     *                 @OA\Property(property="messages_by_status", type="object")
     *             )
     *         )
     *     )
     * )
     */
    public function stats(Request $request): JsonResponse
    {
        $query = Message::query();

        // Filter by business if provided
        if ($request->has('business_id')) {
            $query->where('business_id', $request->business_id);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('created_at', '<=', $request->end_date);
        }

        // Calculate statistics
        $stats = [
            'total_messages' => $query->count(),
            'sent_messages' => (clone $query)->where('status', 'sent')->count(),
            'delivered_messages' => (clone $query)->where('status', 'delivered')->count(),
            'failed_messages' => (clone $query)->where('status', 'failed')->count(),
            'pending_messages' => (clone $query)->where('status', 'pending')->count(),
            'total_cost' => (clone $query)->sum('cost'),
            'credits_remaining' => 0, // This should be calculated based on business credits
            'messages_by_type' => [
                'email' => (clone $query)->where('message_type', 'email')->count(),
                'sms' => (clone $query)->where('message_type', 'sms')->count(),
                'whatsapp' => (clone $query)->where('message_type', 'whatsapp')->count(),
            ],
            'messages_by_status' => [
                'pending' => (clone $query)->where('status', 'pending')->count(),
                'sent' => (clone $query)->where('status', 'sent')->count(),
                'delivered' => (clone $query)->where('status', 'delivered')->count(),
                'failed' => (clone $query)->where('status', 'failed')->count(),
            ],
        ];

        return $this->successResponse($stats);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboard/recent-messages",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     summary="Get recent messages",
     *     description="Returns a list of the most recent messages",
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of messages to return",
     *         required=false,
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Parameter(
     *         name="business_id",
     *         in="query",
     *         description="Filter by business ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Operation successful"),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Message"))
     *         )
     *     )
     * )
     */
    public function recentMessages(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);
        
        $query = Message::with('business');

        if ($request->has('business_id')) {
            $query->where('business_id', $request->business_id);
        }

        $messages = $query->latest()->limit($limit)->get();

        return $this->successResponse($messages);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboard/message-trends",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     summary="Get message trends",
     *     description="Returns message trends over time grouped by type",
     *     @OA\Parameter(
     *         name="period",
     *         in="query",
     *         description="Time period for trends",
     *         required=false,
     *         @OA\Schema(type="string", enum={"week", "month", "year"}, default="week")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Operation successful"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="date", type="string"),
     *                     @OA\Property(property="message_type", type="string"),
     *                     @OA\Property(property="count", type="integer")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function messageTrends(Request $request): JsonResponse
    {
        $period = $request->get('period', 'week'); // week, month, year

        // PostgreSQL date format patterns
        $dateFormat = match ($period) {
            'week' => 'YYYY-MM-DD',
            'month' => 'YYYY-MM-DD',
            'year' => 'YYYY-MM',
            default => 'YYYY-MM-DD',
        };

        $daysBack = match ($period) {
            'week' => 7,
            'month' => 30,
            'year' => 365,
            default => 7,
        };

        $trends = Message::select(
            DB::raw("TO_CHAR(created_at, '{$dateFormat}') as date"),
            'message_type',
            DB::raw('COUNT(*) as count')
        )
        ->where('created_at', '>=', now()->subDays($daysBack))
        ->groupBy('date', 'message_type')
        ->orderBy('date')
        ->get();

        return $this->successResponse($trends);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/dashboard/cost-analysis",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     summary="Get cost analysis",
     *     description="Returns cost breakdown by type and date",
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Start date for filtering (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="End date for filtering (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Operation successful"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="by_type", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="by_date", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="total_cost", type="number", format="float")
     *             )
     *         )
     *     )
     * )
     */
    public function costAnalysis(Request $request): JsonResponse
    {
        $query = Message::query();

        if ($request->has('start_date')) {
            $query->where('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('created_at', '<=', $request->end_date);
        }

        $costByType = Message::select('message_type', DB::raw('SUM(cost) as total_cost'))
            ->groupBy('message_type')
            ->get();

        $costByDate = Message::select(
            DB::raw("DATE(created_at) as date"),
            DB::raw('SUM(cost) as total_cost')
        )
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        $data = [
            'by_type' => $costByType,
            'by_date' => $costByDate,
            'total_cost' => $query->sum('cost'),
        ];

        return $this->successResponse($data);
    }
}
