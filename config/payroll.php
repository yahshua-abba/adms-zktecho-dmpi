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

    /*
    | Retry toward DMPI. Only transport-level failures are retried — a connection
    | error, a 5xx, or a 429. A 401 is not retried here: HttpPayrollClient handles
    | that by re-authenticating once, and hammering a refused login is how you get
    | the account locked out.
    */
    'retries' => (int) env('PAYROLL_RETRIES', 3),

    // First backoff wait in milliseconds; doubles each attempt, capped at 30s.
    'retry_base_ms' => (int) env('PAYROLL_RETRY_BASE_MS', 2000),

    /*
    | Ceiling for a sync process. Its job is to make a runaway payload fail loudly
    | instead of quietly starving the box, which is what an unlimited memory_limit
    | does.
    |
    | Measured, not guessed: the roster (~2.6 MB of JSON, ~9k employees) peaks
    | around 64 MB — decoded PHP arrays cost roughly 10-20x the JSON. The device
    | payload is far bigger; the incremental Bugo pull alone is 42 MB of JSON with
    | 56k assignments, and the unfiltered one is larger still. 512M was tried and
    | killed a device pull mid-parse, so the ceiling has to clear that with room.
    */
    'memory_limit' => env('PAYROLL_MEMORY_LIMIT', '2048M'),
];
