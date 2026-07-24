<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\EmployeeMap;
use App\Queries\EmployeeDirectory;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        return view('employees.index', [
            'search' => $request->query('search'),
            'device' => $request->query('device'),
            'devices' => Device::whereNotNull('payroll_device_code')->orderBy('no_sn')->get(),
            'mapped' => EmployeeDirectory::mapped($request->query('search'), $request->query('device')),
            'unmapped' => EmployeeDirectory::unmappedPins(),
        ]);
    }

    public function lookupDeviceUser(string $pin)
    {
        if (EmployeeMap::where('device_pin', $pin)->exists()) {
            return redirect()->route('employees.index')
                ->with('error', "PIN {$pin} is already mapped to an employee.");
        }

        $sourceDevices = Attendance::where('employee_id', $pin)
            ->distinct()
            ->pluck('sn');
        $queued = 0;

        foreach ($sourceDevices as $deviceSn) {
            $body = "DATA QUERY USERINFO PIN={$pin}";
            $alreadyQueued = DeviceCommand::where('device_sn', $deviceSn)
                ->where('body', $body)
                ->whereIn('status', ['pending', 'sent'])
                ->exists();

            if (! $alreadyQueued) {
                DeviceCommand::create([
                    'device_sn' => $deviceSn,
                    'body' => $body,
                    'status' => 'pending',
                ]);
                $queued++;
            }
        }

        $message = $queued
            ? "Lookup queued for PIN {$pin}. Refresh after the device checks in."
            : "A lookup for PIN {$pin} is already waiting for the device.";

        return redirect()->route('employees.index')->with('success', $message);
    }
}
