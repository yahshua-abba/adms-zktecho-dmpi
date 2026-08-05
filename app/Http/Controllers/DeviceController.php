<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;
use App\Models\PayrollDevice;
use App\Queries\AttendanceQuery;
use App\Queries\DeviceRoster;
use App\Queries\LogQuery;
use App\Support\PerPage;
use App\Sync\AttendanceSync;
use App\Sync\DmpiSyncLauncher;
use App\Sync\EnrollmentReconciler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

class DeviceController extends Controller
{
    // Menampilkan daftar device
    public function index(Request $request)
    {
        $data['lable'] = 'Devices';
        // Use the Device model (not a raw row) so the view can call isOnline()/status.
        $data['log'] = Device::orderBy('online', 'DESC')->get();

        // Two different lists, and confusing them is easy: `devices` are physical
        // clocks that have checked in here, `payroll_devices` is DMPI's own list.
        // The payroll list used to render ONLY as a dropdown inside each physical
        // device row, so with no clocks checked in, a freshly downloaded set of 89
        // appeared nowhere at all — the download looked like it had failed.
        $linkedByCode = $data['log']->whereNotNull('payroll_device_code')->groupBy('payroll_device_code');

        $data['payrollDevices'] = PayrollDevice::orderBy('code')->get()
            ->each(fn (PayrollDevice $pd) => $pd->setAttribute(
                'linked_serials',
                ($linkedByCode->get($pd->code) ?? collect())->pluck('no_sn')->all()
            ));

        // How many people are on each clock. Bulk-counted for the whole list —
        // this page renders every device that has ever checked in, so a count
        // per row would be four queries per device.
        $data['peopleCounts'] = DeviceRoster::counts($data['log']);

        return view('devices.index', $data);
    }

    /**
     * Who is on one time clock.
     *
     * Reached from the People column on the Devices list. It shows both lists
     * side by side — what payroll assigns to the clock and what this server has
     * sent to it — because they are different facts and the gap between them is
     * the thing an operator is usually here to find. See App\Queries\DeviceRoster.
     */
    public function people(Request $request, Device $device)
    {
        $perPage = PerPage::resolve($request->has('per_page') ? (int) $request->query('per_page') : null);

        $roster = DeviceRoster::forDevice($device, $request->only(['search', 'status']), $perPage);

        return view('devices.people', [
            'device' => $device,
            'people' => $roster['people']->appends($request->query()),
            'summary' => $roster['summary'],
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'payrollDevice' => $device->payroll_device_code
                ? PayrollDevice::where('code', $device->payroll_device_code)->first()
                : null,
        ]);
    }

    public function DeviceLog(Request $request)
    {
        return $this->logScreen($request, 'device_log', 'Device Check-ins',
            'Each time a device connects to this server it "checks in" and reports its settings. Use this to confirm a device is actually reaching the server.',
            route('devices.DeviceLog'), true, [
                ['data' => 'created_at', 'title' => 'Time'],
                ['data' => 'sn', 'title' => 'Device'],
                ['data' => 'event', 'title' => 'What happened', 'orderable' => false],
                ['data' => 'details', 'title' => 'Technical details', 'orderable' => false],
            ]);
    }

    public function FingerLog(Request $request)
    {
        return $this->logScreen($request, 'finger_log', 'Device Messages',
            'Everything a device sends to the server — attendance taps and on-device activity (e.g. someone opening the device menu). Use this to confirm raw data is arriving.',
            route('devices.FingerLog'), false, [
                ['data' => 'created_at', 'title' => 'Time'],
                ['data' => 'device', 'title' => 'Device', 'orderable' => false],
                ['data' => 'event', 'title' => 'What happened', 'orderable' => false],
                ['data' => 'details', 'title' => 'Technical details', 'orderable' => false],
            ]);
    }

