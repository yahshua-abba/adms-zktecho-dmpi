@extends('layouts.app')

@section('content')
    <h2 class="mb-4">{{ $lable }}</h2>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
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
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Not $(function () {...}): the Vite bundle is a deferred module, so jQuery
    // doesn't exist yet while this inline script is being parsed. Deferred modules
    // do run before DOMContentLoaded, so $ is available inside the callback.
    document.addEventListener('DOMContentLoaded', function () {
        // Searchable "Timekeeper device" dropdown — the option list can run to
        // dozens of entries (one per DMPI timekeeper device code), so a plain
        // <select> makes finding the right one a scroll-and-squint exercise.
        document.querySelectorAll('select.js-payroll-device').forEach(function (el) {
            new TomSelect(el, {
                create: false,
                allowEmptyOption: true,
                maxOptions: null,
                placeholder: '— not linked —',
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

            if (search && row.data('search').indexOf(search) === -1) return false;
            if (online && row.data('online') !== online) return false;
            if (direction && String(row.data('direction')) !== direction) return false;
            if (linked && row.data('linked') !== linked) return false;

            return true;
        });

        var table = $('#devices').DataTable({
            // Filtering is driven entirely by the filter bar above, so hide
            // DataTables' own search box and just keep length/pagination/info.
            dom: '<"top">rt<"bottom"lip><"clear">',
            order: [],
            lengthMenu: @json(\App\Support\PerPage::OPTIONS),
            pageLength: {{ \App\Support\PerPage::DEFAULT }},
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
                        $(cell).closest('tr').attr('data-online', s.online ? 'online' : 'offline').data('online', s.online ? 'online' : 'offline');
                    });
                    table.draw();
                })
                .catch(function () { /* ignore transient errors */ });
        }, 60000);
    });
</script>
@endpush
