<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\EmployeeMap;
use App\Models\PinCollision;
use App\Queries\EmployeeDirectory;
use App\Support\PerPage;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        // Three independently-paginated tables share this one page, so each gets
        // its own page/per-page query param name (mapped_* / unmapped_* /
        // conflicts_*) — see EmployeeDirectory — plus a `tab` param so paginating
        // one doesn't bounce the page back to another tab on reload.
        $tab = in_array($request->query('tab'), ['unmapped', 'conflicts'], true)
            ? $request->query('tab')
            : 'mapped';

        $mappedPerPage = PerPage::resolve($request->has('mapped_per_page') ? (int) $request->query('mapped_per_page') : null);
        $unmappedPerPage = PerPage::resolve($request->has('unmapped_per_page') ? (int) $request->query('unmapped_per_page') : null);
        $conflictsPerPage = PerPage::resolve($request->has('conflicts_per_page') ? (int) $request->query('conflicts_per_page') : null);

        $mapped = EmployeeDirectory::mapped($request->query('search'), $request->query('device'), $mappedPerPage)
            ->appends(array_merge($request->query(), ['tab' => 'mapped']));

        $unmapped = EmployeeDirectory::unmappedPins($unmappedPerPage)
            ->appends(array_merge($request->query(), ['tab' => 'unmapped']));

        $conflicts = EmployeeDirectory::collisions($conflictsPerPage)
            ->appends(array_merge($request->query(), ['tab' => 'conflicts']));

        return view('employees.index', [
            'search' => $request->query('search'),
            'device' => $request->query('device'),
            'tab' => $tab,
            'devices' => Device::whereNotNull('payroll_device_code')->orderBy('no_sn')->get(),
            'mapped' => $mapped,
            'unmapped' => $unmapped,
            'conflicts' => $conflicts,
            'unresolvedConflicts' => PinCollision::whereNull('resolved_payroll_employee_id')->count(),
        ]);
    }

    /**
     * Decide which payroll employee owns a contested device PIN.
     *
     * The decision is stored on the collision so it survives later roster pulls
     * (RosterSync re-validates it against the current claimants each run), and the
     * mapping is written straight away — otherwise the operator resolves the
     * conflict and nothing visibly happens until the next hourly pull.
     */
    public function resolveCollision(Request $request, PinCollision $collision)
    {
        $validated = $request->validate([
            'payroll_employee_id' => ['required', 'integer'],
        ]);

        $chosenId = (int) $validated['payroll_employee_id'];

        // Only somebody currently claiming the PIN can be given it. Guards against
        // a stale form posted after DMPI's claimants changed underneath it.
        $claimant = collect($collision->claimants ?? [])
            ->first(fn (array $c) => (int) $c['payroll_employee_id'] === $chosenId);

        if ($claimant === null) {
            return back()->with('error', 'That employee no longer claims this PIN — re-sync from DMPI and try again.');
        }

        $collision->forceFill([
            'resolved_payroll_employee_id' => $chosenId,
            'resolved_at' => now(),
        ])->save();

        EmployeeMap::updateOrCreate(
            ['device_pin' => $collision->device_pin],
            [
                'company' => $claimant['company'],
                'chapa' => $claimant['chapa'],
                'payroll_employee_id' => $chosenId,
                'name' => $claimant['name'] ?? null,
                'rfid' => $claimant['rfid'] ?? null,
            ],
        );

        ActivityLog::record(
            'pin.collision',
            "Device PIN {$collision->device_pin} assigned to payroll employee {$chosenId}"
            .($claimant['name'] ? " ({$claimant['name']})" : '').'. Its pending punches will sync on the next run.',
        );

        return back()->with('success', "PIN {$collision->device_pin} now maps to payroll employee {$chosenId}. Pending punches will sync on the next run.");
    }

    /**
     * Undo a decision — the PIN goes back to unmapped, so its punches stop
     * flowing rather than continuing to a choice the operator has withdrawn.
     */
    public function clearCollision(PinCollision $collision)
    {
        $collision->forceFill([
            'resolved_payroll_employee_id' => null,
            'resolved_at' => null,
        ])->save();

        EmployeeMap::where('device_pin', $collision->device_pin)->delete();

        ActivityLog::record(
            'pin.collision',
            "Decision withdrawn for device PIN {$collision->device_pin}; it is unmapped again and its punches will not sync.",
            'error',
        );

        return back()->with('success', "PIN {$collision->device_pin} is contested again and will not sync until it's decided.");
    }
}
