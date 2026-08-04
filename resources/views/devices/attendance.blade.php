@extends('layouts.app')

@section('content')
    @php
        $dateFrom = $filters['date_from'] ?? now()->subDays(7)->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $sync = $filters['sync'] ?? '';
        $device = $filters['device'] ?? '';
        $employee = $filters['employee'] ?? '';
        $company = $filters['company'] ?? '';
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Attendance</h2>
        <div class="d-flex gap-2">
            <a href="{{ route('attendance.export') }}" id="exportBtn" class="btn btn-outline-primary"><i class="bi bi-download"></i> Export CSV</a>
            <form action="{{ route('attendance.sync') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-success" title="Pushes every pending punch, not just what's shown or selected below"><i class="bi bi-arrow-repeat"></i> Sync to payroll now</button>
            </form>
        </div>
    </div>

    <div id="ajaxAlerts"></div>

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
                {{-- Date range --}}
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small text-muted mb-1">From date</label>
                    <input type="date" id="f_date_from" value="{{ $dateFrom }}" class="form-control">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small text-muted mb-1">To date</label>
                    <input type="date" id="f_date_to" value="{{ $dateTo }}" class="form-control">
                </div>
                {{-- Device --}}
                <div class="col-12 col-md-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Device</label>
                    <select id="f_device" class="form-select">
                        <option value="">All devices</option>
                        @foreach ($devices as $d)
                            <option value="{{ $d->no_sn }}" @selected($device === $d->no_sn)>
                                {{ $d->nama ? $d->nama.' — ' : '' }}{{ $d->no_sn }}{{ $d->direction ? ' ('.strtoupper($d->direction).')' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                {{-- Company --}}
                <div class="col-6 col-md-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Company</label>
                    <select id="f_company" class="form-select">
                        <option value="">All companies</option>
                        @foreach ($companies as $c)
                            <option value="{{ $c }}" @selected($company === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Sync status --}}
                <div class="col-6 col-md-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Sync status</label>
                    <select id="f_sync" class="form-select">
                        <option value="">All statuses</option>
                        <option value="synced" @selected($sync === 'synced')>Synced</option>
                        <option value="pending" @selected($sync === 'pending')>Pending</option>
                        <option value="failed" @selected($sync === 'failed')>Failed</option>
                        <option value="skipped" @selected($sync === 'skipped')>Skipped</option>
                    </select>
                </div>
                {{-- Employee + actions --}}
                <div class="col-12 col-md-8 col-lg-7">
                    <label class="form-label small text-muted mb-1">Employee — name or CHAPA</label>
                    <input type="text" id="f_employee" value="{{ $employee }}" class="form-control" placeholder="e.g. Rubelyn or 4968">
                </div>
                <div class="col-12 col-md-4 col-lg-5 d-flex gap-2 justify-content-md-end">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Apply</button>
                    <button type="button" id="clearFilters" class="btn btn-outline-secondary">Clear</button>
                </div>
            </div>
        </form>
    </div>

    <div id="selectionToolbar" class="table-card d-none mb-3 d-flex flex-wrap align-items-center gap-2 py-2 px-3">
        <span class="fw-semibold"><span id="selCount">0</span> selected</span>
        <span class="text-muted small">(selection is kept as you page through the table)</span>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <button type="button" id="syncSelectedBtn" class="btn btn-sm btn-success"><i class="bi bi-arrow-repeat"></i> Sync selected</button>
            <button type="button" id="excludeSelectedBtn" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Exclude from sync</button>
            <button type="button" id="includeSelectedBtn" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Include in sync</button>
            <button type="button" id="deleteSelectedBtn" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete selected</button>
            <button type="button" id="clearSelectionBtn" class="btn btn-sm btn-link">Clear selection</button>
        </div>
    </div>

    <div class="table-card">
        <table class="table table-hover align-middle w-100" id="attendance">
            <thead>
                <tr>
                    <th style="width:1%"><input type="checkbox" class="form-check-input" id="selectAllOnPage" title="Select all rows on this page"></th>
                    <th>ID</th>
                    <th>Punched <div class="small text-muted fw-normal">at device</div></th>
                    <th>Received <div class="small text-muted fw-normal">by ADMS</div></th>
                    <th>Device</th>
                    <th>In/Out</th>
                    <th>Employee</th>
                    <th>Sync</th>
                    <th>Synced <div class="small text-muted fw-normal">to payroll</div></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@push('scripts')
<script>
    // Not $(function () {...}): the Vite bundle is a deferred module, so jQuery
    // doesn't exist yet while this inline script is being parsed. Deferred modules
    // do run before DOMContentLoaded, so $ is available inside the callback.
    document.addEventListener('DOMContentLoaded', function () {
        function currentFilters() {
            return {
                date_from: $('#f_date_from').val(),
                date_to: $('#f_date_to').val(),
                device: $('#f_device').val(),
                company: $('#f_company').val(),
                sync: $('#f_sync').val(),
                employee: $('#f_employee').val(),
            };
        }

        var table = $('#attendance').DataTable({
            processing: true,
            serverSide: true,
            searching: false,
            order: [],
            lengthMenu: @json(\App\Support\PerPage::OPTIONS),
            pageLength: {{ \App\Support\PerPage::DEFAULT }},
            ajax: {
                url: '{{ route('devices.Attendance') }}',
                data: function (d) { Object.assign(d, currentFilters()); }
            },
            columns: [
                {
                    data: 'id', orderable: false, searchable: false, className: 'text-center',
                    render: function (id) { return '<input type="checkbox" class="form-check-input row-select" value="' + id + '">'; }
                },
                { data: 'id', name: 'attendances.id' },
                { data: 'timestamp', name: 'attendances.timestamp' },
                { data: 'received_at', name: 'attendances.created_at' },
                { data: 'device_display', orderable: false, searchable: false },
                { data: 'inout', orderable: false, searchable: false },
                { data: 'employee_display', orderable: false, searchable: false },
                { data: 'sync_status', orderable: false, searchable: false },
                { data: 'synced_at', name: 'attendances.sync_time' },
            ],
            drawCallback: function () { syncCheckboxesToSelection(); }
        });

        // Keep the Export link pointed at the same filters the table is showing,
        // so "Export CSV" downloads exactly what's on screen (or everything when
        // filters are empty).
        function syncExportLink() {
            var params = $.param(currentFilters());
            $('#exportBtn').attr('href', '{{ route('attendance.export') }}' + (params ? '?' + params : ''));
        }
        syncExportLink();

        // Row selection deliberately survives paging, but NOT a filter change: the
        // bulk actions (delete, exclude, sync) would otherwise act on rows the
        // operator can no longer see, since a new filter can hide anything already
        // ticked. Clearing on every filter-driven redraw keeps "selected" and
        // "on screen" honest. (selectedIds/syncCheckboxesToSelection are defined
        // just below; both exist by the time a user event can fire this.)
        function redrawForFilters() {
            selectedIds.clear();
            syncCheckboxesToSelection();
            table.draw();
            syncExportLink();
        }

        $('#filterForm').on('submit', function (e) { e.preventDefault(); redrawForFilters(); });
        $('#f_device, #f_company, #f_sync').on('change', redrawForFilters);
        $('#clearFilters').on('click', function () {
            $('#f_device, #f_company, #f_sync, #f_employee').val('');
            redrawForFilters();
        });

        // --- Row selection (persists across pages/redraws; a plain JS Set of ids) ---
        var selectedIds = new Set();

        function syncCheckboxesToSelection() {
            $('#attendance tbody .row-select').each(function () {
                $(this).prop('checked', selectedIds.has(String($(this).val())));
            });
            var visible = $('#attendance tbody .row-select');
            var allChecked = visible.length > 0 && visible.filter(':checked').length === visible.length;
            $('#selectAllOnPage').prop('checked', allChecked);
            updateToolbar();
        }

        function updateToolbar() {
            $('#selCount').text(selectedIds.size);
            $('#selectionToolbar').toggleClass('d-none', selectedIds.size === 0);
        }

        $('#attendance tbody').on('change', '.row-select', function () {
            var id = String($(this).val());
            if (this.checked) { selectedIds.add(id); } else { selectedIds.delete(id); }
            updateToolbar();
        });

        $('#selectAllOnPage').on('change', function () {
            var checked = this.checked;
            $('#attendance tbody .row-select').each(function () {
                $(this).prop('checked', checked);
                var id = String($(this).val());
                if (checked) { selectedIds.add(id); } else { selectedIds.delete(id); }
            });
            updateToolbar();
        });

        $('#clearSelectionBtn').on('click', function () {
            selectedIds.clear();
            syncCheckboxesToSelection();
        });

        // --- Bulk actions ---
        function showAlert(type, message) {
            var $alert = $('<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert"></div>')
                .text(message)
                .append('<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>');
            $('#ajaxAlerts').empty().append($alert);
        }

        function postSelection(url, extra) {
            return $.ajax({
                url: url,
                method: 'POST',
                data: Object.assign({ ids: Array.from(selectedIds) }, extra || {}),
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
            });
        }

        $('#syncSelectedBtn').on('click', function () {
            if (selectedIds.size === 0) { return; }
            postSelection('{{ route('attendance.sync-selected') }}')
                .done(function (res) {
                    showAlert('success', res.message);
                    selectedIds.clear();
                    table.ajax.reload(null, false);
                })
                .fail(function (xhr) { showAlert('danger', xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Sync failed.'); });
        });

        $('#excludeSelectedBtn').on('click', function () {
            if (selectedIds.size === 0) { return; }
            postSelection('{{ route('attendance.exclude') }}', { excluded: 1 })
                .done(function (res) {
                    showAlert('success', res.message);
                    selectedIds.clear();
                    table.ajax.reload(null, false);
                })
                .fail(function (xhr) { showAlert('danger', xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Exclude failed.'); });
        });

        $('#includeSelectedBtn').on('click', function () {
            if (selectedIds.size === 0) { return; }
            postSelection('{{ route('attendance.exclude') }}', { excluded: 0 })
                .done(function (res) {
                    showAlert('success', res.message);
                    selectedIds.clear();
                    table.ajax.reload(null, false);
                })
                .fail(function (xhr) { showAlert('danger', xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Include failed.'); });
        });

        $('#deleteSelectedBtn').on('click', function () {
            if (selectedIds.size === 0) { return; }
            var count = selectedIds.size;
            if (!confirm('Delete ' + count + ' punch(es)? This cannot be undone. Punches already synced to payroll will only be removed from ADMS — payroll keeps its own copy.')) {
                return;
            }
            postSelection('{{ route('attendance.delete') }}')
                .done(function (res) {
                    showAlert('success', res.message);
                    selectedIds.clear();
                    table.ajax.reload(null, false);
                })
                .fail(function (xhr) { showAlert('danger', xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Delete failed.'); });
        });
    });
</script>
@endpush
