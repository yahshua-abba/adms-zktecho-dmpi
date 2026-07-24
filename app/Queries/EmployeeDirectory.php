<?php

namespace App\Queries;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceEnrollment;
use App\Models\DeviceUser;
use App\Models\EmployeeMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Employees screen's data: the mapped roster (employee_map, with each
 * person's last punch) and the "unmapped device PINs" list — PINs that have
 * tapped but have no payroll mapping yet, i.e. enrollment gaps to fix.
 */
class EmployeeDirectory
{
    /**
     * Mapped employees with last punch time and the physical devices they're
     * enrolled on. Searchable across every column, and filterable to a single
     * physical device (by serial).
     *
     * @param  ?string  $search  matches name, CHAPA, company, device PIN, RFID,
     *                           payroll id, or an enrolled device's serial/name/code
     * @param  ?string  $device  a physical device serial (no_sn) to filter by
     */
    public static function mapped(?string $search = null, ?string $device = null): Collection
    {
        $query = EmployeeMap::query()
            ->select('employee_map.*')
            ->selectSub(
                Attendance::selectRaw('max(timestamp)')
                    ->whereColumn('employee_id', 'employee_map.device_pin'),
                'last_punch_at'
            );

        // Dropdown filter: only employees enrolled on the chosen physical device
        // (resolved through its linked payroll device code).
        if ($device) {
            $code = Device::where('no_sn', $device)->value('payroll_device_code');
            $assignedIds = $code
                ? DeviceAssignment::where('device_code', $code)->pluck('payroll_employee_id')
                : collect();
            $query->whereIn('payroll_employee_id', $assignedIds);
        }

        if ($search) {
            // Employees whose enrolled device (serial, name, or payroll code) matches.
            $deviceMatchedIds = DeviceAssignment::query()
                ->leftJoin('devices', 'devices.payroll_device_code', '=', 'device_assignments.device_code')
                ->where(function (Builder $sub) use ($search) {
                    $sub->where('device_assignments.device_code', 'like', "%{$search}%")
                        ->orWhere('devices.no_sn', 'like', "%{$search}%")
                        ->orWhere('devices.nama', 'like', "%{$search}%");
                })
                ->pluck('device_assignments.payroll_employee_id');

            $query->where(function (Builder $sub) use ($search, $deviceMatchedIds) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('chapa', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('device_pin', 'like', "%{$search}%")
                    ->orWhere('rfid', 'like', "%{$search}%")
                    ->orWhere('payroll_employee_id', 'like', "%{$search}%")
                    ->orWhereIn('payroll_employee_id', $deviceMatchedIds);
            });
        }

        $employees = $query->orderBy('name')->get();

        // Resolve each employee's assigned payroll-device codes to the physical
        // device(s) (serial + name) linked to them. Done in PHP to stay cross-DB.
        $physicalByCode = Device::whereNotNull('payroll_device_code')
            ->get(['no_sn', 'nama', 'payroll_device_code'])
            ->groupBy('payroll_device_code');

        $codesByPayroll = DeviceAssignment::whereIn('payroll_employee_id', $employees->pluck('payroll_employee_id'))
            ->get()
            ->groupBy('payroll_employee_id')
            ->map(fn ($group) => $group->pluck('device_code')->unique()->values()->all());

        $employees->each(function ($e) use ($codesByPayroll, $physicalByCode) {
            $devices = [];
            foreach ($codesByPayroll->get($e->payroll_employee_id, []) as $code) {
                $linked = $physicalByCode->get($code);
                if ($linked) {
                    foreach ($linked as $d) {
                        $devices[] = ['serial' => $d->no_sn, 'name' => $d->nama, 'code' => $code];
                    }
                } else {
                    // Assigned to a payroll device with no physical reader linked yet.
                    $devices[] = ['serial' => null, 'name' => null, 'code' => $code];
                }
            }
            $e->setAttribute('devices', $devices);
        });

        return $employees;
    }

    /**
     * Device PINs seen in attendances that have no employee_map row.
     *
     * The push protocol sends the PIN, not the RFID/card number. We can still
     * provide useful investigation context from the punch source and any
     * enrollment state previously recorded by ADMS.
     */
    public static function unmappedPins(): Collection
    {
        $unmapped = Attendance::query()
            ->whereNotIn('employee_id', EmployeeMap::pluck('device_pin'))
            ->selectRaw('employee_id, count(*) as punch_count, max(timestamp) as last_punch_at')
            ->groupBy('employee_id')
            ->orderByRaw('max(timestamp) desc')
            ->get();

        if ($unmapped->isEmpty()) {
            return $unmapped;
        }

        $pins = $unmapped->pluck('employee_id');
        $allSerials = Attendance::query()
            ->whereIn('employee_id', $pins)
            ->select('employee_id', 'sn')
            ->distinct()
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->pluck('sn')->values());

        $devices = Device::whereIn('no_sn', $allSerials->flatten()->unique())
            ->get(['no_sn', 'nama'])
            ->keyBy('no_sn');

        $deviceUsers = DeviceUser::query()
            ->whereIn('pin', $pins)
            ->whereIn('device_sn', $allSerials->flatten()->unique())
            ->get()
            ->keyBy(fn ($user) => $user->device_sn.'|'.$user->pin);

        $cardsByPin = DeviceEnrollment::query()
            ->whereIn('pin', $pins)
            ->whereNotNull('card')
            ->where('card', '<>', '')
            ->get(['pin', 'card'])
            ->groupBy('pin')
            ->map(fn ($rows) => $rows->pluck('card')->unique()->values());

        $unmapped->each(function ($row) use ($allSerials, $devices, $deviceUsers, $cardsByPin) {
            $row->setAttribute('source_devices', $allSerials->get($row->employee_id, collect())
                ->map(fn ($serial) => [
                    'serial' => $serial,
                    'name' => $devices->get($serial)?->nama,
                ])->values()->all());
            $row->setAttribute('device_users', $allSerials->get($row->employee_id, collect())
                ->map(function ($serial) use ($row, $deviceUsers, $devices) {
                    $user = $deviceUsers->get($serial.'|'.$row->employee_id);

                    return [
                        'serial' => $serial,
                        'device_name' => $devices->get($serial)?->nama,
                        'user_name' => $user?->name,
                        'card' => $user?->card,
                    ];
                })->values()->all());
            $row->setAttribute('known_cards', $cardsByPin->get($row->employee_id, collect())->all());
        });

        return $unmapped;
    }
}
