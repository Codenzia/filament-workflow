<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification as IlluminateNotification;
use Illuminate\Support\Facades\DB;

class SendNotificationAction implements ActionHandlerInterface
{
    public static function label(): string
    {
        return 'Send Notification';
    }

    /**
     * Send a notification to a user or set of users.
     *
     * Config keys:
     * - user_id: int|null — specific user ID to notify
     * - user_field: string|null — model field containing user ID (e.g., 'assigned_to')
     * - notification_class: string — FQCN of the notification to send
     * - message: string|null — fallback message if no notification class
     */
    public function execute(Model $model, array $config, array $context): array
    {
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        $userId = $config['user_id'] ?? null;
        $userField = $config['user_field'] ?? null;

        if ($userField && ! $userId) {
            $userId = $model->getAttribute($userField);
        }

        if (! $userId) {
            return ['action' => 'send_notification', 'skipped' => true, 'reason' => 'No user ID resolved'];
        }

        $user = $userModel::find($userId);
        if (! $user) {
            return ['action' => 'send_notification', 'skipped' => true, 'reason' => "User {$userId} not found"];
        }

        $notificationClass = $config['notification_class'] ?? null;

        if (! $notificationClass || ! class_exists($notificationClass)) {
            // Nothing is delivered without a notification class, so the node
            // did not do what it says it does. Report the skip instead of a
            // send that never happened.
            return ['action' => 'send_notification', 'skipped' => true, 'reason' => 'No notification class configured'];
        }

        if (! WorkflowEngine::isAllowedNotification($notificationClass)
            || ! is_subclass_of($notificationClass, IlluminateNotification::class)) {
            return ['action' => 'send_notification', 'skipped' => true, 'reason' => 'Notification class not allow-listed'];
        }

        // Mail, SMS and webhook channels cannot be rolled back with the
        // workflow's transaction — deliver only once the run has committed.
        DB::afterCommit(fn () => $user->notify(new $notificationClass($model, $context)));

        return [
            'action' => 'send_notification',
            'user_id' => $userId,
            'notification_class' => $notificationClass,
        ];
    }

    public static function configSchema(): array
    {
        return [
            TextInput::make('config.user_field')
                ->label('User Field')
                ->placeholder('e.g., assigned_to_user_id')
                ->helperText('Model field containing the user ID to notify'),
            TextInput::make('config.user_id')
                ->label('Or Specific User ID')
                ->numeric(),
            Select::make('config.notification_class')
                ->label('Notification Class')
                ->options(fn (): array => WorkflowEngine::getAllowedNotificationClasses())
                ->searchable()
                ->helperText('Only allow-listed notification classes can be selected.'),
            TextInput::make('config.message')
                ->label('Fallback Message'),
        ];
    }
}
