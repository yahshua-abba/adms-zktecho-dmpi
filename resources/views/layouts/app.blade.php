<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADMS Server</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{--
        Restore the collapsed-sidebar preference *before* the stylesheet paints.
        Doing it from the deferred bundle would render the wide sidebar first and
        snap it narrow a frame later.
    --}}
    <script>
        try {
            if (localStorage.getItem('adms.sidebarCollapsed') === '1') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        } catch (e) { /* private mode / storage disabled — just stay expanded */ }
    </script>
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
                    <a class="app-brand" id="appSidebarBrand" href="{{ route('monitoring') }}" title="ADMS Server">
                        <i class="bi bi-fingerprint"></i>
                        <span class="app-nav-label">ADMS Server</span>
                    </a>
                    <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close navigation"></button>
                </div>

                @php
                    // One list per section. `title` is what the browser shows on hover once
                    // the rail is collapsed and the label itself is hidden.
                    $navSections = [
                        ['heading' => null, 'items' => [
                            ['label' => 'Monitoring',     'icon' => 'speedometer2',      'url' => route('monitoring'),         'active' => $isMonitoring],
                            ['label' => 'Devices',        'icon' => 'hdd-network',       'url' => route('devices.index'),      'active' => $isDevices],
                            ['label' => 'Employees',      'icon' => 'people',            'url' => route('employees.index'),    'active' => $r === 'employees.index'],
                            ['label' => 'Attendance',     'icon' => 'clock-history',     'url' => route('devices.Attendance'), 'active' => $isAttendance],
                        ]],
                        ['heading' => 'Logs', 'items' => [
                            ['label' => 'Server Activity', 'icon' => 'activity',          'url' => route('activity.index'),     'active' => $r === 'activity.index'],
                            ['label' => 'Scheduler',       'icon' => 'stopwatch',         'url' => route('scheduler.log'),      'active' => $r === 'scheduler.log'],
                            ['label' => 'Device Check-ins','icon' => 'broadcast',         'url' => route('devices.DeviceLog'),  'active' => $r === 'devices.DeviceLog'],
                            ['label' => 'Device Messages', 'icon' => 'chat-square-dots',  'url' => route('devices.FingerLog'),  'active' => $r === 'devices.FingerLog'],
                            ['label' => 'DMPI Calls',      'icon' => 'cloud-arrow-down',  'url' => route('dmpi.calls'),         'active' => $r === 'dmpi.calls'],
                        ]],
                        ['heading' => 'Support', 'items' => [
                            ['label' => 'Settings', 'icon' => 'sliders',         'url' => route('settings'), 'active' => $r === 'settings'],
                            ['label' => 'Help',     'icon' => 'question-circle', 'url' => route('help'),     'active' => $r === 'help'],
                        ]],
                    ];
                @endphp

                <nav class="app-nav">
                    @foreach ($navSections as $section)
                        @if ($section['heading'])
                            <div class="app-nav-heading"><span class="app-nav-label">{{ $section['heading'] }}</span></div>
                        @endif
                        <ul class="nav flex-column">
                            @foreach ($section['items'] as $item)
                                <li class="nav-item">
                                    <a class="nav-link {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}" title="{{ $item['label'] }}">
                                        <i class="bi bi-{{ $item['icon'] }}"></i>
                                        <span class="app-nav-label">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </nav>
            </div>
        </aside>

        <div class="app-main">
            <header class="app-topbar">
                <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Toggle navigation">
                    <i class="bi bi-list"></i>
                </button>
                <span class="fw-bold d-lg-none">ADMS Server</span>
                {{-- Desktop only: below lg the sidebar is a drawer, so "collapse" has no meaning there. --}}
                <button class="btn btn-sm btn-outline-secondary d-none d-lg-inline-flex" type="button" id="sidebarCollapseToggle" aria-controls="appSidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar">
                    <i class="bi bi-list"></i>
                </button>
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
