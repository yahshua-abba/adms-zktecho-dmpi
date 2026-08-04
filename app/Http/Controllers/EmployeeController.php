<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Queries\EmployeeDirectory;
use App\Support\PerPage;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        // Two independently-paginated tables share this one page, so each gets
        // its own page/per-page query param name (mapped_* / unmapped_*) — see
        // EmployeeDirectory — plus a `tab` param so paginating one doesn't bounce
        // the page back to the other tab on reload.
        $tab = $request->query('tab') === 'unmapped' ? 'unmapped' : 'mapped';

        $mappedPerPage = PerPage::resolve($request->has('mapped_per_page') ? (int) $request->query('mapped_per_page') : null);
        $unmappedPerPage = PerPage::resolve($request->has('unmapped_per_page') ? (int) $request->query('unmapped_per_page') : null);

        $mapped = EmployeeDirectory::mapped($request->query('search'), $request->query('device'), $mappedPerPage)
            ->appends(array_merge($request->query(), ['tab' => 'mapped']));

        $unmapped = EmployeeDirectory::unmappedPins($unmappedPerPage)
            ->appends(array_merge($request->query(), ['tab' => 'unmapped']));

        return view('employees.index', [
            'search' => $request->query('search'),
            'device' => $request->query('device'),
            'tab' => $tab,
            'devices' => Device::whereNotNull('payroll_device_code')->orderBy('no_sn')->get(),
            'mapped' => $mapped,
            'unmapped' => $unmapped,
        ]);
    }
}
