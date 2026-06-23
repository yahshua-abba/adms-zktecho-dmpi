@extends('layouts.app')

@section('content')
    <h2 class="mb-1">{{ $title }}</h2>
    @isset($intro)
        <p class="text-muted mb-4"><i class="bi bi-info-circle"></i> {{ $intro }}</p>
    @endisset

    <div class="filter-bar">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" id="f_date_from" value="{{ now()->subDays(7)->toDateString() }}" class="form-control">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" id="f_date_to" value="{{ now()->toDateString() }}" class="form-control">
            </div>
            @if ($showDevice)
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">Device</label>
                    <select id="f_device" class="form-select">
                        <option value="">All devices</option>
                        @foreach ($devices as $d)
                            <option value="{{ $d->no_sn }}">{{ $d->nama ? $d->nama.' — ' : '' }}{{ $d->no_sn }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1">Search payload</label>
                <input type="text" id="f_q" class="form-control" placeholder="text in data/url">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
                <button type="button" id="clearFilters" class="btn btn-outline-secondary">Clear</button>
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="logs">
                <thead>
                    <tr>
                        @foreach ($columns as $c)
                            <th>{{ $c['title'] }}</th>
                        @endforeach
                    </tr>
                </thead>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        var hasDevice = @json($showDevice);
        var table = $('#logs').DataTable({
            processing: true,
            serverSide: true,
            searching: false,
            order: [],
            ajax: {
                url: '{{ $ajax }}',
                data: function (d) {
                    d.date_from = $('#f_date_from').val();
                    d.date_to = $('#f_date_to').val();
                    d.q = $('#f_q').val();
                    if (hasDevice) { d.device = $('#f_device').val(); }
                }
            },
            columns: @json($columns).map(function (c) {
                return { data: c.data, name: c.data, orderable: c.orderable !== false };
            })
        });

        $('#filterForm').on('submit', function (e) { e.preventDefault(); table.draw(); });
        $('#f_device').on('change', function () { table.draw(); });
        $('#clearFilters').on('click', function () {
            $('#f_q').val('');
            $('#f_device').val('');
            table.draw();
        });
    });
</script>
@endpush
