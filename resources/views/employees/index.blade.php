@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Employees</h2>
        <form action="{{ route('dmpi.sync') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-success"><i class="bi bi-cloud-download"></i> Sync from DMPI</button>
        </form>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="mapped-tab" data-bs-toggle="tab" data-bs-target="#tab-mapped" type="button" role="tab">
                <i class="bi bi-person-check"></i> Mapped
                <span class="badge bg-secondary ms-1">{{ $mapped->count() }}</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="unmapped-tab" data-bs-toggle="tab" data-bs-target="#tab-unmapped" type="button" role="tab">
                <i class="bi bi-person-exclamation"></i> Unmapped PINs
                <span class="badge {{ $unmapped->count() ? 'bg-danger' : 'bg-secondary' }} ms-1">{{ $unmapped->count() }}</span>
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- ─── Mapped roster ─── --}}
        <div class="tab-pane fade show active" id="tab-mapped" role="tabpanel">
            <div class="filter-bar">
                <form method="GET" action="{{ route('employees.index') }}" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-md-5">
                        <label class="form-label small mb-1">Search (any column)</label>
                        <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="name, CHAPA, PIN, RFID, payroll #, or device serial">
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <label class="form-label small mb-1">Enrolled device</label>
                        <select name="device" class="form-select" onchange="this.form.submit()">
                            <option value="">All devices</option>
                            @foreach ($devices as $d)
                                <option value="{{ $d->no_sn }}" @selected($device === $d->no_sn)>
                                    {{ $d->nama ? $d->nama.' — ' : '' }}{{ $d->no_sn }}{{ $d->payroll_device_code ? ' ('.$d->payroll_device_code.')' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                        @if ($search || $device)
                            <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">Clear</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="table-card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr><th>Name</th><th>Company</th><th>CHAPA No.</th><th>Device PIN</th><th>RFID Card</th><th>Payroll ID</th><th>Enrolled devices</th><th>Last punch</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($mapped as $e)
                                <tr>
                                    <td>{{ $e->name ?: '—' }}</td>
                                    <td>{{ $e->company }}</td>
                                    <td>{{ $e->chapa }}</td>
                                    <td><code>{{ $e->device_pin }}</code></td>
                                    <td>@if ($e->rfid)<code>{{ $e->rfid }}</code>@else<span class="text-muted">—</span>@endif</td>
                                    <td>{{ $e->payroll_employee_id }}</td>
                                    <td>
                                        @forelse ($e->devices as $d)
                                            @if ($d['serial'])
                                                <span class="badge bg-light text-dark border" title="{{ $d['name'] ? $d['name'].' · ' : '' }}payroll: {{ $d['code'] }}">
                                                    <i class="bi bi-hdd-network"></i> {{ $d['serial'] }}
                                                </span>
                                            @else
                                                <span class="badge bg-warning-subtle text-dark border" title="payroll device — no physical reader linked">{{ $d['code'] }}</span>
                                            @endif
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                    </td>
                                    <td>{{ $e->last_punch_at ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-muted text-center py-3">No mapped employees found. Try clearing the filter, or use <strong>Sync from DMPI</strong> to pull the roster.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ─── Unmapped device PINs ─── --}}
        <div class="tab-pane fade" id="tab-unmapped" role="tabpanel">
            <div class="table-card">
                @if ($unmapped->count())
                    <div class="alert alert-warning small mb-3">
                        <i class="bi bi-exclamation-triangle"></i>
                        These PINs are tapping on devices but aren't matched to any employee yet, so their punches <strong>won't sync to payroll</strong>. Fix by enrolling each device user with PIN = <code>{company}_{CHAPA}</code>, or by syncing the roster from DMPI.
                    </div>
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle"></i>
                        Click <strong>Look up device user</strong> to ask each source reader for the name and RFID/card assigned to that PIN. The lookup is delivered when the device checks in; refresh this page afterward.
                    </div>
                @endif
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr><th>Device PIN</th><th>Source device(s)</th><th>Device user / card</th><th>Punches</th><th>Last seen</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($unmapped as $u)
                                <tr>
                                    <td><span class="badge bg-warning text-dark">{{ $u->employee_id }}</span></td>
                                    <td>
                                        @forelse ($u->source_devices as $source)
                                            <span class="badge bg-light text-dark border">
                                                <i class="bi bi-hdd-network"></i>
                                                <code>{{ $source['serial'] }}</code>
                                                @if ($source['name'])
                                                    <span class="text-muted">— {{ $source['name'] }}</span>
                                                @endif
                                            </span>
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        @foreach ($u->device_users as $user)
                                            <div class="small mb-1">
                                                <div><code>{{ $user['serial'] }}</code></div>
                                                @if ($user['user_name'])
                                                    <strong>{{ $user['user_name'] }}</strong>
                                                @else
                                                    <span class="text-muted">Not looked up yet</span>
                                                @endif
                                                @if ($user['card'])
                                                    <div><code>RFID/card: {{ $user['card'] }}</code></div>
                                                @endif
                                            </div>
                                        @endforeach
                                        @if (count($u->known_cards))
                                            <div class="small text-muted">Previously known card(s):
                                                @foreach ($u->known_cards as $card)
                                                    <code>{{ $card }}</code>@if (! $loop->last), @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td>{{ $u->punch_count }}</td>
                                    <td>{{ $u->last_punch_at }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('employees.unmapped.lookup', ['pin' => $u->employee_id]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Ask the source device(s) for this user's name and card">
                                                <i class="bi bi-search"></i> Look up device user
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted text-center py-3">No unmapped PINs — every tapping device user is matched to an employee. 🎉</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
