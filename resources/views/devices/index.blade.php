@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h2 class="mb-0">{{ $lable }}</h2>
        {{-- Both live here, not on Employees. They come from the same DMPI call but
             differ entirely in reach: one refreshes payroll's list of clock names,
             shown below; the other rewrites the machines on the wall. Employees
             keeps only the roster download. --}}
        <div class="d-flex flex-wrap gap-2">
            <form action="{{ route('dmpi.sync', 'devices') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-outline-success" title="Refresh payroll's list of time clock names, shown below. Only a list — it does not touch the machines on the wall or change who can use them.">
                    <i class="bi bi-hdd-network"></i> Download devices
                </button>
            </form>
            {{-- Confirmed through a real modal, not window.confirm(): the browser box
                 is a fixed size we cannot style, and it silently scrolled an
                 explanation this long down to its last two lines — hiding the very
                 warning it exists to deliver. The form is empty here and submitted by
                 the modal's button via form="", the same pattern the device rows use. --}}
            <form id="download-assignments" action="{{ route('dmpi.sync', 'assignments') }}" method="POST">
                @csrf
            </form>
            <button type="button" class="btn btn-outline-success"
                    data-bs-toggle="modal" data-bs-target="#confirm-assignments"
                    title="Ask payroll who belongs on each time clock, then rewrite the machines on the wall to match — adding and removing people.">
                <i class="bi bi-diagram-3"></i> Download assignments
            </button>
        </div>
    </div>

    @include('partials.sync-progress')

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="filter-bar">
        <div class="d-flex align-items-center mb-3">
            <i class="bi bi-funnel me-2 text-muted"></i>
            <span class="fw-semibold">Filters</span>
        </div>
        <form id="filterForm">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-4">
                    <label class="form-label small text-muted mb-1">Search</label>
                    <input type="text" id="f_search" class="form-control" placeholder="Serial number, name, or location">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small text-muted mb-1">Online</label>
                    <select id="f_online" class="form-select">
                        <option value="">All</option>
                        <option value="online">Online</option>
                        <option value="offline">Offline</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small text-muted mb-1">Direction</label>
                    <select id="f_direction" class="form-select">
                        <option value="">All</option>
                        <option value="in">IN</option>
                        <option value="out">OUT</option>
                        <option value="both">BOTH</option>
                        <option value="none">Not set</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small text-muted mb-1">Timekeeper device</label>
                    <select id="f_linked" class="form-select">
                        <option value="">All</option>
                        <option value="linked">Linked</option>
                        <option value="unlinked">Not linked</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2 d-flex">
                    <button type="button" id="clearFilters" class="btn btn-outline-secondary w-100">Clear</button>
                </div>
            </div>
        </form>
    </div>

    {{-- ─── DMPI's own device list ───
         Kept visible in its own right. It previously rendered only as a dropdown
         inside each physical device row, so on a server with no clocks checked in
         a successful download of 89 devices showed up nowhere and looked like a
         failure. --}}
    @php
        $linkedCount = collect($payrollDevices)->filter(fn ($pd) => ! empty($pd->linked_serials))->count();
    @endphp
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <i class="bi bi-cloud-check text-muted me-1"></i>
                    <strong>Payroll devices from DMPI</strong>
                    <span class="badge {{ count($payrollDevices) ? 'bg-success' : 'bg-secondary' }} ms-1">{{ count($payrollDevices) }}</span>
                    @if (count($payrollDevices))
                        <span class="text-muted small ms-2">
                            {{ $linkedCount }} linked to a clock here,
                            {{ count($payrollDevices) - $linkedCount }} not yet
                        </span>
                    @endif
                </div>
                <div class="d-flex flex-wrap gap-2">
                    {{-- Shown even with nothing downloaded: the screen explains how to
                         populate itself, which is more use than a hidden button. --}}
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('devices.timekeepers') }}">
                        <i class="bi bi-people"></i> See who's on each
                    </a>
                    @if (count($payrollDevices))
                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                data-bs-toggle="collapse" data-bs-target="#payroll-devices">
                            Show list
                        </button>
                    @endif
                </div>
            </div>

            @if (count($payrollDevices))
                <div class="collapse mt-3" id="payroll-devices">
                    <div class="table-responsive" style="max-height: 22rem; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="sticky-top bg-body">
                                <tr><th>Code</th><th>Location</th><th>Clock connected here</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($payrollDevices as $pd)
                                    <tr>
                                        <td><code>{{ $pd->code }}</code></td>
                                        <td class="text-muted">{{ $pd->name ?: '—' }}</td>
                                        <td>
                                            @forelse ($pd->linked_serials as $serial)
                                                <span class="badge bg-light text-dark border"><i class="bi bi-hdd-network"></i> {{ $serial }}</span>
                                            @empty
                                                <span class="text-muted">—</span>
                                            @endforelse
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('devices.timekeepers.show', ['code' => $pd->code]) }}"
                                               class="text-decoration-none small">View people</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="text-muted small mt-2">
                    Nothing downloaded yet. Use <strong>Download devices</strong> above to pull DMPI's list.
                </div>
            @endif
        </div>
    </div>

    @if ($log->isEmpty())
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            <strong>No time clock has contacted this server yet.</strong>
            Clocks add themselves the first time they check in — they can't be created here.
            @if (count($payrollDevices))
                The {{ count($payrollDevices) }} payroll devices above are DMPI's records, not clocks talking to this server;
                once a real clock checks in you can link it to one of them.
            @endif
            Check <a href="{{ route('devices.DeviceLog') }}">Device Check-ins</a> to see whether anything is reaching the server at all.
        </div>
    @endif

    <div class="table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="devices">
                <thead>
                    <tr>
                        <th>Serial Number</th>
                        <th>Name</th>
                        <th>Location</th>
                        <th>Direction</th>
                        <th>Timekeeper device</th>
                        <th>People</th>
                        <th>Online</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($log as $d)
                        <tr data-online="{{ $d->isOnline() ? 'online' : 'offline' }}"
                            data-direction="{{ $d->direction ?? 'none' }}"
                            data-linked="{{ $d->payroll_device_code ? 'linked' : 'unlinked' }}"
                            data-search="{{ strtolower($d->no_sn.' '.$d->nama.' '.$d->lokasi) }}">
                            <td>
                                {{ $d->no_sn }}
                                <form id="dev-{{ $d->id }}" action="{{ route('devices.update', $d->id) }}" method="POST">
                                    @csrf
                                    @method('PATCH')
                                </form>
                            </td>
                            <td><input form="dev-{{ $d->id }}" type="text" name="nama" value="{{ $d->nama }}" class="form-control form-control-sm"></td>
                            <td><input form="dev-{{ $d->id }}" type="text" name="lokasi" value="{{ $d->lokasi }}" class="form-control form-control-sm"></td>
                            <td>
                                <select form="dev-{{ $d->id }}" name="direction" class="form-select form-select-sm">
                                    <option value="" @selected($d->direction === null)>—</option>
                                    <option value="in" @selected($d->direction === 'in')>IN</option>
                                    <option value="out" @selected($d->direction === 'out')>OUT</option>
                                    <option value="both" @selected($d->direction === 'both')>BOTH</option>
                                </select>
                            </td>
                            <td>
                                <select form="dev-{{ $d->id }}" name="payroll_device_code" class="form-select form-select-sm js-payroll-device">
                                    <option value="" @selected($d->payroll_device_code === null)>— not linked —</option>
                                    @foreach ($payrollDevices as $pd)
                                        <option value="{{ $pd->code }}" @selected($d->payroll_device_code === $pd->code)>
                                            {{ $pd->code }}{{ $pd->name ? ' — '.$pd->name : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            {{-- Two numbers, never one. "On the clock" is what this server
                                 has sent to the machine; "payroll assigns" is what DMPI says
                                 belongs there. They disagree whenever an enrollment sync is
                                 owed or a person can't be enrolled, and that disagreement is
                                 what an operator needs to see. Full breakdown one click away.
                                 See App\Queries\DeviceRoster. --}}
                            @php ($people = $peopleCounts[$d->no_sn] ?? ['on_clock' => 0, 'assigned' => 0, 'blocked' => 0, 'waiting' => 0, 'unconfirmed' => 0, 'linked' => false])
                            <td>
                                <a href="{{ route('devices.people', $d->id) }}" class="text-decoration-none" title="See who is on this clock">
                                    <span class="fs-6 fw-semibold">{{ $people['on_clock'] }}</span>
                                    <span class="small">on the clock</span>
                                </a>
                                <div class="small text-muted">
                                    @if ($people['linked'])
                                        payroll assigns {{ $people['assigned'] }}
                                    @else
                                        not linked to payroll
                                    @endif
                                </div>
                                @if ($people['blocked'])
                                    <span class="badge bg-danger" title="Assigned in payroll but with no employee record here — they cannot be enrolled">{{ $people['blocked'] }} can't be added</span>
                                @endif
                            {{-- Two badges, never one total. "Waiting" is work this server
                                 still owes the clock and can still call back; "unconfirmed"
                                 is already delivered and can't be. Summed under one label
                                 they read as outstanding work, and a clock that stops
                                 confirming carries that number for ever. Both link to the
                                 queue, which is the only place any of it can be acted on. --}}
                                @if ($people['waiting'])
                                    <a href="{{ route('devices.queue', $d->id) }}" class="text-decoration-none"
                                       title="Changes still in this device's mailbox — it collects them next time it connects. These can still be cancelled.">
                                        <span class="badge bg-warning text-dark">{{ number_format($people['waiting']) }} waiting</span>
                                    </a>
                                @endif
                                @if ($people['unconfirmed'])
                                    <a href="{{ route('devices.queue', $d->id) }}" class="text-decoration-none"
                                       title="Already handed to the device, which never reported back whether it carried them out. Nothing is outstanding on this server's side and these cannot be cancelled.">
                                        <span class="badge bg-secondary">{{ number_format($people['unconfirmed']) }} unconfirmed</span>
                                    </a>
                                @endif
                            </td>
                            <td data-status-sn="{{ $d->no_sn }}">
                                <span class="status-badge badge {{ $d->isOnline() ? 'bg-success' : 'bg-secondary' }}">● {{ $d->isOnline() ? 'Online' : 'Offline' }}</span>
                                <div class="small text-muted status-seen">{{ $d->online ? 'seen '.$d->online->diffForHumans() : 'never seen' }}</div>
                            </td>
                            <td class="text-nowrap">
                                <button type="submit" form="dev-{{ $d->id }}" class="btn btn-sm btn-primary">Save</button>
                                <a href="{{ route('devices.PunchLog', $d->id) }}" class="btn btn-sm btn-outline-secondary">Logs</a>
                                <form action="{{ route('devices.syncEnrollments', $d->id) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Queue enrollment commands for this device">Sync enrollments</button>
                                </form>
                                {{-- The serial is reported by the device itself over an endpoint that
                                     is deliberately open, so it is attacker-controlled. It must never
                                     reach inline JS: see the submit handler below. --}}
                                <form action="{{ route('devices.destroy', $d->id) }}" method="POST"
                                      class="d-inline js-remove-device" data-serial="{{ $d->no_sn }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            title="Remove this clock from the list. Punches are kept.">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ─── "Download assignments" confirmation ───
         Long on purpose. This is the only control on the page that reaches into the
         physical machines, and the only one whose damage the server cannot repair:
         it holds no fingerprint templates, so a wrongly removed person has to
         re-enrol at the machine in person. It also spells out what a "clock" is,
         because this page uses "device" for both the machine on the wall and
         payroll's paper record of one, and that gap is the whole risk.

         Sits outside the form and submits it by id, so nothing is nested and the
         modal can live at the end of the page where Bootstrap prefers it. --}}
    <div class="modal fade" id="confirm-assignments" tabindex="-1"
         aria-labelledby="confirm-assignments-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirm-assignments-title">
                        <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                        Rewrite the time clocks?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p>
                        A <strong>time clock</strong> is the physical machine on the wall that people
                        touch to clock in. Each one holds its own list of who is allowed to use it.
                    </p>

                    <p class="mb-2">
                        This asks payroll for its current lists, then rewrites those machines to match:
                    </p>
                    <ul>
                        <li>people who should be on a machine are <strong>added</strong></li>
                        <li>people no longer assigned are <strong>removed</strong></li>
                    </ul>

                    <div class="alert alert-warning d-flex gap-2 mb-3">
                        <i class="bi bi-fingerprint fs-5"></i>
                        <div>
                            <strong>Removing someone also erases their fingerprint from that machine.</strong>
                            This server keeps no copy of fingerprints, so anyone removed by mistake has to
                            walk to the machine and scan their finger again. That part cannot be undone
                            from here.
                        </div>
                    </div>

                    <div class="alert alert-light border d-flex gap-2 mb-3">
                        <i class="bi bi-shield-check fs-5 text-success"></i>
                        <div>
                            <strong>Not affected:</strong> attendance records, the employee list, and each
                            clock's own settings.
                        </div>
                    </div>

                    <p class="mb-0 text-muted">
                        Only continue if payroll's assignments are correct right now.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="download-assignments" class="btn btn-danger">
                        <i class="bi bi-diagram-3"></i> Yes, rewrite the clocks
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Not $(function () {...}): the Vite bundle is a deferred module, so jQuery
    // doesn't exist yet while this inline script is being parsed. Deferred modules
    // do run before DOMContentLoaded, so $ is available inside the callback.
    document.addEventListener('DOMContentLoaded', function () {
        // Confirm before removing a clock.
        //
        // Deliberately NOT an inline onsubmit. The device serial comes from the
        // device itself, over /iclock/* which has no login by design, so anything
        // on the device LAN can choose it. Interpolated into an inline handler it
        // lands inside a JS string literal — and the HTML parser decodes entities
        // before the JS engine ever sees them, so a serial containing an apostrophe
        // closed the string and the rest of it ran. Reading the serial from a data
        // attribute keeps it a string and nothing else.
        document.querySelectorAll('form.js-remove-device').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var serial = form.dataset.serial || 'this device';
                var ok = confirm(
                    'Remove ' + serial + ' from this server?\n\n' +
                    'Its attendance punches are KEPT — they are still valid records.\n' +
                    "What goes is this device's enrolled-user list and any queued commands.\n\n" +
                    'If the clock is still switched on and pointed here, it will reappear at its next check-in.'
                );

                if (!ok) {
                    e.preventDefault();
                }
            });
        });

        // Searchable "Timekeeper device" dropdown — the option list can run to
        // dozens of entries (one per DMPI timekeeper device code), so a plain
        // <select> makes finding the right one a scroll-and-squint exercise.
        document.querySelectorAll('select.js-payroll-device').forEach(function (el) {
            new TomSelect(el, {
                create: false,
                allowEmptyOption: true,
                maxOptions: null,
                placeholder: '— not linked —',
                // Bootstrap's .table-responsive sets overflow-x, and CSS turns that
                // into clipping on BOTH axes — so the dropdown was cut off after two
                // entries with 89 codes to choose from. Attaching it to <body> takes
                // it out of the scrolling box entirely.
                dropdownParent: 'body',
            });
        });

        // Custom global filter: free-text search box + the Online/Direction/
        // Payroll-device dropdowns, all combined (AND) against data-* attributes
        // set server-side on each row.
        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex, rowData, counter) {
            if (settings.nTable.id !== 'devices') return true;

            // Read the row node straight off DataTables' internal row cache
            // rather than through the `table` API object — this callback also
            // runs during the initial draw, before the `var table = ...`
            // assignment below has completed.
            var row = $(settings.aoData[dataIndex].nTr);
            var search = $('#f_search').val().toLowerCase().trim();
            var online = $('#f_online').val();
            var direction = $('#f_direction').val();
            var linked = $('#f_linked').val();

            // .attr(), not .data(): jQuery's .data() coerces an attribute that
            // looks like a number into a Number (and caches the first read), so a
            // row whose searchable text is all digits would break .indexOf below.
            // Reading the attributes raw keeps every value a string.
            if (search && (row.attr('data-search') || '').indexOf(search) === -1) return false;
            if (online && row.attr('data-online') !== online) return false;
            if (direction && row.attr('data-direction') !== direction) return false;
            if (linked && row.attr('data-linked') !== linked) return false;

            return true;
        });

        var table = $('#devices').DataTable({
            // Filtering is driven entirely by the filter bar above, so hide
            // DataTables' own search box and just keep length/pagination/info.
            dom: '<"top">rt<"bottom"lip><"clear">',
            order: [],
            lengthMenu: @json(\App\Support\PerPage::OPTIONS),
            pageLength: {{ \App\Support\PerPage::DEFAULT }},
            language: {
                // "No data available in table" reads like a fault. This table only
                // ever fills when a physical clock checks in, so say that instead.
                emptyTable: 'No time clocks have checked in to this server yet.',
                zeroRecords: 'No clocks match those filters.',
            },
        });

        $('#filterForm').on('submit', function (e) { e.preventDefault(); table.draw(); });
        $('#f_search').on('keyup', function () { table.draw(); });
        $('#f_online, #f_direction, #f_linked').on('change', function () { table.draw(); });
        $('#clearFilters').on('click', function () {
            $('#f_search').val('');
            $('#f_online, #f_direction, #f_linked').val('');
            table.draw();
        });

        // Refresh just the online/offline badges every minute, so leaving the page
        // open acts as a live health board without disturbing in-progress edits.
        setInterval(function () {
            fetch('{{ route('devices.status') }}', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    document.querySelectorAll('[data-status-sn]').forEach(function (cell) {
                        var s = data[cell.getAttribute('data-status-sn')];
                        if (!s) return;
                        var badge = cell.querySelector('.status-badge');
                        var seen = cell.querySelector('.status-seen');
                        badge.className = 'status-badge badge ' + (s.online ? 'bg-success' : 'bg-secondary');
                        badge.textContent = '● ' + (s.online ? 'Online' : 'Offline');
                        if (seen) seen.textContent = s.seen ? ('seen ' + s.seen) : 'never seen';
                        $(cell).closest('tr').attr('data-online', s.online ? 'online' : 'offline');
                    });
                    // draw(false) keeps the current page — this poll fires every
                    // minute, and a bare draw() would bounce an operator reading
                    // page 3 back to page 1 each time. The filter handlers above
                    // keep the plain draw(), where resetting to page 1 is right.
                    table.draw(false);
                })
                .catch(function () { /* ignore transient errors */ });
        }, 60000);
    });
</script>
@endpush
