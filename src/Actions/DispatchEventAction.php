<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

        if (! WorkflowEngine::isAllowedEvent($eventClass)) {
            return ['action' => 'dispatch_event', 'skipped' => true, 'reason' => 'Event class not allow-listed'];
        }

        // Listeners can send mail, call webhooks or queue work on another
        // connection — effects no database rollback can undo. Hold the event
        // until the workflow run's transaction has actually committed.
        DB::afterCommit(fn () => event(new $eventClass($model, $context)));

        return [
            'action' => 'dispatch_event',
            'event_class' => $eventClass,
        ];
    }

    public static function configSchema(): array
    {
        return [
            Select::make('config.event_class')
                ->label('Event Class')
                ->options(fn (): array => WorkflowEngine::getAllowedEventClasses())
                ->searchable()
                ->required()
                ->helperText('Only allow-listed event classes can be selected.'),
        ];
    }
}
