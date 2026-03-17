<?php

declare(strict_types=1);

return [
    'trigger' => [
        'description' => 'Entry point that starts a workflow when an event occurs — e.g. model created, updated, or a field value changes. Each workflow begins with at least one trigger.',
    ],
    'condition' => [
        'description' => 'Evaluates a field against a value using one of 12 operators (equals, contains, greater than, etc.). Branches the flow into Yes and No paths based on the result.',
    ],
    'delay' => [
        'description' => 'Pauses workflow execution for a set duration (minutes, hours, or days). Uses a queued job to resume the flow automatically after the wait period.',
    ],
    'action' => [
        'description' => 'Executes an operation on the model — change a field value, send a notification, or dispatch a custom event. Connect after triggers, conditions, or delays.',
    ],
];
