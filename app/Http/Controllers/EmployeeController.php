<?php

namespace App\Http\Controllers;

use App\Models\Device;
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
}
