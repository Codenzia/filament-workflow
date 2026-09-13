<?php

declare(strict_types=1);

/**
 * EscalateOnDeadlineAction
 *
 * Escalate a record by notifying a role's members or specific users when
 * the model's deadline field has passed (optionally with a grace period).
 *
 * Inspired by RubixSmartNavigator-v2's WorkflowEngine::escalateToUsers().
 * Decoupled from any specific domain model — operates on any Eloquent
 * record with a Carbon-castable deadline attribute.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification as IlluminateNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class EscalateOnDeadlineAction implements ActionHandlerInterface
{
    public static function label(): string
    {
        return 'Escalate on Deadline';
    }

    /**
     * Config keys:
     * - deadline_field: string (required) — model attribute holding the deadline (Carbon-castable).
     * - grace_period_days: int (default 0) — escalate only if deadline + grace < now.
     * - escalate_to_role: ?string — Spatie role name; resolved via User::role(...) if available.
     * - escalate_to_user_ids: int[] — fallback recipients if role resolves empty.
     * - notification_class: string (FQCN, required) — notification to send.
     * - priority: 'low'|'normal'|'high' (default 'high') — passed to notification context.
     */
    public function execute(Model $model, array $config, array $context): array
    {
        $deadlineField = $config['deadline_field'] ?? null;
        $notificationClass = $config['notification_class'] ?? null;

        if (! $deadlineField) {
            return ['action' => 'escalate_on_deadline', 'skipped' => true, 'reason' => 'No deadline_field configured'];
        }

        if (! $notificationClass || ! class_exists($notificationClass)) {
            return ['action' => 'escalate_on_deadline', 'skipped' => true, 'reason' => "Notification class '{$notificationClass}' not found"];
        }

        if (! WorkflowEngine::isAllowedNotification($notificationClass)
            || ! is_subclass_of($notificationClass, IlluminateNotification::class)) {
            return ['action' => 'escalate_on_deadline', 'skipped' => true, 'reason' => 'Notification class not allow-listed'];
        }

        $deadline = $model->getAttribute($deadlineField);
        if (! $deadline) {
            return ['action' => 'escalate_on_deadline', 'skipped' => true, 'reason' => "Field '{$deadlineField}' has no value"];
        }

        try {
            $deadline = $deadline instanceof Carbon ? $deadline : Carbon::parse((string) $deadline);
        } catch (\Throwable $e) {
            return ['action' => 'escalate_on_deadline', 'skipped' => true, 'reason' => "Field '{$deadlineField}' is not a parseable date"];
        }

        $graceDays = (int) ($config['grace_period_days'] ?? 0);
        $threshold = $deadline->copy()->addDays($graceDays);

        if ($threshold->isFuture()) {
            return [
                'action' => 'escalate_on_deadline',
                'skipped' => true,
                'reason' => 'Deadline+grace still in the future',
                'deadline' => $deadline->toIso8601String(),
                'grace_days' => $graceDays,
            ];
        }

        // Already-escalated guard: don't re-notify within 24h for the same
        // (model, action, deadline) tuple. Looks at the audit log so a delayed
        // resume cannot escalate twice.
        if ($this->alreadyEscalatedRecently($model, $deadline)) {
            return [
                'action' => 'escalate_on_deadline',
                'skipped' => true,
                'reason' => 'Already escalated within 24h',
            ];
        }

        $recipients = $this->resolveRecipients($config);
        if ($recipients->isEmpty()) {
            return [
                'action' => 'escalate_on_deadline',
                'skipped' => true,
                'reason' => 'No eligible recipients (role empty and no user_ids)',
            ];
        }

        // An escalation that has already left the building cannot be undone
        // by a later action failing, so wait for the run to commit first.
        DB::afterCommit(fn () => Notification::send($recipients, new $notificationClass($model, [
            ...$context,
            'deadline' => $deadline->toIso8601String(),
            'grace_days' => $graceDays,
            'priority' => $config['priority'] ?? 'high',
        ])));

        return [
            'action' => 'escalate_on_deadline',
            'escalated_to' => $recipients->pluck('id')->toArray(),
            'deadline' => $deadline->toIso8601String(),
            'grace_days' => $graceDays,
        ];
    }

    /**
     * Resolve the user collection to escalate to.
     *
     * Tries the role first (via Spatie's `role()` scope if installed),
     * then falls back to explicit user IDs. Filters out missing user IDs.
     */
    protected function resolveRecipients(array $config): Collection
    {
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');

        $recipients = new Collection;

        if (! empty($config['escalate_to_role']) && method_exists($userModel, 'scopeRole')) {
            $recipients = $userModel::role($config['escalate_to_role'])->get();
        }

        if ($recipients->isEmpty() && ! empty($config['escalate_to_user_ids'])) {
            $ids = array_filter((array) $config['escalate_to_user_ids']);
            $recipients = $userModel::whereIn('id', $ids)->get();
        }

        return $recipients;
    }

    /**
     * True if a successful escalate_on_deadline ran for this model with
     * the same deadline within the last 24 hours.
     */
    protected function alreadyEscalatedRecently(Model $model, Carbon $deadline): bool
    {
        return WorkflowExecutionLog::query()
            ->where('model_type', $model::class)
            ->where('model_id', $model->getKey())
            ->where('result', 'success')
            ->where('executed_at', '>=', now()->subDay())
            ->where('details->action', 'escalate_on_deadline')
            ->where('details->deadline', $deadline->toIso8601String())
            ->exists();
    }

    public static function configSchema(): array
    {
        return [
            TextInput::make('config.deadline_field')
                ->label('Deadline Field')
                ->placeholder('e.g., due_at, deadline')
                ->helperText('Model attribute holding the deadline (Carbon-castable)')
                ->required(),
            TextInput::make('config.grace_period_days')
                ->label('Grace Period (days)')
                ->numeric()
                ->default(0)
                ->minValue(0),
            TextInput::make('config.escalate_to_role')
                ->label('Role')
                ->placeholder('e.g., manager (requires spatie/laravel-permission)'),
            TagsInput::make('config.escalate_to_user_ids')
                ->label('Or Specific User IDs')
                ->placeholder('Press Enter after each ID')
                ->helperText('Fallback when role is empty or not configured'),
            Select::make('config.notification_class')
                ->label('Notification Class')
                ->options(fn (): array => WorkflowEngine::getAllowedNotificationClasses())
                ->searchable()
                ->required()
                ->helperText('Only allow-listed notification classes can be selected.'),
            Select::make('config.priority')
                ->label('Priority')
                ->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'])
                ->default('high'),
        ];
    }
}