    // Shared server-side log screen for device_log / finger_log. Adds plain-language
    // "What happened" + "Technical details" columns so non-technical users can read it.
    private function logScreen(Request $request, string $table, string $title, string $intro, string $ajax, bool $showDevice, array $columns)
    {
        if ($request->ajax()) {
            $select = ['id', 'created_at', 'url', 'data'];
            if ($table === 'device_log') {
                $select[] = 'sn';
                $select[] = 'option';
            }

            $query = LogQuery::filtered($table, $request->only(['date_from', 'date_to', 'device', 'q']))
                ->select($select)
                ->orderBy('id', 'desc');

            return DataTables::of($query)
                ->editColumn('created_at', fn ($row) => (string) $row->created_at)
                ->addColumn('device', fn ($row) => $this->logDevice($row))
                ->addColumn('event', fn ($row) => $this->describeLogEvent($table, $row))
                ->addColumn('details', fn ($row) => '<code class="small text-muted">'.e(Str::limit(trim((string) ($row->data ?: $row->url)), 90)).'</code>')
                ->rawColumns(['device', 'event', 'details'])
                ->make(true);
        }

        return view('devices.logs', [
            'title' => $title,
            'intro' => $intro,
            'ajax' => $ajax,
            'showDevice' => $showDevice,
            'devices' => $showDevice ? Device::orderBy('no_sn')->get() : collect(),
            'columns' => $columns,
        ]);
    }

    // The device serial for a log row — a real column on device_log, parsed from
    // the JSON payload for finger_log.
    private function logDevice($row): string
    {
        if (! empty($row->sn)) {
            return e($row->sn);
        }
        $url = json_decode((string) $row->url, true);

        return is_array($url) && ! empty($url['SN'])
            ? e($url['SN'])
            : '<span class="text-muted">—</span>';
    }

    // Plain-language description of what a log row represents.
    private function describeLogEvent(string $table, $row): string
    {
        if ($table === 'device_log') {
            return '<span class="badge bg-info-subtle text-dark border">Check-in</span> <span class="small text-muted">connected &amp; reported its settings</span>';
        }

        $url = json_decode((string) $row->url, true);
        $type = is_array($url) ? strtoupper((string) ($url['table'] ?? '')) : '';

        return match ($type) {
            'ATTLOG' => '<span class="badge bg-success">Attendance tap</span> <span class="small text-muted">a punch was received</span>',
            'OPERLOG' => '<span class="badge bg-secondary">Device activity</span> <span class="small text-muted">menu / settings used on the device</span>',
            default => '<span class="badge bg-light text-dark border">Data received</span>',
        };
    }

    public function Attendance(Request $request)
    {
        if ($request->ajax()) {
            $query = AttendanceQuery::filtered($request->only(['date_from', 'date_to', 'device', 'employee', 'sync', 'company']))
                ->leftJoin('employee_map', 'employee_map.device_pin', '=', 'attendances.employee_id')
                ->leftJoin('devices', 'devices.no_sn', '=', 'attendances.sn')
                ->select(
                    'attendances.*',
                    'employee_map.name as emp_name',
                    'employee_map.chapa as emp_chapa',
                    'employee_map.company as emp_company',
                    'employee_map.payroll_employee_id as emp_payroll_id',
                    'devices.nama as dev_nama',
                    'devices.lokasi as dev_lokasi',
                )
                ->orderBy('attendances.id', 'desc');

            return DataTables::of($query)
                ->addColumn('device_display', function ($row) {
                    $title = $row->dev_nama ? e($row->dev_nama) : e($row->sn);
                    $serial = $row->dev_nama ? '<div class="small text-muted">'.e($row->sn).'</div>' : '';
                    $location = $row->dev_lokasi ? '<div class="small text-muted"><i class="bi bi-geo-alt"></i> '.e($row->dev_lokasi).'</div>' : '';

                    return $title.$serial.$location;
                })
                ->addColumn('inout', function ($row) {
                    // IN/OUT is frozen onto the punch at arrival and read-only here;
                    // never recompute it from the device's (mutable) current direction.
                    return match ($row->log_type) {
                        'in' => '<span class="badge bg-info text-dark">IN</span>',
                        'out' => '<span class="badge bg-dark">OUT</span>',
                        default => '<span class="text-muted" title="device had no direction set when this punch arrived">—</span>',
                    };
                })
                ->addColumn('employee_display', function ($row) {
                    if ($row->emp_name) {
                        return '<div class="fw-semibold">'.e($row->emp_name).'</div>'
                            .'<div class="small text-muted">CHAPA '.e($row->emp_chapa).' · Co '.e($row->emp_company).' · Payroll #'.e($row->emp_payroll_id).'</div>'
                            .'<div class="small text-muted">PIN '.e($row->employee_id).'</div>';
                    }

                    return '<span class="badge bg-warning text-dark">PIN '.e($row->employee_id).' · unmapped</span>';
                })
                ->addColumn('sync_status', function ($row) {
                    if ($row->is_sync) {
                        return '<span class="badge bg-success">synced</span>';
                    }
                    if ($row->sync_excluded) {
                        return '<span class="badge bg-warning text-dark" title="Excluded from sync — won\'t be pushed automatically">skipped</span>';
                    }
                    if ($row->sync_error) {
                        return '<span class="badge bg-danger" title="'.e($row->sync_error).'">failed</span>';
                    }

                    return '<span class="badge bg-secondary">pending</span>';
                })
                // When ADMS ingested the punch from the device.
                ->addColumn('received_at', fn ($row) => $row->created_at ? $row->created_at->format('Y-m-d H:i:s') : '—')
                // When the punch was pushed to payroll.
                ->addColumn('synced_at', fn ($row) => $row->sync_time
                    ? $row->sync_time->format('Y-m-d H:i:s')
                    : '<span class="text-muted">—</span>')
                ->editColumn('timestamp', fn ($row) => (string) $row->timestamp)
                ->rawColumns(['device_display', 'inout', 'employee_display', 'sync_status', 'synced_at'])
                ->make(true);
        }

        return view('devices.attendance', [
            'devices' => Device::orderBy('no_sn')->get(),
            'companies' => EmployeeMap::whereNotNull('company')->distinct()->orderBy('company')->pluck('company'),
            'filters' => $request->only(['date_from', 'date_to', 'device', 'employee', 'sync', 'company']),
        ]);
    }

