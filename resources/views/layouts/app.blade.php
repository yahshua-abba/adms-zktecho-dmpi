<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADMS Server</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.0.1/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f6f8fa; }
        .navbar-brand { font-weight: 600; }
        .stat-card { border: none; border-radius: .75rem; box-shadow: 0 1px 3px rgba(16,24,40,.08); }
        .stat-card .stat-value { font-size: 2rem; font-weight: 700; line-height: 1; }
        .stat-card .stat-label { color: #667085; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
        .filter-bar { background:#fff; border-radius:.75rem; box-shadow:0 1px 3px rgba(16,24,40,.08); padding:1rem; margin-bottom:1rem; }
        .table-card { background:#fff; border-radius:.75rem; box-shadow:0 1px 3px rgba(16,24,40,.08); padding:1rem; }
        .table thead th { font-size:.78rem; text-transform:uppercase; letter-spacing:.03em; color:#667085; }
        @media (max-width: 991.98px) {
            .navbar-collapse { position: fixed; top: 56px; left: -100%; padding: 15px; width: 75%; height: 100%;
                background-color: #fff; transition: all 0.3s ease-in-out; z-index: 1000; }
            .navbar-collapse.show { left: 0; }
            body.menu-open { overflow: hidden; }
            .navbar-toggler { z-index: 1001; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="{{ route('monitoring') }}"><i class="bi bi-fingerprint"></i> ADMS Server</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    @php $r = Route::currentRouteName(); @endphp
                    <li class="nav-item"><a class="nav-link {{ $r === 'monitoring' ? 'active' : '' }}" href="{{ route('monitoring') }}">Monitoring</a></li>
                    <li class="nav-item"><a class="nav-link {{ $r === 'devices.index' ? 'active' : '' }}" href="{{ route('devices.index') }}">Devices</a></li>
                    <li class="nav-item"><a class="nav-link {{ $r === 'employees.index' ? 'active' : '' }}" href="{{ route('employees.index') }}">Employees</a></li>
                    <li class="nav-item"><a class="nav-link {{ $r === 'devices.Attendance' ? 'active' : '' }}" href="{{ route('devices.Attendance') }}">Attendance</a></li>
                    <li class="nav-item"><a class="nav-link {{ $r === 'help' ? 'active' : '' }}" href="{{ route('help') }}">Help</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="logsDrop" role="button" data-bs-toggle="dropdown" aria-expanded="false">Logs</a>
                        <ul class="dropdown-menu" aria-labelledby="logsDrop">
                            <li><a class="dropdown-item" href="{{ route('activity.index') }}">Server Activity</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">Device diagnostics</h6></li>
                            <li><a class="dropdown-item" href="{{ route('devices.DeviceLog') }}">Device Check-ins</a></li>
                            <li><a class="dropdown-item" href="{{ route('devices.FingerLog') }}">Device Messages</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
            <span class="navbar-text d-none d-lg-block text-light">{{ now()->format('D, d M Y H:i') }}</span>
        </div>
    </nav>

    <div class="container mt-4 mb-5">
        @yield('content')
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
    <script src="https://cdn.datatables.net/1.11.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.navbar-toggler').on('click', function() { $('body').toggleClass('menu-open'); });
            $('.nav-link').on('click', function() {
                if ($(window).width() < 992) { $('.navbar-collapse').removeClass('show'); $('body').removeClass('menu-open'); }
            });
        });
    </script>
    @stack('scripts')
</body>
</html>
