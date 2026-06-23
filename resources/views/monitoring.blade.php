@extends('layouts.app')

@section('content')
    @php
        $banner = ['ok' => ['bg-success', 'All systems normal'], 'warn' => ['bg-warning text-dark', 'Running with warnings'], 'fail' => ['bg-danger', 'Action needed']][$overall];
        $dot = ['ok' => 'text-success', 'warn' => 'text-warning', 'fail' => 'text-danger'];
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Monitoring</h2>
        <div class="d-flex align-items-center gap-3">
            <form action="{{ route('scheduler.start') }}" method="POST" class="mb-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-play-circle"></i> Start scheduler</button>
            </form>
            <span class="small text-muted d-none d-md-inline">auto-refreshes every 30s · {{ now()->format('H:i:s') }}</span>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- Overall status banner --}}
    <div class="alert {{ $banner[0] }} d-flex align-items-center" role="alert">
        <span class="fs-4 me-2">{{ $overall === 'ok' ? '✓' : ($overall === 'warn' ? '!' : '✕') }}</span>
        <strong>{{ $banner[1] }}</strong>
    </div>

    {{-- At-a-glance operational stats --}}
    <h6 class="text-muted text-uppercase small mt-4 mb-2">At a glance</h6>
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
        <div class="alert alert-warning mt-3 mb-0">
            <i class="bi bi-exclamation-triangle"></i>
            @if ($stats['failed_count'])
                <strong>{{ $stats['failed_count'] }}</strong> punch(es) failed to sync to payroll.
            @endif
            @if ($stats['unmapped_count'])
                <strong>{{ $stats['unmapped_count'] }}</strong> device PIN(s) are tapping but not mapped to an employee.
            @endif
        </div>
    @endif

    {{-- System health checks --}}
    <h6 class="text-muted text-uppercase small mt-4 mb-2">System health</h6>
    <div class="row g-3">
        @foreach ($checks as $c)
            <div class="col-md-6 col-lg-4">
                @if (!empty($c['link']))
                    <a href="{{ $c['link'] }}" class="card stat-card h-100 text-decoration-none text-reset" style="transition:box-shadow .15s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(16,24,40,.15)'" onmouseout="this.style.boxShadow=''">
                @else
                    <div class="card stat-card h-100">
                @endif
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-1">
                            <span class="{{ $dot[$c['status']] }} me-2" style="font-size:1.2rem;">●</span>
                            <strong>{{ $c['label'] }}</strong>
                            <span class="badge ms-auto {{ ['ok'=>'bg-success','warn'=>'bg-warning text-dark','fail'=>'bg-danger'][$c['status']] }}">{{ $c['status'] }}</span>
                        </div>
                        <div class="small text-muted">{{ $c['detail'] }} @if(!empty($c['link']))<span class="text-primary">›</span>@endif</div>
                    </div>
                @if (!empty($c['link']))
                    </a>
                @else
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endsection

@push('scripts')
<script>
    // Live monitor: reload stats + checks every 30 seconds.
    setTimeout(function () { location.reload(); }, 30000);
</script>
@endpush
