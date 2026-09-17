<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Small composable helpers used by the API list endpoints so that every filter
 * can be combined instead of being mutually exclusive.
 */
class QueryFilters
{
    /**
     * Apply `field=value` filters for every column present in the request.
     *
     * @param  array<int, string>  $columns
     */
    public static function exact(Builder $query, Request $request, array $columns): Builder
    {
        foreach ($columns as $column) {
            if ($request->filled($column)) {
                $value = $request->query($column, $request->input($column));

                is_array($value)
                    ? $query->whereIn($column, $value)
                    : $query->where($column, $value);
            }
        }

        return $query;
    }

    /**
     * Apply `field_in=a,b,c` style multi-value filters.
     *
     * @param  array<int, string>  $columns
     */
    public static function inList(Builder $query, Request $request, array $columns): Builder
    {
        foreach ($columns as $column) {
            $key = $column . '_in';

            if ($request->filled($key)) {
                $values = $request->input($key);
                $values = is_array($values) ? $values : array_filter(explode(',', (string) $values));

                if ($values) {
                    $query->whereIn($column, $values);
                }
            }
        }

        return $query;
    }

    /**
     * Free text search across several columns.
     *
     * @param  array<int, string>  $columns
     */
    public static function search(Builder $query, ?string $term, array $columns): Builder
    {
        $term = trim((string) $term);

        if ($term === '' || $columns === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term, $columns) {
            foreach ($columns as $index => $column) {
                // Nested "relation.column" searches go through whereHas.
                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);

                    $index === 0
                        ? $q->whereHas($relation, fn ($r) => $r->where($field, 'like', "%{$term}%"))
                        : $q->orWhereHas($relation, fn ($r) => $r->where($field, 'like', "%{$term}%"));

                    continue;
                }

                $index === 0
                    ? $q->where($column, 'like', "%{$term}%")
                    : $q->orWhere($column, 'like', "%{$term}%");
            }
        });
    }

    /**
     * Date range on a timestamp column, driven by `<prefix>_from` / `<prefix>_to`.
     *
     * `$acceptGenericAliases` also honours the UI-wide `start_date` / `end_date`
     * parameters. Only one column per query should opt in, otherwise a list with
     * several date columns would be filtered on all of them at once.
     */
    public static function dateRange(
        Builder $query,
        Request $request,
        string $column,
        ?string $prefix = null,
        bool $acceptGenericAliases = true,
    ): Builder {
        $prefix ??= $column === 'created_at' ? 'created' : $column;

        if ($request->filled($prefix . '_from')) {
            $query->whereDate($column, '>=', $request->input($prefix . '_from'));
        }

        if ($request->filled($prefix . '_to')) {
            $query->whereDate($column, '<=', $request->input($prefix . '_to'));
        }

        if ($acceptGenericAliases) {
            if ($request->filled('start_date')) {
                $query->whereDate($column, '>=', $request->input('start_date'));
            }

            if ($request->filled('end_date')) {
                $query->whereDate($column, '<=', $request->input('end_date'));
            }
        }

        return $query;
    }

    /**
     * Numeric range on a column, driven by `min_<column>` / `max_<column>`.
     */
    public static function numericRange(Builder $query, Request $request, string $column): Builder
    {
        if ($request->filled('min_' . $column)) {
            $query->where($column, '>=', (float) $request->input('min_' . $column));
        }

        if ($request->filled('max_' . $column)) {
            $query->where($column, '<=', (float) $request->input('max_' . $column));
        }

        return $query;
    }

    /**
     * Boolean filter that tolerates "1"/"0"/"true"/"false".
     *
     * @param  array<int, string>  $columns
     */
    public static function booleans(Builder $query, Request $request, array $columns): Builder
    {
        foreach ($columns as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->boolean($column));
            }
        }

        return $query;
    }

    /**
     * Sorting limited to an allow list, defaulting to newest first.
     *
     * @param  array<int, string>  $allowed
     */
    public static function sort(Builder $query, Request $request, array $allowed, string $default = 'created_at'): Builder
    {
        $column = $request->input('sort_by', $default);

        if (! in_array($column, $allowed, true)) {
            $column = $default;
        }

        $direction = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction);
    }

    /**
     * Page size, capped so a client cannot ask for the whole table.
     */
    public static function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        $perPage = (int) $request->input('per_page', $default);

        return max(1, min($perPage, $max));
    }

    /**
     * Restrict a business-scoped query to what the current user may see.
     */
    public static function restrictToUserBusinesses(Builder $query, Request $request, string $column = 'business_id'): Builder
    {
        $user = $request->user();

        if (! $user || $user->hasUnrestrictedAccess()) {
            return $query;
        }

        return $query->whereIn($column, $user->accessibleBusinessIds() ?? []);
    }
}
