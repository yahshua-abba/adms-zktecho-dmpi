<?php

return [
    /*
    | How many days of raw device_log / finger_log to keep before the
    | logs:prune command deletes them. Attendance records are never pruned.
    */
    'log_retention_days' => (int) env('ADMS_LOG_RETENTION_DAYS', 30),

    /*
    | The single dashboard login. There's no users table — this is a one-admin
    | edge box, so the credential pair lives in .env rather than a database.
    | If either is left unset, login is refused (see AuthController::login).
    */
    'auth' => [
        'username' => env('ADMS_AUTH_USERNAME'),
        'password' => env('ADMS_AUTH_PASSWORD'),
    ],
];
