<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Authorization Abilities
    |--------------------------------------------------------------------------
    |
    | The Gate/permission abilities checked by the WorkflowDesigner page.
    | The 'view' ability gates access to the page itself (canAccess) and
    | read-only data (execution history, scheduler status). Create/edit/
    | delete/run abilities gate the corresponding mutating operations.
    | Set each value to one of your host app's permission names.
    |
    */
    'abilities' => [
        'view' => 'view_workflow',
        'create' => 'create_workflow',
        'edit' => 'edit_workflow',
        'delete' => 'delete_workflow',
        'run_triggers' => 'run_workflow_triggers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allow-listed Event / Notification Classes
    |--------------------------------------------------------------------------
    |
    | FQCNs that DispatchEventAction / SendNotificationAction /
    | EscalateOnDeadlineAction are permitted to instantiate from stored
    | node config. A workflow node may only reference a class listed here
    | (or registered at runtime via WorkflowEngine::registerEventClass() /
    | registerNotificationClass()). Leaving these empty disables those
    | actions until classes are explicitly allow-listed.
    |
    */
    'allowed_event_classes' => [],

    'allowed_notification_classes' => [],

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
    | Run Budgets Across Delays
    |--------------------------------------------------------------------------
    |
    | A run keeps its identity across delay nodes. These bound how far a run
    | may travel once delays are involved: `max_run_hops` is the number of
    | delayed continuations one run may schedule, and `max_run_days` is the
    | wall-clock lifetime of a run measured from its first node. Reaching
    | either ends the run and records the reason in the execution log.
    | Set to 0 to disable a bound.
    |
    */
    'max_run_hops' => 100,

    'max_run_days' => 30,

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
    | Time Trigger Chunk Size
    |--------------------------------------------------------------------------
    |
    | How many candidate models the time-trigger command loads at a time.
    | The deduplication lookup runs once per chunk.
    |
    */
    'time_trigger_chunk_size' => 500,

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

    /*
    |--------------------------------------------------------------------------
    | Cross-Process Lock TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | The cache lock acquired around `WorkflowEngine::evaluate()` (and
    | delayed-resume) auto-expires after this many seconds as a failsafe
    | against a process that dies mid-run. Increase if your slowest
    | workflow run can exceed 60 seconds.
    |
    */
    'lock_ttl' => 60,

    /*
    |--------------------------------------------------------------------------
    | Lock Wait Seconds
    |--------------------------------------------------------------------------
    |
    | Maximum time a queue worker will block waiting for the per-model
    | workflow lock before giving up. Two near-simultaneous triggers on
    | the same model are serialised; if the second cannot acquire the
    | lock within this window, it is logged and skipped.
    |
    */
    'lock_wait_seconds' => 10,

    /*
    |--------------------------------------------------------------------------
    | Dispatch Deduplication Window (seconds)
    |--------------------------------------------------------------------------
    |
    | EvaluateWorkflowJob is ShouldBeUnique. While a job with the same
    | (model, trigger, context) is pending or running, additional dispatches
    | are dropped for this many seconds. Protects against observer storms
    | from rapid identical model updates.
    |
    */
    'dedup_dispatch_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Lock Contention Retry Backoff (seconds)
    |--------------------------------------------------------------------------
    |
    | A queued job that cannot acquire the per-model lock releases itself back
    | onto the queue instead of dropping the event. The delay doubles with
    | each attempt, starting at `lock_retry_base_seconds` and capped at
    | `lock_retry_max_seconds`.
    |
    */
    'lock_retry_base_seconds' => 5,

    'lock_retry_max_seconds' => 300,
];
