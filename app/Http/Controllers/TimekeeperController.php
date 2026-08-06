<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\PayrollDevice;
use App\Queries\TimekeeperDirectory;
use App\Support\PerPage;
use Illuminate\Http\Request;

/**
 * DMPI's timekeeper devices, from payroll's side.
 *
 * Read-only. Every row here came down in an earlier DMPI pull and is served
 * from local tables — nothing on these screens calls payroll, so they stay
 * usable while a download is running or while DMPI is unreachable.
 *
 * See App\Queries\TimekeeperDirectory for why this exists alongside the
 * per-reader People screen rather than inside it.
 */
class TimekeeperController extends Controller
{
    public function index(Request $request)
    {
        $perPage = PerPage::resolve($request->has('per_page') ? (int) $request->query('per_page') : null);

        return view('timekeepers.index', [
            'devices' => TimekeeperDirectory::devices($request->only(['search', 'filter']), $perPage)
                ->appends($request->query()),
            'summary' => TimekeeperDirectory::summary(),
            'search' => $request->query('search'),
            'filter' => $request->query('filter'),
        ]);
    }

    public function show(Request $request, string $code)
    {
        // 404 on an unknown code rather than an empty page: "this door has nobody
        // on it" and "there is no such door" want different reactions, and an
        // empty table says the first while meaning the second.
        $device = PayrollDevice::where('code', $code)->firstOrFail();

        $perPage = PerPage::resolve($request->has('per_page') ? (int) $request->query('per_page') : null);
        $roster = TimekeeperDirectory::people($device->code, $request->only(['search', 'status']), $perPage);

        return view('timekeepers.show', [
            'device' => $device,
            'people' => $roster['people']->appends($request->query()),
            'summary' => $roster['summary'],
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            // The readers pointed at this door. Whether each person is actually ON
            // one of them is a per-reader fact, so it stays on the reader's own
            // People screen and is linked to rather than merged in here.
            'readers' => Device::where('payroll_device_code', $device->code)->orderBy('no_sn')->get(),
        ]);
    }
}
