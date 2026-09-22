<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Support\ActivityCatalog;
use App\Support\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Activity", description="Audit trail of what console users did")
 */
class ActivityController extends BaseController
{
    /**
     * @OA\Get(
     *     path="/api/v1/activities",
     *     tags={"Activity"},
     *     security={{"bearerAuth":{}}},
     *     summary="What console users did",
     *     description="Newest first. Admins and global managers see everything; a manager restricted to some applications sees what happened on them, plus the sign-ins, account and user changes of their users. Every filter can be combined.",
     *     @OA\Parameter(name="search", in="query", description="Description, user, application or subject", @OA\Schema(type="string")),
     *     @OA\Parameter(name="user_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="business_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="action", in="query", description="Exact action, e.g. whatsapp_template.submitted", @OA\Schema(type="string")),
     *     @OA\Parameter(name="action_group", in="query", description="Action prefix, e.g. whatsapp_template", @OA\Schema(type="string")),
     *     @OA\Parameter(name="subject_type", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="subject_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="ip_address", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="start_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Paginated activity")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::query()->visibleTo($request->user());

        QueryFilters::exact($query, $request, ['user_id', 'business_id', 'action', 'subject_type', 'subject_id', 'ip_address']);
        QueryFilters::search($query, $request->input('search'), [
            'description', 'user_name', 'user_email', 'business_name', 'subject_label',
        ]);
        QueryFilters::dateRange($query, $request, 'created_at');

        if ($request->filled('action_group')) {
            $query->where('action', 'like', rtrim($request->input('action_group'), '.') . '.%');
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        return $this->successResponse($query->paginate(QueryFilters::perPage($request)));
    }

    /**
     * @OA\Get(
     *     path="/api/v1/activities/actions",
     *     tags={"Activity"},
     *     security={{"bearerAuth":{}}},
     *     summary="Every action the activity log can contain",
     *     @OA\Response(response=200, description="Action names, grouped by what they concern")
     * )
     */
    public function actions(): JsonResponse
    {
        $actions = ActivityCatalog::actions();

        return $this->successResponse([
            'actions' => $actions,
            'groups' => collect($actions)->map(fn (string $action) => strtok($action, '.'))->unique()->values(),
        ]);
    }
}
