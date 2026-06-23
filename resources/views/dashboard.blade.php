@extends('layouts.app')

@section('content')
    <h2 class="mb-4">Dashboard</h2>

    <div class="row g-3">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-value text-success">{{ $stats['devices_online'] }}</div>
                    <div class="stat-label">Devices online</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <a href="{{ route('devices.index') }}" class="text-decoration-none">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-value {{ $stats['devices_offline'] ? 'text-danger' : 'text-muted' }}">{{ $stats['devices_offline'] }}</div>
                        <div class="stat-label">Devices offline</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <a href="{{ route('devices.Attendance') }}" class="text-decoration-none">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-value text-dark">{{ $stats['punches_today'] }}</div>
                        <div class="stat-label">Punches today</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <a href="{{ route('devices.Attendance', ['sync' => 'pending']) }}" class="text-decoration-none">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-value text-warning">{{ $stats['pending_count'] }}</div>
                        <div class="stat-label">Pending sync</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <a href="{{ route('devices.Attendance', ['sync' => 'failed']) }}" class="text-decoration-none">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-value {{ $stats['failed_count'] ? 'text-danger' : 'text-muted' }}">{{ $stats['failed_count'] }}</div>
                        <div class="stat-label">Failed sync</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <a href="{{ route('employees.index') }}" class="text-decoration-none">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="stat-value {{ $stats['unmapped_count'] ? 'text-danger' : 'text-muted' }}">{{ $stats['unmapped_count'] }}</div>
                        <div class="stat-label">Unmapped PINs</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    @if ($stats['failed_count'] || $stats['unmapped_count'])
        <div class="alert alert-warning mt-4 mb-0">
            <i class="bi bi-exclamation-triangle"></i>
            @if ($stats['failed_count'])
                <strong>{{ $stats['failed_count'] }}</strong> punch(es) failed to sync to payroll.
            @endif
            @if ($stats['unmapped_count'])
                <strong>{{ $stats['unmapped_count'] }}</strong> device PIN(s) are tapping but not mapped to an employee.
            @endif
        </div>
    @endif
@endsection
