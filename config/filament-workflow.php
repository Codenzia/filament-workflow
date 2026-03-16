<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Queue Name
    |--------------------------------------------------------------------------
    |
    | The queue name used for workflow automation jobs (evaluation, delayed
    | node execution). Use a dedicated queue to prevent automation jobs
    | from blocking your default queue.
    |
    */
    'queue' => 'automations',

    /*
    |--------------------------------------------------------------------------
    | Max Nodes Per Run
    |--------------------------------------------------------------------------
    |
    | Maximum number of nodes that can be executed in a single workflow run.
    | Prevents infinite loops when condition chains create cycles.
    |
    */
    'max_nodes_per_run' => 50,

    /*
    |--------------------------------------------------------------------------
    | Time Trigger Interval
    |--------------------------------------------------------------------------
    |
    | How often (in minutes) the time-based trigger command runs.
    | Used by the scheduler to check for overdue tasks, approaching
    | due dates, and other time-based triggers.
    |
    */
    'time_trigger_interval' => 15,

    /*
    |--------------------------------------------------------------------------
    | Deduplication Window
    |--------------------------------------------------------------------------
    |
    | Number of hours before the same trigger can re-fire for the same
    | model+workflow combination. Prevents repeated execution of
    | time-based triggers.
    |
    */
    'dedup_window_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain execution logs before auto-cleanup.
    | Set to null to disable auto-cleanup.
    |
    */
    'log_retention_days' => 90,
];