    // Stream the attendance table to CSV. Honors the exact same filters as the
    // on-screen table (reuses AttendanceQuery::filtered); with no filters it is a
    // full bulk export. Chunked + streamed so large exports never load every row
    // into memory at once.
    public function exportAttendance(Request $request)
    {
        $filters = $request->only(['date_from', 'date_to', 'device', 'employee', 'sync', 'company']);

        $query = AttendanceQuery::filtered($filters)
            ->leftJoin('employee_map', 'employee_map.device_pin', '=', 'attendances.employee_id')
            ->leftJoin('devices', 'devices.no_sn', '=', 'attendances.sn')
            ->select(
                'attendances.*',
                'employee_map.name as emp_name',
                'employee_map.chapa as emp_chapa',
                'employee_map.company as emp_company',
                'employee_map.payroll_employee_id as emp_payroll_id',
                'devices.nama as dev_nama',
                'devices.lokasi as dev_lokasi',
            )
            ->orderBy('attendances.id', 'desc');

        $filename = 'attendances-'.now()->format('Y-m-d_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ];

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens accented names/locations correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID', 'Punched at', 'Received at', 'Device serial', 'Device name',
                'Device location', 'In/Out', 'Employee PIN', 'Employee name',
                'CHAPA', 'Company', 'Payroll ID', 'Sync status', 'Synced at', 'Sync error',
            ]);

