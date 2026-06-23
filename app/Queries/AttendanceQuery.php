<?php

namespace App\Queries;

use App\Models\Attendance;
use App\Models\EmployeeMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Turns a set of UI filters into a filtered attendance query.
 *
 * Centralises every "how do we slice punches" rule (date range, device,
 * payroll-sync status, employee by CHAPA or name) so the controller and the
 * yajra DataTables endpoint share one definition and the rules are tested
 * directly, independent of HTTP or the DataTables JSON envelope.
 *
 * Recognised keys: date_from, date_to (Y-m-d), device (sn),
 * sync ("synced"|"failed"|"pending"), employee (matches CHAPA or mapped name).
 */
class AttendanceQuery
{
    public static function filtered(array $filters): Builder
    {
        $query = Attendance::query();

        // Range comparisons (not whereDate) so the timestamp index is used.
        if (! empty($filters['date_from'])) {
            $query->where('timestamp', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('timestamp', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }

        if (! empty($filters['device'])) {
            $query->where('sn', $filters['device']);
        }

        if (! empty($filters['sync'])) {
            match ($filters['sync']) {
                'synced' => $query->where('is_sync', true),
                'failed' => $query->where('is_sync', false)->whereNotNull('sync_error'),
                'pending' => $query->where('is_sync', false)->whereNull('sync_error'),
                default => null,
            };
        }

        if (! empty($filters['company'])) {
            $query->whereIn('employee_id', EmployeeMap::where('company', $filters['company'])->pluck('device_pin'));
        }

        if (! empty($filters['employee'])) {
            $term = $filters['employee'];
            // Names live in employee_map; resolve them to device PINs. A raw term
            // also matches the PIN directly (which contains the CHAPA).
            $matchingPins = EmployeeMap::where('name', 'like', "%{$term}%")->pluck('device_pin');

            $query->where(function (Builder $sub) use ($term, $matchingPins) {
                $sub->where('employee_id', 'like', "%{$term}%")
                    ->orWhereIn('employee_id', $matchingPins);
            });
        }

        return $query;
    }
}
