<?php

namespace App\Sync;

use App\Contracts\PayrollClient;
use App\Models\EmployeeMap;

/**
 * Pulls the employee roster from DMPI and upserts the device-PIN -> payroll-id
 * map. Devices are enrolled with User ID = "{company}_{chapa}" (the legacy TCD
 * scheme), so that composite IS the device PIN and the map's join key — CHAPA
 * alone collides across the manpower companies.
 */
class RosterSync
{
    public function __construct(private PayrollClient $payroll)
    {
    }

    public function sync(): void
    {
        foreach ($this->payroll->fetchEmployees() as $employee) {
            $devicePin = $employee['company'].'_'.$employee['chapa'];

            EmployeeMap::updateOrCreate(
                ['device_pin' => $devicePin],
                [
                    'company' => (string) $employee['company'],
                    'chapa' => (string) $employee['chapa'],
                    'payroll_employee_id' => $employee['id'],
                    'name' => $employee['name'] ?? null,
                    'rfid' => $employee['rfid'] ?? null,
                ],
            );
        }
    }
}
