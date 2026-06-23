<?php

return [
    /*
    | Connection to the DMPI payroll app. The edge server logs in here, pulls the
    | employee roster, and pushes attendance punches. See app/Sync/HttpPayrollClient.
    */
    'base_url' => env('PAYROLL_URL', ''),
    'username' => env('PAYROLL_USERNAME', ''),
    'password' => env('PAYROLL_PASSWORD', ''),

    // DMPI grants timekeeper access by sniffing the user-agent; must contain this.
    'user_agent' => env('PAYROLL_USER_AGENT', 'YP_TIMEKEEPER'),

    // How many unsynced punches to push per run.
    'batch_size' => (int) env('PAYROLL_BATCH_SIZE', 50),

    // DMPI's bulk read endpoints are slow (the legacy server set no timeout and
    // ran them as background jobs). Give the reads a long ceiling, in seconds.
    'timeout' => (int) env('PAYROLL_TIMEOUT', 600),
];
