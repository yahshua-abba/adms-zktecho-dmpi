@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <a href="{{ route('devices.index') }}" class="small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to devices
            </a>
            <h2 class="mb-1 mt-1">People recorded for {{ $device->nama ?: $device->no_sn }}</h2>
            <div class="text-muted small">
                <span class="badge {{ $device->isOnline() ? 'bg-success' : 'bg-secondary' }}">● {{ $device->isOnline() ? 'Online' : 'Offline' }}</span>
                <span class="ms-2"><i class="bi bi-hdd-network"></i> {{ $device->no_sn }}</span>
                @if ($device->lokasi)
                    <span class="ms-2"><i class="bi bi-geo-alt"></i> {{ $device->lokasi }}</span>
                @endif
                @if ($device->payroll_device_code)
                    <span class="ms-2"><i class="bi bi-cloud-check"></i>
                        payroll device {{ $device->payroll_device_code }}{{ $payrollDevice?->name ? ' — '.$payrollDevice->name : '' }}
                    </span>
                @endif
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('devices.Attendance', ['device' => $device->no_sn]) }}" class="btn btn-outline-secondary">
                <i class="bi bi-list-ul"></i> Punches
            </a>
            <form action="{{ route('devices.syncEnrollments', $device->id) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-outline-success" title="Re-send all currently assigned users and queue any needed removals">
                    <i class="bi bi-arrow-repeat"></i> Sync enrollments
                </button>
            </form>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ─── Summary ───
         Counts over the whole device, not the filtered page: these are the
         numbers someone came here to read, and a search box shouldn't quietly
         change what "5 can't be added" means. --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Recorded for clock</div>
                <div class="fs-3 fw-semibold">{{ $summary['on_clock'] }}</div>
                <div class="small text-muted">ADMS intended list</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Waiting to be added</div>
                <div class="fs-3 fw-semibold {{ $summary['adding'] ? 'text-info' : '' }}">{{ $summary['adding'] }}</div>
                <div class="small text-muted">assigned, not sent yet</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Waiting to be removed</div>
                <div class="fs-3 fw-semibold {{ $summary['removing'] ? 'text-warning' : '' }}">{{ $summary['removing'] }}</div>
                <div class="small text-muted">no longer assigned</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Can't be added</div>
                <div class="fs-3 fw-semibold {{ $summary['blocked'] ? 'text-danger' : '' }}">{{ $summary['blocked'] }}</div>
                <div class="small text-muted">no employee record here</div>
            </div></div>
        </div>
    </div>

    {{-- The honest caveat. device_enrollment records ADMS's intended state, not a
         physical inventory read back from the clock. --}}
    <div class="alert alert-light border d-flex gap-2">
        <i class="bi bi-info-circle fs-5 text-muted"></i>
        <div class="small mb-0">
            <strong>Recorded for clock</strong> means ADMS queued or sent the person to the device;
            it does not prove the clock applied the command.
            {{-- "Unconfirmed", not "queued": the count covers changes still in the
                 mailbox AND ones already handed over that the clock never reported
                 back on. Only the first are waiting for a connection, so promising
                 that all of them are lands an operator on the queue screen looking
                 for something to cancel that isn't there. --}}
            @if ($summary['queued'])
                <a href="{{ route('devices.queue', $device->id) }}" class="text-decoration-none">
                    <strong class="text-warning-emphasis">{{ $summary['queued'] }} person/people have a change the clock hasn't confirmed</strong></a> —
                either still waiting to be collected, or already sent with no result reported back.
                Open the queue to see which.
            @else
                Nothing is queued for this device right now.
            @endif
            @unless ($device->payroll_device_code)
                <br>This clock isn't linked to a payroll device, so nobody is assigned to it and its user list isn't being managed. Link it on the Devices page to start syncing.
            @endunless
        </div>
    </div>

    <div class="filter-bar">
        <form method="GET" action="{{ route('devices.people', $device->id) }}" class="row g-2 align-items-end">
            <input type="hidden" name="per_page" value="{{ $people->perPage() }}">
            <div class="col-sm-6 col-md-5">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="name, PIN, CHAPA, RFID, or payroll #">
            </div>
            <div class="col-sm-6 col-md-4">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="{{ \App\Queries\DeviceRoster::ON_CLOCK }}" @selected($status === \App\Queries\DeviceRoster::ON_CLOCK)>Recorded for clock</option>
                    <option value="{{ \App\Queries\DeviceRoster::ADDING }}" @selected($status === \App\Queries\DeviceRoster::ADDING)>Waiting to be added</option>
                    <option value="{{ \App\Queries\DeviceRoster::REMOVING }}" @selected($status === \App\Queries\DeviceRoster::REMOVING)>Waiting to be removed</option>
                    <option value="{{ \App\Queries\DeviceRoster::BLOCKED }}" @selected($status === \App\Queries\DeviceRoster::BLOCKED)>Can't be added</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                @if ($search || $status)
                    <a href="{{ route('devices.people', $device->id) }}" class="btn btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="d-flex justify-content-end mb-3">
            @include('partials.per-page-select', ['paginator' => $people, 'param' => 'per_page', 'pageParam' => 'page'])
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Device PIN</th>
                        <th>Company</th>
                        <th>CHAPA No.</th>
                        <th>RFID Card</th>
                        <th>Payroll ID</th>
                        <th>Status</th>
                        <th>Punches here</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($people as $p)
                        @php([$statusLabel, $statusClass] = \App\Queries\DeviceRoster::label($p['status']))
                        <tr>
                            <td>
                                {{ $p['name'] ?: '—' }}
                                @if ($p['reason'])
                                    <div class="small text-danger">{{ $p['reason'] }}</div>
                                @endif
                            </td>
                            <td>@if ($p['pin'])<code>{{ $p['pin'] }}</code>@else<span class="text-muted">—</span>@endif</td>
                            <td>{{ $p['company'] ?: '—' }}</td>
                            <td>{{ $p['chapa'] ?: '—' }}</td>
                            <td>@if ($p['rfid'])<code>{{ $p['rfid'] }}</code>@else<span class="text-muted">—</span>@endif</td>
                            <td>{{ $p['payroll_employee_id'] ?: '—' }}</td>
                            <td>
                                <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
                                @if ($p['queued'] === 'add')
                                    <div class="small text-muted"><i class="bi bi-hourglass-split"></i> add queued, not delivered</div>
                                @elseif ($p['queued'] === 'remove')
                                    <div class="small text-muted"><i class="bi bi-hourglass-split"></i> removal queued, not delivered</div>
                                @elseif ($p['sent_at'])
                                    <div class="small text-muted">sent {{ $p['sent_at']->diffForHumans() }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($p['punch_count'])
                                    <a href="{{ route('devices.Attendance', ['device' => $device->no_sn, 'employee' => $p['pin']]) }}"
                                       class="text-decoration-none">{{ $p['punch_count'] }}</a>
                                    <div class="small text-muted">last {{ $p['last_punch_at'] }}</div>
                                @else
                                    <span class="text-muted">never</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-muted text-center py-3">
                                @if ($search || $status)
                                    Nobody on this clock matches those filters.
                                @elseif ($device->payroll_device_code)
                                    Nobody is recorded for this clock yet. Use <strong>Download assignments</strong> on the Devices page to pull payroll's list, then <strong>Sync enrollments</strong>.
                                @else
                                    Nobody is recorded for this clock, and it isn't linked to a payroll device yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.pagination-footer', ['paginator' => $people])
    </div>
@endsection
