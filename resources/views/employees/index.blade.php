@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h2 class="mb-0">Employees</h2>
        <div class="d-flex flex-wrap gap-2">
            {{-- The roster download lives here, where the roster is shown. The clock
                 list and the assignments come from the same DMPI call but are shown
                 on Devices, so their buttons live there too. --}}
            <form action="{{ route('dmpi.sync', 'employees') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-success" title="Pull the employee roster from DMPI. Usually about 30 seconds.">
                    <i class="bi bi-people-fill"></i> Download employees
                </button>
            </form>
        </div>
    </div>

    @include('partials.sync-progress')

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $tab === 'mapped' ? 'active' : '' }}" id="mapped-tab" data-bs-toggle="tab" data-bs-target="#tab-mapped" type="button" role="tab">
                <i class="bi bi-person-check"></i> Mapped
                <span class="badge bg-secondary ms-1">{{ $mapped->total() }}</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $tab === 'unmapped' ? 'active' : '' }}" id="unmapped-tab" data-bs-toggle="tab" data-bs-target="#tab-unmapped" type="button" role="tab">
                <i class="bi bi-person-exclamation"></i> Unmapped PINs
                <span class="badge {{ $unmapped->total() ? 'bg-danger' : 'bg-secondary' }} ms-1">{{ $unmapped->total() }}</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $tab === 'conflicts' ? 'active' : '' }}" id="conflicts-tab" data-bs-toggle="tab" data-bs-target="#tab-conflicts" type="button" role="tab">
                <i class="bi bi-people"></i> PIN conflicts
                <span class="badge {{ $unresolvedConflicts ? 'bg-danger' : 'bg-secondary' }} ms-1">{{ $unresolvedConflicts }}</span>
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- ─── Mapped roster ─── --}}
        <div class="tab-pane fade {{ $tab === 'mapped' ? 'show active' : '' }}" id="tab-mapped" role="tabpanel">
            <div class="filter-bar">
                <form method="GET" action="{{ route('employees.index') }}" class="row g-2 align-items-end">
                    {{-- A GET submit rebuilds the query string from this form alone, so
                         carry the two tables' page sizes and the active tab through it —
                         otherwise searching silently resets both tables to the default
                         page size and drops you back on the mapped tab. --}}
                    <input type="hidden" name="tab" value="mapped">
                    <input type="hidden" name="mapped_per_page" value="{{ $mapped->perPage() }}">
                    <input type="hidden" name="unmapped_per_page" value="{{ $unmapped->perPage() }}">
                    <input type="hidden" name="conflicts_per_page" value="{{ $conflicts->perPage() }}">
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
                <div class="d-flex justify-content-end mb-3">
                    @include('partials.per-page-select', ['paginator' => $mapped, 'param' => 'mapped_per_page', 'pageParam' => 'mapped_page', 'extra' => ['tab' => 'mapped']])
                </div>
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
                                <tr><td colspan="8" class="text-muted text-center py-3">No mapped employees found. Try clearing the filter, or use <strong>Download employees</strong> to pull the roster.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @include('partials.pagination-footer', ['paginator' => $mapped])
            </div>
        </div>

        {{-- ─── Unmapped device PINs ─── --}}
        <div class="tab-pane fade {{ $tab === 'unmapped' ? 'show active' : '' }}" id="tab-unmapped" role="tabpanel">
            <div class="table-card">
                @if ($unmapped->total())
                    <div class="alert alert-warning small mb-3">
                        <i class="bi bi-exclamation-triangle"></i>
                        These PINs are tapping on devices but aren't matched to any employee yet, so their punches <strong>won't sync to payroll</strong>. Fix by enrolling each device user with PIN = <code>{company}_{CHAPA}</code>, or by syncing the roster from DMPI.
                    </div>
                @endif
                <div class="d-flex justify-content-end mb-3">
                    @include('partials.per-page-select', ['paginator' => $unmapped, 'param' => 'unmapped_per_page', 'pageParam' => 'unmapped_page', 'extra' => ['tab' => 'unmapped']])
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr><th>Device PIN</th><th>Punches</th><th>Last seen</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($unmapped as $u)
                                <tr>
                                    <td><span class="badge bg-warning text-dark">{{ $u->employee_id }}</span></td>
                                    <td>{{ $u->punch_count }}</td>
                                    <td>{{ $u->last_punch_at }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center py-3">No unmapped PINs — every tapping device user is matched to an employee. 🎉</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @include('partials.pagination-footer', ['paginator' => $unmapped])
            </div>
        </div>

        {{-- ─── Contested device PINs ─── --}}
        <div class="tab-pane fade {{ $tab === 'conflicts' ? 'show active' : '' }}" id="tab-conflicts" role="tabpanel">
            <div class="table-card">
                @if ($unresolvedConflicts)
                    <div class="alert alert-danger small mb-3">
                        <i class="bi bi-exclamation-octagon"></i>
                        These device PINs are claimed by <strong>more than one payroll employee</strong> in DMPI. A punch only carries
                        the PIN, so there's no way to tell the claimants apart — the PIN is left unmapped and its punches
                        <strong>won't sync</strong> rather than being filed against the wrong person.
                        Pick the owner below and the waiting punches go through on the next run. The real fix is to remove the
                        duplicate in DMPI; once that's done these entries disappear on their own.
                    </div>
                @endif
                <div class="d-flex justify-content-end mb-3">
                    @include('partials.per-page-select', ['paginator' => $conflicts, 'param' => 'conflicts_per_page', 'pageParam' => 'conflicts_page', 'extra' => ['tab' => 'conflicts']])
                </div>

                @forelse ($conflicts as $c)
                    @php $owner = $c->resolvedClaimant(); @endphp
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <code class="fs-6">{{ $c->device_pin }}</code>
                                <span class="text-muted small ms-2">claimed by {{ count($c->claimants) }} employees</span>
                                @if ($c->stuck_punches)
                                    <span class="badge bg-warning text-dark ms-2" title="unsynced punches waiting on this PIN">
                                        <i class="bi bi-hourglass-split"></i> {{ $c->stuck_punches }} punch(es) waiting
                                    </span>
                                @endif
                            </div>
                            <div>
                                @if ($owner)
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle"></i>
                                        Assigned to {{ $owner['name'] ?: 'payroll #'.$owner['payroll_employee_id'] }}
                                    </span>
                                @else
                                    <span class="badge bg-danger"><i class="bi bi-question-circle"></i> Undecided — not syncing</span>
                                @endif
                            </div>
                        </div>

                        <form method="POST" action="{{ route('employees.conflicts.resolve', $c) }}" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-12">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-2">
                                        <thead>
                                            <tr><th style="width:3rem"></th><th>Name</th><th>Payroll ID</th><th>Company</th><th>CHAPA No.</th><th>RFID Card</th></tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($c->claimants as $claimant)
                                                <tr>
                                                    <td>
                                                        <input class="form-check-input" type="radio"
                                                               name="payroll_employee_id"
                                                               id="pin-{{ $c->id }}-{{ $claimant['payroll_employee_id'] }}"
                                                               value="{{ $claimant['payroll_employee_id'] }}"
                                                               @checked($owner && $owner['payroll_employee_id'] === $claimant['payroll_employee_id'])
                                                               required>
                                                    </td>
                                                    <td><label class="form-check-label" for="pin-{{ $c->id }}-{{ $claimant['payroll_employee_id'] }}">{{ $claimant['name'] ?: '—' }}</label></td>
                                                    <td>{{ $claimant['payroll_employee_id'] }}</td>
                                                    <td>{{ $claimant['company'] }}</td>
                                                    <td>{{ $claimant['chapa'] }}</td>
                                                    <td>@if (!empty($claimant['rfid']))<code>{{ $claimant['rfid'] }}</code>@else<span class="text-muted">—</span>@endif</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-primary btn-sm">
                                    <i class="bi bi-person-check"></i> {{ $owner ? 'Change owner' : 'Assign this PIN' }}
                                </button>
                            </div>
                        </form>

                        @if ($owner)
                            <form method="POST" action="{{ route('employees.conflicts.clear', $c) }}" class="mt-2">
                                @csrf
                                <button class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-arrow-counterclockwise"></i> Withdraw decision
                                </button>
                                <span class="text-muted small ms-2">Unmaps the PIN again and stops its punches syncing.</span>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-muted text-center py-3 mb-0">No PIN conflicts — every device PIN belongs to exactly one payroll employee. 🎉</p>
                @endforelse

                @include('partials.pagination-footer', ['paginator' => $conflicts])
            </div>
        </div>
    </div>
@endsection
