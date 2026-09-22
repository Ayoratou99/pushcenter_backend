<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One action of a console user. Written once, never updated.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'business_id',
        'business_name',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'description',
        'properties',
        'method',
        'route',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * What $viewer may see. Admins and global managers: everything. A manager
     * restricted to some applications: what happened on those applications,
     * plus the actions not tied to an application (sign-ins, account and user
     * changes) done by or on the users of those applications.
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        $businessIds = $viewer->accessibleBusinessIds();

        if ($businessIds === null) {
            return $query;
        }

        $userIds = DB::table('business_user')->whereIn('business_id', $businessIds)->pluck('user_id')->push($viewer->getKey())->unique()->all();

        return $query->where(function (Builder $q) use ($businessIds, $userIds) {
            $q->whereIn('business_id', $businessIds)
                ->orWhere(function (Builder $q) use ($userIds) {
                    $q->whereNull('business_id')
                        ->where(function (Builder $q) use ($userIds) {
                            $q->whereIn('user_id', $userIds)
                                ->orWhere(fn (Builder $q) => $q->where('subject_type', 'user')->whereIn('subject_id', $userIds));
                        });
                });
        });
    }
}
