<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\PayrollDevice;
use App\Queries\TimekeeperDirectory;
use App\Support\PerPage;
use App\Sync\EnrollmentReconciler;
use Illuminate\Http\Request;

/**
 * DMPI's timekeeper devices, from payroll's side.
 *
 * Every row here came down in an earlier DMPI pull and is served from local
 * tables, so listing and searching stay usable while DMPI is unreachable. An
 * eligible row can also queue that one employee to one linked physical clock;
 * this writes only to the local device mailbox and does not call payroll.
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

    /** Queue one eligible payroll employee for one explicitly chosen clock. */
    public function syncPerson(
        string $code,
        int $payrollEmployeeId,
        Device $device,
        EnrollmentReconciler $reconciler,
    ) {
        PayrollDevice::where('code', $code)->firstOrFail();

        if ($device->payroll_device_code !== $code) {
            return back()->with('error', 'That physical clock is not linked to this payroll device. Nothing was queued.');
        }

        $result = $reconciler->reconcilePerson($device->no_sn, $payrollEmployeeId);
        $clockName = $device->nama ?: $device->no_sn;

        return match ($result) {
            EnrollmentReconciler::PERSON_QUEUED => back()->with(
                'success',
                "Queued payroll employee {$payrollEmployeeId} for {$clockName}.",
            ),
            EnrollmentReconciler::PERSON_ALREADY_WAITING => back()->with(
                'success',
                "That employee is already waiting for {$clockName} to collect the command.",
            ),
            EnrollmentReconciler::PERSON_NOT_ASSIGNED => back()->with(
                'error',
                'Payroll no longer assigns that employee to this device. Download assignments and try again.',
            ),
            default => back()->with(
                'error',
                'That employee is no longer eligible. Follow the eligibility guide shown in their row.',
            ),
        };
    }
}