            $query->chunk(2000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    $status = $row->is_sync
                        ? 'synced'
                        : ($row->sync_excluded ? 'skipped' : ($row->sync_error ? 'failed' : 'pending'));

                    fputcsv($out, [
                        $row->id,
                        (string) $row->timestamp,
                        $row->created_at ? $row->created_at->format('Y-m-d H:i:s') : '',
                        $row->sn,
                        $row->dev_nama,
                        $row->dev_lokasi,
                        $row->log_type ? strtoupper($row->log_type) : '',
                        $row->employee_id,
                        $row->emp_name,
                        $row->emp_chapa,
                        $row->emp_company,
                        $row->emp_payroll_id,
                        $status,
                        $row->sync_time ? $row->sync_time->format('Y-m-d H:i:s') : '',
                        $row->sync_error,
                    ]);
                }
            });

            fclose($out);
        }, $filename, $headers);
    }

    // Set a device's IN/OUT direction (drives log_type sent to payroll), name, location.
    public function update(Request $request, Device $device)
    {
        $validated = $request->validate([
            'direction' => ['nullable', 'in:in,out,both'],
            'nama' => ['nullable', 'string', 'max:255'],
            'lokasi' => ['nullable', 'string', 'max:255'],
            'payroll_device_code' => ['nullable', 'string', 'max:255'],
        ]);

        $device->update($validated);

        return redirect()->route('devices.index')->with('success', 'Device updated.');
    }

    /**
     * Remove a clock from this server.
     *
     * Devices add themselves by checking in, so this is a tidy-up for readers that
     * have been retired, replaced, or (like a test serial) never existed — not a
     * way to stop a live one, which simply reappears on its next check-in.
     *
     * Punches are deliberately kept: they are the data of record, they may not have
     * reached payroll yet, and they are still valid attendance whatever happened to
     * the hardware. What does go is our belief about the device — the enrolled user
     * list and any queued commands. Leaving the enrollment behind would be worse
     * than useless: if the clock ever came back, the reconciler would compare
     * against a stale list, conclude its users were already loaded, and push
     * nothing to a reader that might have been wiped.
     */
    public function destroy(Device $device)
    {
        $serial = $device->no_sn;
        $punches = Attendance::where('sn', $serial)->count();

        DB::transaction(function () use ($serial, $device) {
            DeviceEnrollment::where('device_sn', $serial)->delete();
            DeviceCommand::where('device_sn', $serial)->delete();
            $device->delete();
        });

        $message = "Device {$serial} removed. Its {$punches} punch(es) were kept.";
        ActivityLog::record('device.removed', $message, 'warning');

        return redirect()->route('devices.index')->with(
            'success',
            $message.' It will reappear on its own if it checks in again.'
        );
    }

    // Live online/offline status per device serial, polled by the Devices page.
    public function status()
    {
        return Device::all()->mapWithKeys(fn (Device $d) => [
            $d->no_sn => [
                'online' => $d->isOnline(),
                'seen' => $d->online ? $d->online->diffForHumans() : null,
            ],
        ]);
    }

    // Manual "Sync from DMPI" button — pulls the roster + device info from DMPI,
    // then re-queues enrollment commands so RFID/assignment changes reach devices.
    /**
     * Kick off a full DMPI pull in the background.
     *
     * This used to run all three stages inline while the browser waited. Payroll
     * reads get a ten-minute ceiling and the app serves one request at a time, so
     * a single press could take the entire dashboard down — login page included —
     * for the length of the pull. It now launches and returns immediately; the
     * outcome lands in Server Activity.
     */
    public function syncFromDmpi(DmpiSyncLauncher $launcher, string $part)
    {
        if (! array_key_exists($part, DmpiSyncLauncher::PARTS)) {
            abort(404);
        }

        if ($launcher->isRunning()) {
            return redirect()->back()->with('error', 'A DMPI download is already running. Watch Server Activity for the result.');
        }

        $launcher->start($part);
        ActivityLog::record('dmpi.pull', "Manual DMPI download requested from the dashboard: {$part}.");

        $expectation = match ($part) {
            'employees' => 'Downloading employees — usually about half a minute.',
            'devices' => 'Downloading the clock list.',
            'assignments' => 'Downloading device assignments, then updating the clocks.',
        };

        return redirect()->back()->with(
            'success',
            $expectation.' It runs in the background, so this page stays usable — watch Server Activity for the result.'
        );
    }

    // Manual "Sync to payroll now" button — pushes ALL pending (non-excluded)
    // punches to DMPI, same as the scheduled sync-attendances command.
    public function syncAttendances(AttendanceSync $sync)
    {
        $sync->sync((int) config('payroll.batch_size'));

        return redirect()->route('devices.Attendance')->with('success', 'Pushed pending punches to payroll.');
    }

    // Attendance screen row-selection toolbar: "Sync selected" — pushes only the
    // hand-picked punches, bypassing sync_excluded (an explicit pick overrides a
    // standing "skip" mark). Already-synced ids in the selection are ignored.
    public function syncSelectedAttendances(Request $request, AttendanceSync $sync)
    {
        $ids = $this->validatedAttendanceIds($request);
        $result = $sync->syncIds($ids);

        $message = "Synced {$result['synced']} selected punch(es) to payroll.";
        if ($result['failed'] > 0) {
            $message .= " {$result['failed']} failed — see the Sync column for the reason.";
        }

        return response()->json(['message' => $message]);
    }

    // Attendance screen row-selection toolbar: "Exclude from sync" / "Include in
    // sync" — flips sync_excluded so the automatic/scheduled sync stops (or
    // resumes) pushing the selected punches.
    public function excludeAttendances(Request $request)
    {
        $ids = $this->validatedAttendanceIds($request);
        $excluded = $request->boolean('excluded');

        // sync_excluded is a "don't push this yet" mark, so it only ever applies to
        // unsynced rows — skipping an already-synced punch is meaningless. Filtering
        // here keeps a selection that mixes synced and pending rows from leaving
        // is_sync=true alongside sync_excluded=true.
        $selected = Attendance::whereIn('id', $ids);
        $count = (clone $selected)->where('is_sync', false)->count();
        $skipped = (clone $selected)->where('is_sync', true)->count();

        Attendance::whereIn('id', $ids)
            ->where('is_sync', false)
            ->update(['sync_excluded' => $excluded]);

        $message = $excluded
            ? "{$count} punch(es) excluded from sync — they won't be pushed automatically."
            : "{$count} punch(es) re-included for sync.";

        if ($skipped > 0) {
            $message .= " {$skipped} already-synced punch(es) were left unchanged.";
        }

        return response()->json(['message' => $message]);
    }

    // Attendance screen row-selection toolbar: "Delete selected" — permanently
    // removes the chosen punches from ADMS. This does not un-send anything already
    // pushed to payroll; it only removes the local record.
    public function destroyAttendances(Request $request)
    {
        $ids = $this->validatedAttendanceIds($request);
        $count = Attendance::whereIn('id', $ids)->delete();

        return response()->json(['message' => "Deleted {$count} punch(es)."]);
    }

    private function validatedAttendanceIds(Request $request): array
    {
        return $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];
    }

    // Manually queue enrollment commands for one device (the "Sync enrollments" button).
    public function syncEnrollments(Device $device, EnrollmentReconciler $reconciler)
    {
        $reconciler->reconcileDevice($device->no_sn);

        return redirect()->route('devices.index')->with('success', "Enrollment queued for {$device->no_sn}.");
    }

    // Punches recorded by one device — reuse the filtered Attendance screen.
    public function DevicePunchLog(Device $device)
    {
        return redirect()->route('devices.Attendance', ['device' => $device->no_sn]);
    }

    // // Menampilkan form tambah device
    // public function create()
    // {
    //     return view('devices.create');
    // }

    // // Menyimpan device baru ke database
    // public function store(Request $request)
    // {
    //     $device = new Device();
    //     $device->nama = $request->input('nama');
    //     $device->no_sn = $request->input('no_sn');
    //     $device->lokasi = $request->input('lokasi');
    //     $device->save();

    //     return redirect()->route('devices.index')->with('success', 'Device berhasil ditambahkan!');
    // }

    // // Menampilkan detail device
    // public function show($id)
    // {
    //     $device = Device::find($id);
    //     return view('devices.show', compact('device'));
    // }

    // // Menampilkan form edit device
    // public function edit($id)
    // {
    //     $device = Device::find($id);
    //     return view('devices.edit', compact('device'));
    // }

    // // Mengupdate device ke database
    // public function update(Request $request, $id)
    // {
    //     $device = Device::find($id);
    //     $device->nama = $request->input('nama');
    //     $device->no_sn = $request->input('no_sn');
    //     $device->lokasi = $request->input('lokasi');
    //     $device->save();

    //     return redirect()->route('devices.index')->with('success', 'Device berhasil diupdate!');
    // }

    // // Menghapus device dari database
    // public function destroy($id)
    // {
    //     $device = Device::find($id);
    //     $device->delete();

    //     return redirect()->route('devices.index')->with('success', 'Device berhasil dihapus!');
    // }
}
