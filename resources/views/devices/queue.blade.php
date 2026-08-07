@extends('layouts.app')

@section('content')
    @php ($Q = \App\Queries\DeviceQueue::class)

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <a href="{{ route('devices.index') }}" class="small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to devices
            </a>
            <h2 class="mb-1 mt-1">Queue for {{ $device->nama ?: $device->no_sn }}</h2>
            <div class="text-muted small">
                <span class="badge {{ $device->isOnline() ? 'bg-success' : 'bg-secondary' }}">● {{ $device->isOnline() ? 'Online' : 'Offline' }}</span>
                <span class="ms-2"><i class="bi bi-hdd-network"></i> {{ $device->no_sn }}</span>
                @if ($device->lokasi)
                    <span class="ms-2"><i class="bi bi-geo-alt"></i> {{ $device->lokasi }}</span>
                @endif
                <span class="ms-2">
                    @if ($device->payroll_device_code)
                        <i class="bi bi-cloud-check"></i> payroll device {{ $device->payroll_device_code }}
                    @else
                        <i class="bi bi-cloud-slash"></i> not linked to payroll
                    @endif
                </span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('devices.people', $device->id) }}" class="btn btn-outline-secondary">
                <i class="bi bi-people"></i> People
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h3 class="h6 mb-1">Live queue progress</h3>
                    <div class="small text-muted">Updates every 5 seconds while this page is open.</div>
                </div>
                <div class="small text-success" id="liveQueueStatus">
                    <span class="spinner-grow spinner-grow-sm" aria-hidden="true"></span>
                    Live · checking now
                </div>
            </div>
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="d-flex justify-content-between gap-2 mb-1">
                        <strong>Delivery progress</strong>
                        <span class="small text-muted">
                            <span data-progress-value="delivered">{{ number_format($progress['delivered']) }}</span>
                            / <span data-progress-value="total">{{ number_format($progress['total']) }}</span>
                            handed to clock
                        </span>
                    </div>
                    <div class="progress" role="progressbar" aria-label="Delivery progress" aria-valuenow="{{ $progress['delivery_percent'] }}" aria-valuemin="0" aria-valuemax="100" data-progress-role="delivery">
                        <div class="progress-bar bg-info" style="width: {{ $progress['delivery_percent'] }}%" data-progress-bar="delivery">{{ $progress['delivery_percent'] }}%</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between gap-2 mb-1">
                        <strong>Device responses</strong>
                        <span class="small text-muted">
                            <span data-progress-value="responded">{{ number_format($progress['responded']) }}</span>
                            / <span data-progress-value="total">{{ number_format($progress['total']) }}</span>
                            replied
                        </span>
                    </div>
                    <div class="progress" role="progressbar" aria-label="Device response progress" aria-valuenow="{{ $progress['response_percent'] }}" aria-valuemin="0" aria-valuemax="100" data-progress-role="response">
                        <div class="progress-bar bg-success" style="width: {{ $progress['response_percent'] }}%" data-progress-bar="response">{{ $progress['response_percent'] }}%</div>
                    </div>
                </div>
            </div>
            <div class="small text-muted mt-3">
                Delivery means the clock collected the command. A response means the clock later reported success or failure.
                Progress includes all command history ADMS currently retains for this clock.
            </div>
            <div class="alert alert-info py-2 px-3 mt-3 mb-0 d-none" id="queueChangedNotice">
                New commands were added or cancelled. <a href="{{ request()->fullUrl() }}" class="alert-link">Refresh the table</a> to see the changed rows.
            </div>
        </div>
    </div>

    {{-- The four states are the whole point of this screen: only the first can
         still be called back, and an operator arriving here in a hurry needs that
         boundary to be the most obvious thing on the page. --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Waiting to be collected</div>
                <div class="fs-3 fw-semibold {{ $counts[$Q::PENDING] ? 'text-warning' : '' }}" data-queue-count="{{ $Q::PENDING }}">{{ number_format($counts[$Q::PENDING]) }}</div>
                <div class="small text-muted">still cancellable</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Handed over — unconfirmed</div>
                <div class="fs-3 fw-semibold" data-queue-count="{{ $Q::SENT }}">{{ number_format($counts[$Q::SENT]) }}</div>
                <div class="small text-muted">too late to stop</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Done</div>
                <div class="fs-3 fw-semibold text-success" data-queue-count="{{ $Q::DONE }}">{{ number_format($counts[$Q::DONE]) }}</div>
                <div class="small text-muted">carried out on the machine</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Failed</div>
                <div class="fs-3 fw-semibold {{ $counts[$Q::FAILED] ? 'text-danger' : '' }}" data-queue-count="{{ $Q::FAILED }}">{{ number_format($counts[$Q::FAILED]) }}</div>
                <div class="small text-muted">the device reported an error</div>
            </div></div>
        </div>
    </div>

    <div class="alert alert-light border d-flex gap-2">
        <i class="bi bi-info-circle fs-5 text-muted"></i>
        <div class="small mb-0">
            This is a <strong>mailbox</strong>, not a setting. The clock collects what's in it whenever it
            connects and carries it out — changing the timekeeper device above does
            <strong>not</strong> take posted instructions back.
            Anything already handed over can no longer be stopped from here.
        </div>
    </div>

    @if ($counts[$Q::PENDING] > 0)
        <div id="cancelAllFormContainer">
            <form action="{{ route('devices.queue.cancel', $device->id) }}" method="POST" class="mb-4" id="cancelAllForm">
                @csrf
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-octagon"></i> Cancel all <span data-cancel-all-count>{{ number_format($counts[$Q::PENDING]) }}</span> waiting
                    </button>
                    <span class="small text-muted">
                        Removes them from the mailbox so the clock never sees them. Nothing already on the machine is changed.
                    </span>
                </div>
            </form>
        </div>
    @endif

    <div class="filter-bar">
        <form method="GET" action="{{ route('devices.queue', $device->id) }}" class="row g-2 align-items-end">
            <input type="hidden" name="per_page" value="{{ $commands->perPage() }}">
            <div class="col-sm-6 col-md-4">
                <label class="form-label small mb-1">State</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">All</option>
                    @foreach ([$Q::PENDING, $Q::SENT, $Q::DONE, $Q::FAILED] as $s)
                        @php ([$sLabel] = $Q::statusLabel($s))
                        <option value="{{ $s }}" @selected($status === $s)>{{ $sLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-md-4">
                <label class="form-label small mb-1">What it does</label>
                <select name="action" class="form-select" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="{{ $Q::ADD }}" @selected($action === $Q::ADD)>Add or update a person</option>
                    <option value="{{ $Q::REMOVE }}" @selected($action === $Q::REMOVE)>Remove a person</option>
                </select>
            </div>
            <div class="col-auto">
                @if ($status || $action)
                    <a href="{{ route('devices.queue', $device->id) }}" class="btn btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>

    <form action="{{ route('devices.queue.cancel', $device->id) }}" method="POST" id="cancelPickedForm">
        @csrf
        <div class="table-card">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <button type="submit" class="btn btn-outline-danger btn-sm" id="cancelPickedBtn" disabled>
                    <i class="bi bi-x-circle"></i> Cancel picked (<span id="pickedCount">0</span>)
                </button>
                @include('partials.per-page-select', ['paginator' => $commands, 'param' => 'per_page', 'pageParam' => 'page'])
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width: 2.5rem;">
                                <input type="checkbox" class="form-check-input" id="pickAll" title="Pick every cancellable row on this page">
                            </th>
                            <th>What it does</th>
                            <th>Person</th>
                            <th>State</th>
                            <th>Device response</th>
                            <th>Queued</th>
                            <th>Handed over</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($commands as $c)
                            @php ([$sLabel, $sClass] = $Q::statusLabel($c->status))
                            <tr data-command-id="{{ $c->id }}">
                                <td data-pick-cell>
                                    @if ($c->cancellable)
                                        <input type="checkbox" class="form-check-input js-pick" name="ids[]" value="{{ $c->id }}">
                                    @else
                                        {{-- Not offered rather than offered-and-refused: a tickable box
                                             for something that cannot be cancelled promises an undo
                                             this server does not have. --}}
                                        <span class="text-muted" title="Already handed to the device — can't be called back">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($c->action === $Q::REMOVE)
                                        <span class="badge bg-danger">Remove from clock</span>
                                    @else
                                        <span class="badge bg-primary">Add or update</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $c->person ?: '—' }}
                                    @if ($c->pin)
                                        <div class="small text-muted"><code>{{ $c->pin }}</code></div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $sClass }}" data-command-state>{{ $sLabel }}</span>
                                </td>
                                <td style="min-width: 13rem;">
                                    <div class="small fw-semibold" data-response-summary>
                                        @if ($c->status === $Q::DONE)
                                            Success · code {{ $c->return_code ?? 0 }}
                                        @elseif ($c->status === $Q::FAILED)
                                            Failed · code {{ $c->return_code ?? 'unknown' }}
                                        @elseif ($c->status === $Q::SENT)
                                            Waiting for device reply
                                        @else
                                            Not sent yet
                                        @endif
                                    </div>
                                    <div class="small text-muted" data-response-time>
                                        {{ $c->done_at ? 'Replied '.$c->done_at : ($c->sent_at ? 'Sent '.$c->sent_at : '') }}
                                    </div>
                                    <details class="small mt-1 {{ $c->response ? '' : 'd-none' }}" data-response-details>
                                        <summary>Raw device reply</summary>
                                        <code class="text-break" data-response-raw>{{ $c->response }}</code>
                                    </details>
                                </td>
                                <td class="small text-muted">{{ $c->created_at }}</td>
                                <td class="small text-muted" data-sent-at>{{ $c->sent_at ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-muted text-center py-3">
                                    @if ($status || $action)
                                        Nothing in this queue matches those filters.
                                    @else
                                        This clock's queue is empty — everything this server decided has been delivered.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('partials.pagination-footer', ['paginator' => $commands])
        </div>
    </form>

    <script>
        // The Vite bundle is a deferred ES module, so it runs after this inline
        // script is parsed but before DOMContentLoaded — jQuery isn't defined yet.
        // Plain DOM here, so nothing is waited on at all.
        document.addEventListener('DOMContentLoaded', function () {
            var picks = function () { return Array.prototype.slice.call(document.querySelectorAll('.js-pick')); };
            var btn = document.getElementById('cancelPickedBtn');
            var count = document.getElementById('pickedCount');
            var all = document.getElementById('pickAll');
            var initialTotal = {{ (int) $counts['total'] }};
            var statusUrl = @json(route('devices.queue.status', $device->id));

            function refresh() {
                var n = picks().filter(function (c) { return c.checked; }).length;
                count.textContent = n;
                btn.disabled = n === 0;
            }

            picks().forEach(function (c) { c.addEventListener('change', refresh); });

            if (all) {
                all.addEventListener('change', function () {
                    picks().forEach(function (c) { c.checked = all.checked; });
                    refresh();
                });
            }

            // Removals are the ones that cost something to get wrong, so the
            // confirmation counts them rather than talking about "items".
            document.getElementById('cancelPickedForm').addEventListener('submit', function (e) {
                var n = picks().filter(function (c) { return c.checked; }).length;
                if (!confirm('Cancel ' + n + ' queued instruction(s)?\n\nThey are removed from this clock\'s mailbox and it will never see them.\nNothing already on the machine changes.')) {
                    e.preventDefault();
                }
            });

            var allForm = document.getElementById('cancelAllForm');
            if (allForm) {
                allForm.addEventListener('submit', function (e) {
                    if (!confirm('Cancel every instruction still waiting for this clock?\n\nThey are removed from its mailbox and it will never see them.\nNothing already on the machine changes.')) {
                        e.preventDefault();
                    }
                });
            }

            refresh();

            function number(value) {
                return new Intl.NumberFormat().format(value);
            }

            function updateProgress(kind, value, percent) {
                document.querySelectorAll('[data-progress-value="' + value + '"]').forEach(function (el) {
                    el.textContent = number(currentProgress[value]);
                });
                var bar = document.querySelector('[data-progress-bar="' + kind + '"]');
                var container = document.querySelector('[data-progress-role="' + kind + '"]');
                bar.style.width = percent + '%';
                bar.textContent = percent + '%';
                container.setAttribute('aria-valuenow', percent);
            }

            var currentProgress = @json($progress);

            function updateCommand(row, command) {
                var badge = row.querySelector('[data-command-state]');
                badge.className = 'badge ' + command.badge_class;
                badge.textContent = command.label;

                var summary = row.querySelector('[data-response-summary]');
                if (command.status === 'done') {
                    summary.textContent = 'Success · code ' + (command.return_code === null ? '0' : command.return_code);
                } else if (command.status === 'failed') {
                    summary.textContent = 'Failed · code ' + (command.return_code === null ? 'unknown' : command.return_code);
                } else if (command.status === 'sent') {
                    summary.textContent = 'Waiting for device reply';
                } else {
                    summary.textContent = 'Not sent yet';
                }

                row.querySelector('[data-response-time]').textContent = command.done_at
                    ? 'Replied ' + command.done_at
                    : (command.sent_at ? 'Sent ' + command.sent_at : '');
                row.querySelector('[data-sent-at]').textContent = command.sent_at || '—';

                var details = row.querySelector('[data-response-details]');
                details.classList.toggle('d-none', !command.response);
                row.querySelector('[data-response-raw]').textContent = command.response || '';

                if (command.status !== 'pending') {
                    var pickCell = row.querySelector('[data-pick-cell]');
                    var pick = pickCell.querySelector('.js-pick');
                    if (pick) {
                        pick.checked = false;
                        pickCell.textContent = '—';
                        pickCell.classList.add('text-muted');
                        refresh();
                    }
                }
            }

            function pollQueue() {
                var rows = Array.prototype.slice.call(document.querySelectorAll('[data-command-id]'));
                var ids = rows.map(function (row) { return row.dataset.commandId; }).join(',');
                var url = statusUrl + (ids ? '?ids=' + encodeURIComponent(ids) : '');
                var live = document.getElementById('liveQueueStatus');

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (response) {
                        if (!response.ok) throw new Error('Queue status request failed');
                        return response.json();
                    })
                    .then(function (data) {
                        ['pending', 'sent', 'done', 'failed'].forEach(function (state) {
                            var el = document.querySelector('[data-queue-count="' + state + '"]');
                            if (el) el.textContent = number(data.counts[state]);
                        });

                        var cancelAllContainer = document.getElementById('cancelAllFormContainer');
                        if (cancelAllContainer) {
                            cancelAllContainer.classList.toggle('d-none', data.counts.pending === 0);
                            cancelAllContainer.querySelector('[data-cancel-all-count]').textContent = number(data.counts.pending);
                        }

                        currentProgress = data.progress;
                        document.querySelectorAll('[data-progress-value="total"]').forEach(function (el) {
                            el.textContent = number(data.progress.total);
                        });
                        updateProgress('delivery', 'delivered', data.progress.delivery_percent);
                        updateProgress('response', 'responded', data.progress.response_percent);

                        rows.forEach(function (row) {
                            var command = data.commands[row.dataset.commandId];
                            if (command) updateCommand(row, command);
                        });

                        document.getElementById('queueChangedNotice').classList.toggle('d-none', data.progress.total === initialTotal);
                        live.className = 'small text-success';
                        live.textContent = '● Live · updated ' + data.checked_at;
                    })
                    .catch(function () {
                        live.className = 'small text-danger';
                        live.textContent = 'Update failed · retrying';
                    });
            }

            pollQueue();
            window.setInterval(pollQueue, 5000);
        });
    </script>
@endsection
