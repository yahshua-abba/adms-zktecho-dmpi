@extends('layouts.app')

@section('content')
    <h2 class="mb-4">DMPI Calls</h2>

    @include('partials.sync-progress')

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="alert alert-light border small">
        <i class="bi bi-info-circle"></i>
        Every request this server makes to DMPI, newest first — what was asked for, how long it took, and what came back.
        Only this metadata is recorded: request and response bodies are never stored, because the login carries the
        payroll password and the replies carry thousands of employees' personal details.
    </div>

    <div class="filter-bar">
        <form method="GET" action="{{ route('dmpi.calls') }}" class="row g-2 align-items-end">
            <input type="hidden" name="per_page" value="{{ $calls->perPage() }}">
            <div class="col-sm-6 col-md-4">
                <label class="form-label small mb-1">Outcome</label>
                <select name="outcome" class="form-select" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="ok" @selected($outcome === 'ok')>Succeeded</option>
                    <option value="http_error" @selected($outcome === 'http_error')>Rejected by DMPI</option>
                    <option value="failed" @selected($outcome === 'failed')>Never answered</option>
                    <option value="pending" @selected($outcome === 'pending')>Still in flight</option>
                    <option value="abandoned" @selected($outcome === 'abandoned')>Abandoned (stopped mid-call)</option>
                </select>
            </div>
            @if ($outcome)
                <div class="col-auto">
                    <a href="{{ route('dmpi.calls') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            @endif
        </form>
    </div>

    <div class="table-card">
        <div class="d-flex justify-content-end mb-3">
            @include('partials.per-page-select', ['paginator' => $calls, 'param' => 'per_page', 'pageParam' => 'page'])
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr><th>Time</th><th>Endpoint</th><th>Result</th><th>Took</th><th>Size</th><th>Detail</th></tr>
                </thead>
                <tbody>
                    @forelse ($calls as $call)
                        <tr>
                            <td class="text-nowrap">{{ $call->created_at }}</td>
                            <td><code>{{ $call->endpoint }}</code></td>
                            <td>
                                @if ($call->succeeded())
                                    <span class="badge bg-success">{{ $call->status_code }}</span>
                                @elseif ($call->outcome === 'http_error')
                                    <span class="badge bg-warning text-dark">{{ $call->status_code }}</span>
                                @elseif ($call->outcome === 'pending')
                                    <span class="badge bg-primary"><span class="spinner-border spinner-border-sm"></span> in flight</span>
                                @elseif ($call->outcome === 'abandoned')
                                    <span class="badge bg-secondary">abandoned</span>
                                @else
                                    <span class="badge bg-danger">no answer</span>
                                @endif
                            </td>
                            <td class="text-nowrap">{{ $call->duration() }}</td>
                            <td class="text-nowrap">{{ $call->size() }}</td>
                            <td class="small text-muted">{{ $call->error ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted text-center py-3">
                            No calls recorded yet. Press one of the <strong>Download</strong> buttons on the Employees page.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.pagination-footer', ['paginator' => $calls])
    </div>
@endsection
