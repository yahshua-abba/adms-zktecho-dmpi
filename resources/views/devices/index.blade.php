@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>{{ $lable }}</h2>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <table class="table table-bordered align-middle" id="devices">
            <thead>
                <tr>
                    <th>Serial Number</th>
                    <th>Name</th>
                    <th>Location</th>
                    <th>Direction</th>
                    <th>Payroll device</th>
                    <th>Online</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($log as $d)
                    <tr>
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
                            <select form="dev-{{ $d->id }}" name="payroll_device_code" class="form-select form-select-sm">
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
@endsection

@push('scripts')
<script>
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
                });
            })
            .catch(function () { /* ignore transient errors */ });
    }, 60000);
</script>
@endpush
