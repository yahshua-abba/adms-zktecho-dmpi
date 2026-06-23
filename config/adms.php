<?php

return [
    /*
    | How many days of raw device_log / finger_log to keep before the
    | logs:prune command deletes them. Attendance records are never pruned.
    */
    'log_retention_days' => (int) env('ADMS_LOG_RETENTION_DAYS', 30),
];
