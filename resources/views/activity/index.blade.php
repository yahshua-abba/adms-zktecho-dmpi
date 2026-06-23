@extends('layouts.app')

@section('content')
    <h2 class="mb-4">Server Activity</h2>

    <div class="filter-bar">
        <form method="GET" action="{{ route('activity.index') }}" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">Level</label>
                <select name="level" class="form-select" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="info" @selected($level === 'info')>Info</option>
                    <option value="warning" @selected($level === 'warning')>Warning</option>
                    <option value="error" @selected($level === 'error')>Error</option>
                </select>
            </div>
            @if ($level)
                <div class="col-auto"><a href="{{ route('activity.index') }}" class="btn btn-outline-secondary">Clear</a></div>
            @endif
        </form>
    </div>

    <div class="table-card">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Time</th><th>Level</th><th>Event</th><th>Message</th></tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-nowrap small">{{ $log->created_at }}</td>
                        <td>
                            @php $cls = ['info'=>'bg-secondary','warning'=>'bg-warning text-dark','error'=>'bg-danger'][$log->level] ?? 'bg-secondary'; @endphp
                            <span class="badge {{ $cls }}">{{ $log->level }}</span>
                        </td>
                        <td><code>{{ $log->event }}</code></td>
                        <td>{{ $log->message }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted text-center py-3">No activity yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="d-flex justify-content-center">{{ $logs->links() }}</div>
    </div>
@endsection
