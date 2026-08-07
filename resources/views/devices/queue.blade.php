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

    {{-- The four states are the whole point of this screen: only the first can
         still be called back, and an operator arriving here in a hurry needs that
         boundary to be the most obvious thing on the page. --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Waiting to be collected</div>
                <div class="fs-3 fw-semibold {{ $counts[$Q::PENDING] ? 'text-warning' : '' }}">{{ number_format($counts[$Q::PENDING]) }}</div>
                <div class="small text-muted">still cancellable</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Handed over — unconfirmed</div>
                <div class="fs-3 fw-semibold">{{ number_format($counts[$Q::SENT]) }}</div>
                <div class="small text-muted">too late to stop</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Done</div>
                <div class="fs-3 fw-semibold text-success">{{ number_format($counts[$Q::DONE]) }}</div>
                <div class="small text-muted">carried out on the machine</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Failed</div>
                <div class="fs-3 fw-semibold {{ $counts[$Q::FAILED] ? 'text-danger' : '' }}">{{ number_format($counts[$Q::FAILED]) }}</div>
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
            @if ($counts[$Q::SENT])
                {{ number_format($counts[$Q::SENT]) }} instruction(s) have already been handed over and can no
                longer be stopped from here.
            @endif
        </div>
    </div>

    @if ($counts[$Q::PENDING] > 0)
        <form action="{{ route('devices.queue.cancel', $device->id) }}" method="POST" class="mb-4" id="cancelAllForm">
            @csrf
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-x-octagon"></i> Cancel all {{ number_format($counts[$Q::PENDING]) }} waiting
                </button>
                <span class="small text-muted">
                    Removes them from the mailbox so the clock never sees them. Nothing already on the machine is changed.
                </span>
            </div>
        </form>
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
                            <th>Queued</th>
                            <th>Handed over</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($commands as $c)
                            @php ([$sLabel, $sClass] = $Q::statusLabel($c->status))
                            <tr>
                                <td>
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
                                    <span class="badge {{ $sClass }}">{{ $sLabel }}</span>
                                    @if ($c->status === $Q::FAILED && $c->return_code !== null)
                                        <div class="small text-muted">device said code {{ $c->return_code }}</div>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $c->created_at }}</td>
                                <td class="small text-muted">{{ $c->sent_at ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-muted text-center py-3">
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
        });
    </script>
@endsection
