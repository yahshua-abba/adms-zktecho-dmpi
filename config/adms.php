<?php

return [
    /*
    | Maximum commands returned to a clock in one /iclock/getrequest poll.
    | Large roster refreshes can contain thousands of USERINFO rows; keeping the
    | response small prevents a clock from accepting only the front of the body
    | while the server incorrectly marks the entire roster as handed over.
    */
    'device_command_batch_size' => (int) env('ADMS_DEVICE_COMMAND_BATCH_SIZE', 50),

    /*
    | How many days of routine log rows to keep before the logs:prune command
    | deletes them — the raw device_log / finger_log, the DMPI call log, download
    | and scheduled-job runs, finished device commands, and the info-level lines
    | on Server Activity. Attendance records are never pruned.
    */
    'log_retention_days' => (int) env('ADMS_LOG_RETENTION_DAYS', 30),

    /*
    | How many days to keep the *interesting* rows: warnings and errors on Server
    | Activity, and rejected device payloads.
    |
    | Much longer than the routine window, because volume and value point in
    | opposite directions. Nearly every activity row is the every-minute punch push
    | reporting nothing happened — 1,440 a day, worthless within a week. Warnings
    | and errors are a handful a day and are the whole reason anyone opens the
    | page; "the roster pull has been failing — since when?" is a question about
    | months. A year of them costs almost nothing to keep.
    |
    | Values below ADMS_LOG_RETENTION_DAYS are ignored: deleting the errors before
    | the routine rows around them is never what anyone means.
    */
    'error_retention_days' => (int) env('ADMS_ERROR_RETENTION_DAYS', 365),

    /*
    | The single dashboard login. There's no users table — this is a one-admin
    | edge box, so the credential pair lives in .env rather than a database.
    | If either is left unset, login is refused (see AuthController::login).
    */
    'auth' => [
        'username' => env('ADMS_AUTH_USERNAME'),
        'password' => env('ADMS_AUTH_PASSWORD'),
    ],

    'scheduler' => [
        /*
        | Restart the scheduler by itself when it is found stopped.
        |
        | On by default because the failure it covers is both common and silent:
        | schedule:work is started by hand and dies with the container, so an
        | overnight restart stops every automatic sync until somebody notices and
        | clicks a button. Set ADMS_SCHEDULER_AUTOSTART=false where something else
        | already owns the process — supervisor, systemd, a container entrypoint —
        | so the two are not both trying to start it.
        */
        'autostart' => filter_var(env('ADMS_SCHEDULER_AUTOSTART', true), FILTER_VALIDATE_BOOLEAN),

        /*
        | Shortest gap between two automatic restart attempts, in seconds. Stops a
        | refreshed dashboard, or several open tabs, from launching a pile of
        | schedulers while the first one is still booting.
        */
        'autostart_throttle' => (int) env('ADMS_SCHEDULER_AUTOSTART_THROTTLE', 60),
    ],
];
