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
                <button type="submit" class="btn btn-success"><i class="bi bi-arrow-repeat"></i> Sync to payroll now</button>
            </form>
        </div>
    </div>

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

    <div class="table-card">
        <table class="table table-hover align-middle w-100" id="attendance">
            <thead>
                <tr>
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
    $(function () {
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
            ajax: {
                url: '{{ route('devices.Attendance') }}',
                data: function (d) { Object.assign(d, currentFilters()); }
            },
            columns: [
                { data: 'id', name: 'attendances.id' },
                { data: 'timestamp', name: 'attendances.timestamp' },
                { data: 'received_at', name: 'attendances.created_at' },
                { data: 'device_display', orderable: false, searchable: false },
                { data: 'inout', orderable: false, searchable: false },
                { data: 'employee_display', orderable: false, searchable: false },
                { data: 'sync_status', orderable: false, searchable: false },
                { data: 'synced_at', name: 'attendances.sync_time' },
            ]
        });

        // Keep the Export link pointed at the same filters the table is showing,
        // so "Export CSV" downloads exactly what's on screen (or everything when
        // filters are empty).
        function syncExportLink() {
            var params = $.param(currentFilters());
            $('#exportBtn').attr('href', '{{ route('attendance.export') }}' + (params ? '?' + params : ''));
        }
        syncExportLink();

        $('#filterForm').on('submit', function (e) { e.preventDefault(); table.draw(); syncExportLink(); });
        $('#f_device, #f_company, #f_sync').on('change', function () { table.draw(); syncExportLink(); });
        $('#clearFilters').on('click', function () {
            $('#f_device, #f_company, #f_sync, #f_employee').val('');
            table.draw();
            syncExportLink();
        });
    });
</script>
@endpush
