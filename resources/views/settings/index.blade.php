@extends('layouts.app')

@section('content')
    <h2 class="mb-4">Settings</h2>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card border-danger mb-4">
        <div class="card-header bg-danger-subtle border-danger">
            <strong class="text-danger-emphasis"><i class="bi bi-exclamation-octagon"></i> Danger zone</strong>
        </div>
        <div class="card-body">
            <h5 class="card-title">Clear the database</h5>
            <p class="text-muted">
                Empties this server completely and rebuilds the tables from scratch. There is no undo.
                A full backup is taken automatically first, and the clear is abandoned if that backup fails.
            </p>

            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>What you'd lose</th><th class="text-end">Rows</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong>Attendance punches</strong>
                                @if ($counts['unsynced'])
                                    <span class="badge bg-danger ms-1">{{ $counts['unsynced'] }} never sent to payroll</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($counts['attendances']) }}</td>
                        </tr>
                        <tr>
                            <td>
                                Devices
                                @if ($counts['devicesWithDirection'])
                                    <span class="badge bg-warning text-dark ms-1">{{ $counts['devicesWithDirection'] }} with an IN/OUT direction set</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($counts['devices']) }}</td>
                        </tr>
                        <tr><td>Employees</td><td class="text-end">{{ number_format($counts['employees']) }}</td></tr>
                        <tr><td>PIN conflict decisions</td><td class="text-end">{{ number_format($counts['conflicts']) }}</td></tr>
                        <tr><td>Payroll devices</td><td class="text-end">{{ number_format($counts['payrollDevices']) }}</td></tr>
                        <tr><td>Device assignments</td><td class="text-end">{{ number_format($counts['assignments']) }}</td></tr>
                    </tbody>
                </table>
            </div>

            @if ($counts['unsynced'])
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>{{ $counts['unsynced'] }} punch(es) have never reached payroll.</strong>
                    A synced punch also exists in DMPI; these exist <em>only here</em>. Clearing destroys them for good,
                    and somebody's hours go with them. Push them to payroll first if you can.
                </div>
            @endif

            @if ($counts['devicesWithDirection'])
                <div class="alert alert-warning">
                    <i class="bi bi-signpost-split"></i>
                    <strong>{{ $counts['devicesWithDirection'] }} device(s) have an IN/OUT direction set.</strong>
                    Clocks re-register themselves when they next check in, but their direction is stored only here —
                    not in DMPI. You will have to set each one again by hand, and until you do, their punches can't sync.
                </div>
            @endif

            <div class="alert alert-light border small">
                <i class="bi bi-shield-check"></i>
                What survives: your login, your <code>.env</code> settings, and the automatic backup written to
                <code>storage/app/backups</code>. Employees and assignments can be downloaded again from DMPI;
                punches and device directions cannot.
            </div>

            @if ($running)
                <div class="alert alert-info mb-0">
                    <i class="bi bi-hourglass-split"></i>
                    <strong>{{ $running->describe() }}</strong> is running right now. Clearing is blocked until it finishes
                    or is stopped — it would be writing into tables as they were dropped.
                </div>
            @else
                <form method="POST" action="{{ route('settings.clear-database') }}" class="row g-3"
                      onsubmit="return confirm('Last check: this permanently deletes everything on this server. Continue?');">
                    @csrf
                    <div class="col-md-5">
                        <label for="password" class="form-label">Your password</label>
                        <input type="password" name="password" id="password" autocomplete="current-password"
                               class="form-control @error('password') is-invalid @enderror" required>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Being logged in isn't enough for this one.</div>
                    </div>
                    <div class="col-md-5">
                        <label for="confirmation" class="form-label">Type <code>confirm</code></label>
                        <input type="text" name="confirmation" id="confirmation" autocomplete="off"
                               placeholder="confirm"
                               class="form-control @error('confirmation') is-invalid @enderror" required>
                        @error('confirmation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">So this can't happen by a stray click.</div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-danger w-100"><i class="bi bi-trash3"></i> Clear</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection
