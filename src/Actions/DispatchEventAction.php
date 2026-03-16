<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Illuminate\Database\Eloquent\Model;

class DispatchEventAction implements ActionHandlerInterface
{
    public static function label(): string
    {
        return 'Dispatch Event';
    }

    /**
     * Dispatch a Laravel event.
     *
     * Config keys:
     * - event_class: string (required) — FQCN of the event to dispatch
     */
    public function execute(Model $model, array $config, array $context): array
    {
        $eventClass = $config['event_class'] ?? null;

        if (! $eventClass || ! class_exists($eventClass)) {
            return ['action' => 'dispatch_event', 'skipped' => true, 'reason' => 'Invalid event class'];
        }

        event(new $eventClass($model, $context));

        return [
            'action' => 'dispatch_event',
            'event_class' => $eventClass,
        ];
    }
}
