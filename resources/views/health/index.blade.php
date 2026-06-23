@extends('layouts.app')

@section('content')
    @php
        $banner = ['ok' => ['bg-success', 'All systems normal'], 'warn' => ['bg-warning text-dark', 'Running with warnings'], 'fail' => ['bg-danger', 'Action needed']][$overall];
        $dot = ['ok' => 'text-success', 'warn' => 'text-warning', 'fail' => 'text-danger'];
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">System Health</h2>
        <div class="d-flex align-items-center gap-3">
            <form action="{{ route('scheduler.start') }}" method="POST" class="mb-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-play-circle"></i> Start scheduler</button>
            </form>
            <span class="small text-muted">auto-refreshes every 30s · {{ now()->format('H:i:s') }}</span>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="alert {{ $banner[0] }} d-flex align-items-center" role="alert">
        <span class="fs-4 me-2">{{ $overall === 'ok' ? '✓' : ($overall === 'warn' ? '!' : '✕') }}</span>
        <strong>{{ $banner[1] }}</strong>
    </div>

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
    // Live monitor: reload the checks every 30 seconds.
    setTimeout(function () { location.reload(); }, 30000);
</script>
@endpush
