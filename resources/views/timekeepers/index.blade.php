@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <a href="{{ route('devices.index') }}" class="small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to devices
            </a>
            <h2 class="mb-1 mt-1">Timekeeper devices in payroll</h2>
            <div class="text-muted small">
                Payroll's own list of time clocks and who it puts on each one. Look inside a
                device here to see exactly who would be enrolled — before you link one of your
                readers to it.
            </div>
        </div>
    </div>

    {{-- Everything on this screen was downloaded earlier; nothing here calls DMPI.
         Worth saying plainly, because a stale list looks identical to a current one
         and the numbers below are only as fresh as the last download. --}}
    <div class="alert alert-light border d-flex gap-2">
        <i class="bi bi-info-circle fs-5 text-muted"></i>
        <div class="small mb-0">
            This is payroll's list as of the last download, read from this server — nothing here
            contacts DMPI. Use <strong>Download devices</strong> and <strong>Download assignments</strong>
            on the <a href="{{ route('devices.index') }}">Devices page</a> to refresh it.
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Devices in payroll</div>
                <div class="fs-3 fw-semibold">{{ $summary['devices'] }}</div>
                <div class="small text-muted">downloaded from DMPI</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Linked to a clock here</div>
                <div class="fs-3 fw-semibold">{{ $summary['linked'] }}</div>
                <div class="small text-muted">the rest aren't managed</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">People assigned</div>
                <div class="fs-3 fw-semibold">{{ number_format($summary['assignments']) }}</div>
                <div class="small text-muted">across all devices</div>
            </div></div>
        </div>
        {{-- An empty device is the trap this screen exists to expose: link a live
             reader to one and every user on that reader is queued for deletion. --}}
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Nobody assigned</div>
                <div class="fs-3 fw-semibold {{ $summary['empty'] ? 'text-warning' : '' }}">{{ $summary['empty'] }}</div>
                <div class="small text-muted">linking a clock here empties it</div>
            </div></div>
        </div>
    </div>

    <div class="filter-bar">
        <form method="GET" action="{{ route('devices.timekeepers') }}" class="row g-2 align-items-end">
            <input type="hidden" name="per_page" value="{{ $devices->perPage() }}">
            <div class="col-sm-6 col-md-5">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="device code or location">
            </div>
            <div class="col-sm-6 col-md-4">
                <label class="form-label small mb-1">Show</label>
                <select name="filter" class="form-select" onchange="this.form.submit()">
                    <option value="">All devices</option>
                    @foreach (\App\Queries\TimekeeperDirectory::FILTERS as $value => $labelText)
                        <option value="{{ $value }}" @selected($filter === $value)>{{ $labelText }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                @if ($search || $filter)
                    <a href="{{ route('devices.timekeepers') }}" class="btn btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="d-flex justify-content-end mb-3">
            @include('partials.per-page-select', ['paginator' => $devices, 'param' => 'per_page', 'pageParam' => 'page'])
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Device code</th>
                        <th>Location</th>
                        <th>People assigned</th>
                        <th>Can't be added</th>
                        <th>Your clock</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($devices as $d)
                        <tr>
                            <td><code>{{ $d->code }}</code></td>
                            <td>{{ $d->name ?: '—' }}</td>
                            <td>
                                @if ($d->assigned)
                                    <span class="fw-semibold">{{ number_format($d->assigned) }}</span>
                                @else
                                    {{-- Flagged rather than shown as a plain zero: it reads as
                                         "nothing to see" but behaves as "empty this clock". --}}
                                    <span class="badge bg-warning text-dark">nobody</span>
                                @endif
                            </td>
                            <td>
                                @if ($d->blocked)
                                    <span class="text-danger fw-semibold">{{ number_format($d->blocked) }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @forelse ($d->readers as $reader)
                                    <a href="{{ route('devices.people', $reader->id) }}"
                                       class="badge bg-light text-dark border text-decoration-none">
                                        <i class="bi bi-hdd-network"></i>
                                        {{ $reader->nama ?: $reader->no_sn }}
                                    </a>
                                @empty
                                    <span class="text-muted">not linked</span>
                                @endforelse
                            </td>
                            <td class="text-end">
                                <a href="{{ route('devices.timekeepers.show', ['code' => $d->code]) }}"
                                   class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-people"></i> View people
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted text-center py-3">
                                @if ($search || $filter)
                                    No payroll device matches those filters.
                                @else
                                    Nothing downloaded yet. Use <strong>Download devices</strong> on the
                                    <a href="{{ route('devices.index') }}">Devices page</a> to pull payroll's list.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.pagination-footer', ['paginator' => $devices])
    </div>
@endsection
