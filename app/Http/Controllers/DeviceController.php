<?php

namespace App\Http\Controllers;

use Yajra\DataTables\Facades\DataTables;
use Illuminate\Http\Request;
use App\Models\Device;
use App\Models\Attendance;
use App\Queries\AttendanceQuery;
use App\Queries\LogQuery;
use DB;

class DeviceController extends Controller
{
    // Menampilkan daftar device
    public function index(Request $request)
    {
        $data['lable'] = "Devices";
        // Use the Device model (not a raw row) so the view can call isOnline()/status.
        $data['log'] = Device::orderBy('online', 'DESC')->get();
        $data['payrollDevices'] = \App\Models\PayrollDevice::orderBy('code')->get();
        return view('devices.index',$data);
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
                ->addColumn('details', fn ($row) => '<code class="small text-muted">'.e(\Illuminate\Support\Str::limit(trim((string) ($row->data ?: $row->url)), 90)).'</code>')
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
    public function Attendance(Request $request) {
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
            'companies' => \App\Models\EmployeeMap::whereNotNull('company')->distinct()->orderBy('company')->pluck('company'),
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
                    $status = $row->is_sync ? 'synced' : ($row->sync_error ? 'failed' : 'pending');

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
    public function syncFromDmpi(
        \App\Sync\RosterSync $roster,
        \App\Sync\DeviceInfoSync $devices,
        \App\Sync\EnrollmentReconciler $reconciler
    ) {
        try {
            $roster->sync();
            $devices->sync();
            $reconciler->reconcileAll();
            \App\Models\ActivityLog::record('dmpi.pull', 'Pulled roster + devices from DMPI and reconciled enrollments (manual).');

            return redirect()->back()->with('success', 'Synced from DMPI — roster, devices, and enrollments updated.');
        } catch (\Throwable $e) {
            \App\Models\ActivityLog::record('dmpi.pull', 'Manual DMPI sync failed: '.$e->getMessage(), 'error');

            return redirect()->back()->with('error', 'Sync from DMPI failed: '.$e->getMessage());
        }
    }

    // Manual "Sync to payroll now" button — pushes pending punches to DMPI.
    public function syncAttendances(\App\Sync\AttendanceSync $sync)
    {
        $sync->sync((int) config('payroll.batch_size'));

        return redirect()->route('devices.Attendance')->with('success', 'Pushed pending punches to payroll.');
    }

    // Manually queue enrollment commands for one device (the "Sync enrollments" button).
    public function syncEnrollments(Device $device, \App\Sync\EnrollmentReconciler $reconciler)
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
