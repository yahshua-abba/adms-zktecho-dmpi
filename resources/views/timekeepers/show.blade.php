@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <a href="{{ route('devices.timekeepers') }}" class="small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to timekeeper devices
            </a>
            <h2 class="mb-1 mt-1">{{ $device->name ?: $device->code }}</h2>
            <div class="text-muted small">
                <span><i class="bi bi-cloud-check"></i> payroll device <code>{{ $device->code }}</code></span>
                @if ($device->name)
                    <span class="ms-2">{{ $device->name }}</span>
                @endif
            </div>
        </div>
        @if ($readers->isNotEmpty())
            <div class="d-flex flex-wrap gap-2">
                @foreach ($readers as $reader)
                    <a href="{{ route('devices.people', $reader->id) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-hdd-network"></i> Open physical clock {{ $reader->nama ?: $reader->no_sn }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Says what this page is NOT. The numbers here are payroll's intent; whether
         anyone is actually on a machine is a fact about one physical reader, held
         elsewhere, and a payroll device may have two readers or none. --}}
    <div class="alert alert-light border d-flex gap-2">
        <i class="bi bi-info-circle fs-5 text-muted"></i>
        <div class="small mb-0">
            This is who <strong>payroll assigns</strong> to this device — not who is on a machine.
            @if ($readers->isEmpty())
                No clock here is linked to it, so nobody is being enrolled from this list at all.
                Link one on the <a href="{{ route('devices.index') }}">Devices page</a> to start.
            @elseif ($readers->count() === 1)
                To inspect delivery and punch evidence, use
                <a href="{{ route('devices.people', $readers->first()->id) }}" class="fw-semibold">
                    Open physical clock {{ $readers->first()->nama ?: $readers->first()->no_sn }}
                </a>.
            @else
                {{ $readers->count() }} clocks are linked to it, and each has its own list of who has
                actually been sent — open them above.
            @endif
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">People assigned</div>
                <div class="fs-3 fw-semibold">{{ number_format($summary['assigned']) }}</div>
                <div class="small text-muted">payroll says they belong here</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Eligible for enrollment</div>
                <div class="fs-3 fw-semibold text-success">{{ number_format($summary['enrollable']) }}</div>
                <div class="small text-muted">this server can enrol them</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Can't be enrolled</div>
                <div class="fs-3 fw-semibold {{ $summary['blocked'] ? 'text-danger' : '' }}">{{ number_format($summary['blocked']) }}</div>
                <div class="small text-muted">no employee record, or a PIN conflict</div>
            </div></div>
        </div>
    </div>

    @if ($summary['assigned'] === 0)
        <div class="alert alert-warning d-flex gap-2">
            <i class="bi bi-exclamation-triangle fs-5"></i>
            <div class="small mb-0">
                <strong>Payroll assigns nobody to this device.</strong>
                @if ($readers->isNotEmpty())
                    A clock here is linked to it, so this server will remove every user from that
                    clock and keep it empty. If that clock is a real door people badge at, it is
                    almost certainly linked to the wrong payroll device — check it on the
                    <a href="{{ route('devices.index') }}">Devices page</a>.
                @else
                    Linking a clock to it would empty that clock, so leave it unlinked unless that
                    is what you want.
                @endif
            </div>
        </div>
    @endif

    <div class="filter-bar">
        <form method="GET" action="{{ route('devices.timekeepers.show', ['code' => $device->code]) }}" class="row g-2 align-items-end">
            <input type="hidden" name="per_page" value="{{ $people->perPage() }}">
            <div class="col-sm-6 col-md-5">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="name, PIN, CHAPA, RFID, or payroll #">
            </div>
            <div class="col-sm-6 col-md-4">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="{{ \App\Queries\TimekeeperDirectory::ENROLLABLE }}" @selected($status === \App\Queries\TimekeeperDirectory::ENROLLABLE)>Eligible for enrollment</option>
                    <option value="{{ \App\Queries\TimekeeperDirectory::BLOCKED }}" @selected($status === \App\Queries\TimekeeperDirectory::BLOCKED)>Can't be enrolled</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                @if ($search || $status)
                    <a href="{{ route('devices.timekeepers.show', ['code' => $device->code]) }}" class="btn btn-outline-secondary">Clear</a>
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
                    </tr>
                </thead>
                <tbody>
                    @forelse ($people as $p)
                        @php([$statusLabel, $statusClass] = \App\Queries\TimekeeperDirectory::label($p['status']))
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
                            <td>{{ $p['payroll_employee_id'] }}</td>
                            <td><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-muted text-center py-3">
                                @if ($search || $status)
                                    Nobody assigned to this device matches those filters.
                                @else
                                    Payroll assigns nobody to this device.
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
