@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <a href="{{ route('devices.index') }}" class="small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to devices
            </a>
            <h2 class="mb-1 mt-1">Enrollment status for {{ $device->nama ?: $device->no_sn }}</h2>
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
            <a href="{{ route('devices.queue', $device->id) }}" class="btn btn-outline-warning">
                <i class="bi bi-inbox"></i> Queue
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
    <div class="row row-cols-2 row-cols-lg-5 g-3 mb-4">
        <div class="col">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">ADMS intended list</div>
                <div class="fs-3 fw-semibold">{{ $summary['on_clock'] }}</div>
                <div class="small text-muted">server plan, not device inventory</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Punches received</div>
                <div class="fs-3 fw-semibold {{ $summary['working'] ? 'text-success' : '' }}">{{ $summary['working'] }}</div>
                <div class="small text-muted">strongest proof a PIN works here</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Waiting for clock</div>
                <div class="fs-3 fw-semibold {{ $summary['waiting'] ? 'text-info' : '' }}">{{ $summary['waiting'] }}</div>
                <div class="small text-muted">latest change still on ADMS</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Sent — unconfirmed</div>
                <div class="fs-3 fw-semibold {{ $summary['unconfirmed'] ? 'text-warning' : '' }}">{{ $summary['unconfirmed'] }}</div>
                <div class="small text-muted">clock gave no result</div>
            </div></div>
        </div>
        <div class="col">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Can't be enrolled</div>
                <div class="fs-3 fw-semibold {{ $summary['blocked'] ? 'text-danger' : '' }}">{{ $summary['blocked'] }}</div>
                <div class="small text-muted">missing employee or PIN conflict</div>
            </div></div>
        </div>
    </div>

    <div class="alert alert-light border d-flex flex-wrap align-items-center gap-3">
        <i class="bi bi-info-circle fs-5 text-muted"></i>
        <div class="small mb-0 flex-grow-1">
            <strong>ADMS intended list</strong> is the server's plan, not a live inventory read from the clock.
            A punch received from this serial is the strongest evidence that a person's PIN works here.
            @if ($summary['waiting'] || $summary['unconfirmed'])
                <br><strong>{{ number_format($summary['waiting']) }} waiting for the clock</strong> and
                <strong>{{ number_format($summary['unconfirmed']) }} sent but unconfirmed</strong>.
            @else
                There are no active enrollment commands for this device.
            @endif
            @unless ($device->payroll_device_code)
                <br>This clock isn't linked to a payroll device, so nobody is assigned to it and its user list isn't being managed. Link it on the Devices page to start syncing.
            @endunless
        </div>
        <a href="{{ route('devices.queue', $device->id) }}" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-inbox"></i> Open queue
        </a>
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
                    <option value="{{ \App\Queries\DeviceRoster::WORKING }}" @selected($status === \App\Queries\DeviceRoster::WORKING)>Punch received here</option>
                    <option value="{{ \App\Queries\DeviceRoster::WAITING }}" @selected($status === \App\Queries\DeviceRoster::WAITING)>Waiting for clock</option>
                    <option value="{{ \App\Queries\DeviceRoster::UNCONFIRMED }}" @selected($status === \App\Queries\DeviceRoster::UNCONFIRMED)>Sent — unconfirmed</option>
                    <option value="{{ \App\Queries\DeviceRoster::ON_CLOCK }}" @selected($status === \App\Queries\DeviceRoster::ON_CLOCK)>Recorded by ADMS</option>
                    <option value="{{ \App\Queries\DeviceRoster::ADDING }}" @selected($status === \App\Queries\DeviceRoster::ADDING)>Not recorded by ADMS</option>
                    <option value="{{ \App\Queries\DeviceRoster::REMOVING }}" @selected($status === \App\Queries\DeviceRoster::REMOVING)>No longer assigned</option>
                    <option value="{{ \App\Queries\DeviceRoster::BLOCKED }}" @selected($status === \App\Queries\DeviceRoster::BLOCKED)>Can't be enrolled</option>
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
                                @if ($p['punch_count'])
                                    <span class="badge bg-success">Punch received here</span>
                                @elseif ($p['status'] === \App\Queries\DeviceRoster::BLOCKED)
                                    <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
                                @else
                                    <span class="badge bg-secondary">No punch received here</span>
                                @endif
                                @unless ($p['status'] === \App\Queries\DeviceRoster::BLOCKED)
                                    <div class="small text-muted">{{ $statusLabel }}</div>
                                @endunless
                                @if (($p['command']['delivery'] ?? null) === 'pending')
                                    <div class="small text-info-emphasis"><i class="bi bi-hourglass-split"></i>
                                        {{ $p['command']['action'] === 'add' ? 'add/update waiting for clock' : 'removal waiting for clock' }}
                                    </div>
                                @elseif (($p['command']['delivery'] ?? null) === 'sent')
                                    <div class="small text-warning-emphasis"><i class="bi bi-send"></i>
                                        {{ $p['command']['action'] === 'add' ? 'add/update sent — unconfirmed' : 'removal sent — unconfirmed' }}
                                    </div>
                                @elseif ($p['sent_at'])
                                    <div class="small text-muted">ADMS recorded {{ $p['sent_at']->diffForHumans() }}</div>
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
                                    ADMS has no intended roster for this clock yet. Use <strong>Download assignments</strong> on the Devices page to pull payroll's list, then <strong>Sync enrollments</strong>.
                                @else
                                    ADMS has no intended roster for this clock, and it isn't linked to a payroll device yet.
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
