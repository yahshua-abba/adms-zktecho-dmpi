<?php

namespace App\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Filters the raw contact logs (device_log / finger_log) for the Logs screens.
 *
 * Both tables carry `data`, `url`, and `created_at`; only device_log has an
 * `sn` column, so the device filter is applied only where that column exists.
 *
 * Recognised keys: date_from, date_to (Y-m-d on created_at), device (sn),
 * q (free text matched against data + url).
 */
class LogQuery
{
    public static function filtered(string $table, array $filters): Builder
    {
        $query = DB::table($table);

        // Range comparisons (not whereDate) so the created_at index is used.
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }

        if (! empty($filters['device']) && Schema::hasColumn($table, 'sn')) {
            $query->where('sn', $filters['device']);
        }

        if (! empty($filters['q'])) {
            $term = $filters['q'];
            $query->where(function (Builder $sub) use ($term) {
                $sub->where('data', 'like', "%{$term}%")
                    ->orWhere('url', 'like', "%{$term}%");
            });
        }

        return $query;
    }
}
