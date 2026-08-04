<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADMS Server</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/js/app.js', 'resources/sass/app.scss'])
</head>
<body>
    @php
        $r = (string) Route::currentRouteName();

        // Several routes render "into" one nav entry: a single device's punch log
        // belongs under Devices, the CSV export under Attendance, and the
        // dashboard/health redirects under Monitoring.
        $isMonitoring = in_array($r, ['monitoring', 'dashboard', 'health']);
        $isAttendance = in_array($r, ['devices.Attendance', 'attendance.export']);
        $isDeviceLogs = in_array($r, ['devices.DeviceLog', 'devices.FingerLog']);
        $isDevices = str_starts_with($r, 'devices.') && ! $isAttendance && ! $isDeviceLogs;
    @endphp

    <div class="app-shell">
        <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarBrand">
            <div class="app-sidebar-inner">
                <div class="app-sidebar-header">
                    <a class="app-brand" id="appSidebarBrand" href="{{ route('monitoring') }}">
                        <i class="bi bi-fingerprint"></i>
                        <span>ADMS Server</span>
                    </a>
                    <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close navigation"></button>
                </div>

                <nav class="app-nav">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link {{ $isMonitoring ? 'active' : '' }}" href="{{ route('monitoring') }}">
                                <i class="bi bi-speedometer2"></i> Monitoring
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $isDevices ? 'active' : '' }}" href="{{ route('devices.index') }}">
                                <i class="bi bi-hdd-network"></i> Devices
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $r === 'employees.index' ? 'active' : '' }}" href="{{ route('employees.index') }}">
                                <i class="bi bi-people"></i> Employees
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $isAttendance ? 'active' : '' }}" href="{{ route('devices.Attendance') }}">
                                <i class="bi bi-clock-history"></i> Attendance
                            </a>
                        </li>
                    </ul>

                    <div class="app-nav-heading">Logs</div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link {{ $r === 'activity.index' ? 'active' : '' }}" href="{{ route('activity.index') }}">
                                <i class="bi bi-activity"></i> Server Activity
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $r === 'devices.DeviceLog' ? 'active' : '' }}" href="{{ route('devices.DeviceLog') }}">
                                <i class="bi bi-broadcast"></i> Device Check-ins
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $r === 'devices.FingerLog' ? 'active' : '' }}" href="{{ route('devices.FingerLog') }}">
                                <i class="bi bi-chat-square-dots"></i> Device Messages
                            </a>
                        </li>
                    </ul>

                    <div class="app-nav-heading">Support</div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link {{ $r === 'help' ? 'active' : '' }}" href="{{ route('help') }}">
                                <i class="bi bi-question-circle"></i> Help
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </aside>

        <div class="app-main">
            <header class="app-topbar">
                <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Toggle navigation">
                    <i class="bi bi-list"></i>
                </button>
                <span class="fw-bold d-lg-none">ADMS Server</span>
                <div class="ms-auto d-flex align-items-center gap-3">
                    <span class="small text-muted">{{ now()->format('D, d M Y H:i') }}</span>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-right"></i> Log out</button>
                    </form>
                </div>
            </header>

            <main class="app-content">
                @yield('content')
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
