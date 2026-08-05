@extends('layouts.app')

@section('content')
    @php
        // Outcome -> [badge classes, what it means in plain words]. Kept in one
        // place because the same vocabulary appears in the job list and in the run
        // log below, and the two must not drift apart.
        $badge = [
            'succeeded'   => ['bg-success',            'ok'],
            'failed'      => ['bg-danger',             'failed'],
            'running'     => ['bg-primary',            'running'],
            'overlapping' => ['bg-warning text-dark',  'still busy'],
            'skipped'     => ['bg-secondary',          'skipped'],
        ];

        // Same three-way vocabulary as the Monitoring banner, so a status means the
        // same thing and looks the same wherever it appears in the dashboard.
        $verdictStyle = [
            'ok'   => ['alert-success',        '✓'],
            'warn' => ['alert-warning',        '!'],
            'fail' => ['alert-danger',         '✕'],
        ][$verdict['status']];

        $filtered = $command || $status;
        $filteredJob = $command ? (\App\Models\ScheduledTaskRun::LABELS[$command] ?? $command) : null;
    @endphp

    {{-- ─── The page reads top to bottom, general to specific ───
         1. Is it working?  2. What are the jobs?  3. What happened, run by run?
         It used to open with three equal panels of raw signals and then stack a
         heading, an explainer, a filter bar and a table as four separate boxes, so
         two similar-looking tables sat under near-identical furniture and it was
         genuinely hard to tell which one you were reading. Each step is now one
         card with one job. --}}

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h2 class="mb-0">Scheduler</h2>
        <form action="{{ route('scheduler.start') }}" method="POST" class="mb-0">
            @csrf
            {{-- Quiet when nothing is wrong. A prominent call to action next to a
                 "running normally" banner invites a press that can only interrupt
                 a healthy scheduler. --}}
            <button type="submit" class="btn {{ $verdict['status'] === 'ok' ? 'btn-outline-secondary' : 'btn-primary' }}">
                <i class="bi bi-play-circle"></i> Start scheduler
            </button>
        </form>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ─── 1. Is it working? ───
         One verdict, in the words the Monitoring page uses, from the same code.
         The evidence behind it is deliberately smaller: three co-equal lights that
         only mean something in combination made the reader do the reasoning, and
         on a cron-driven box two of them appear to contradict each other. --}}
    <div class="alert {{ $verdictStyle[0] }}" role="status">
        <div class="d-flex align-items-center">
            <span class="fs-4 me-2">{{ $verdictStyle[1] }}</span>
            <strong>{{ $verdict['detail'] }}</strong>
        </div>
        <div class="small mt-1 opacity-75">
            @foreach ($supporting as $fact)
                {{ $fact }}
            @endforeach
        </div>
    </div>

    {{-- ─── 2. The jobs ───
         Separate from the run log because the every-minute job writes ~1,400 rows
         a day and would bury the hourly ones entirely in a newest-first list. This
         is the only place a job that has never run once is visible. --}}
    <h6 class="text-muted text-uppercase small mb-2">Scheduled jobs</h6>
    <div class="table-card mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Job</th><th>Runs</th><th>Last run</th><th>Result</th><th>Took</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($jobs as $job)
                        {{-- The selected job stays highlighted while you read its runs
                             below, so the two sections are visibly connected rather
                             than being two tables that happen to be on one page. --}}
                        <tr @class(['table-active' => $command === $job['command']])>
                            <td>
                                <strong>{{ $job['label'] }}</strong>
                                <div class="small text-muted"><code>{{ $job['command'] }}</code></div>
                            </td>
                            <td class="text-nowrap small text-muted">{{ $job['cadence'] }}</td>
                            <td class="text-nowrap">
                                {{ $job['run']?->started_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td>
                                @if ($job['run'])
                                    <span class="badge {{ $badge[$job['run']->status][0] ?? 'bg-secondary' }}">
                                        {{ $badge[$job['run']->status][1] ?? $job['run']->status }}
                                    </span>
                                @else
                                    <span class="text-muted small">never run</span>
                                @endif
                            </td>
                            <td class="text-nowrap">{{ $job['run']?->duration() ?? '—' }}</td>
                            {{-- The anchor is not decoration: this filters the log a
                                 screenful below, and without jumping there the click
                                 looks like it did nothing at all. --}}
                            <td class="text-end">
                                <a href="{{ route('scheduler.log', ['command' => $job['command']]) }}#runs"
                                   class="btn btn-sm {{ $command === $job['command'] ? 'btn-secondary' : 'btn-outline-secondary' }}"
                                   title="Show only this job's runs in the log below">View runs</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ─── 3. Run by run ───
         Heading, explanation, filters, table and pagination in one card. As four
         stacked boxes this section looked like four unrelated things and was hard
         to tell apart from the job list above it. --}}
    <h6 id="runs" class="text-muted text-uppercase small mb-2" style="scroll-margin-top: 5rem;">
        Run history
        @if ($filteredJob)
            {{-- text-transform:none, not .text-capitalize: the parent is uppercase,
                 and capitalize would render the job name in Title Case. --}}
            <span class="text-body" style="text-transform: none;">— {{ $filteredJob }} only</span>
        @endif
    </h6>

    <div class="table-card">
        <p class="text-muted small">
            Every time the scheduler started a job, newest first. Recorded by watching the scheduler itself, so a job
            that crashes before it can write its own log still shows up.
            <span class="badge bg-warning text-dark">still busy</span>
            means the job was due but its previous run hadn't finished, so this one was skipped — a long run of those
            is the signature of a job that has hung.
        </p>

        {{-- The fragment on the action keeps a filter change landing in the same
             place as a View runs click. Without it the dropdown reloaded to the top
             of the page while the button jumped to the bottom, and the page felt
             like it moved at random. --}}
        <form method="GET" action="{{ route('scheduler.log') }}#runs" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="per_page" value="{{ $runs->perPage() }}">
            <div class="col-sm-6 col-md-4">
                <label class="form-label small mb-1">Job</label>
                <select name="command" class="form-select" onchange="this.form.submit()">
                    <option value="">All jobs</option>
                    @foreach ($commands as $option)
                        <option value="{{ $option }}" @selected($command === $option)>
                            {{ \App\Models\ScheduledTaskRun::LABELS[$option] ?? $option }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-md-4">
                <label class="form-label small mb-1">Result</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">All results</option>
                    <option value="succeeded"   @selected($status === 'succeeded')>Succeeded</option>
                    <option value="failed"      @selected($status === 'failed')>Failed</option>
                    <option value="overlapping" @selected($status === 'overlapping')>Skipped — previous run still busy</option>
                    <option value="skipped"     @selected($status === 'skipped')>Skipped by a condition</option>
                    <option value="running"     @selected($status === 'running')>Still running</option>
                </select>
            </div>
            <div class="col-sm-6 col-md-4 d-flex justify-content-between align-items-end gap-2">
                @if ($filtered)
                    <a href="{{ route('scheduler.log') }}" class="btn btn-outline-secondary">Show all</a>
                @else
                    <span></span>
                @endif
                @include('partials.per-page-select', ['paginator' => $runs, 'param' => 'per_page', 'pageParam' => 'page'])
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr><th>Started</th><th>Job</th><th>Result</th><th>Took</th><th>What happened</th></tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td class="text-nowrap">{{ $run->started_at }}</td>
                            <td>
                                {{ $run->label() }}
                                <div class="small text-muted"><code>{{ $run->command }}</code></div>
                            </td>
                            <td>
                                <span class="badge {{ $badge[$run->status][0] ?? 'bg-secondary' }}">
                                    {{ $badge[$run->status][1] ?? $run->status }}
                                </span>
                            </td>
                            <td class="text-nowrap">{{ $run->duration() }}</td>
                            <td class="small text-muted">{{ $run->detail() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted text-center py-3">
                            @if ($filtered)
                                Nothing matches that filter.
                            @else
                                Nothing recorded yet. Jobs appear here within a minute of the scheduler starting.
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.pagination-footer', ['paginator' => $runs])
    </div>

    {{-- ─── Footnote: starts and restarts ───
         Moved off the top of the page. It reads as status but it is history, and
         sitting beside the live indicators it made a restart from last week look
         like something happening now. --}}
    @if ($lifecycle->isNotEmpty())
        <h6 class="text-muted text-uppercase small mt-4 mb-2">Recent starts</h6>
        <div class="table-card">
            <ul class="list-unstyled small mb-0">
                @foreach ($lifecycle as $entry)
                    <li class="mb-1">
                        <span class="text-muted">{{ $entry->created_at }}</span>
                        —
                        @if ($entry->event === 'scheduler.autostart')
                            <span class="badge bg-warning text-dark">automatic</span>
                        @else
                            <span class="badge bg-light text-dark border">by hand</span>
                        @endif
                        {{ $entry->message }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    // Jump to the filtered run log ourselves, because the browser's own jump loses
    // a race here. Bootstrap sets scroll-behavior: smooth on <html>, so following
    // #runs starts an animation; Chrome's scroll restoration then applies the
    // previous page's position mid-flight and cancels it about eight pixels in.
    // The visible result was a View runs button that filtered a table two screens
    // below the fold and appeared to do nothing at all.
    //
    // Two details are load-bearing. It runs on `load`, not `DOMContentLoaded`,
    // because scroll restoration happens after the document parses and would
    // otherwise undo this too. And it forces the scroll to be instant — an
    // animated one is interruptible, which is the whole problem.
    window.addEventListener('load', function () {
        if (window.location.hash !== '#runs') {
            return;
        }

        var target = document.getElementById('runs');

        if (! target) {
            return;
        }

        var root = document.documentElement;
        var inherited = root.style.scrollBehavior;

        root.style.scrollBehavior = 'auto';
        target.scrollIntoView();
        root.style.scrollBehavior = inherited;
    });
</script>
@endpush
