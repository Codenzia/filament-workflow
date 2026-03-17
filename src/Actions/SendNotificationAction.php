<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

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
        if ($notificationClass && class_exists($notificationClass)) {
            $user->notify(new $notificationClass($model, $context));
        }

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
            TextInput::make('config.notification_class')
                ->label('Notification Class (FQCN)')
                ->placeholder('App\\Notifications\\...'),
            TextInput::make('config.message')
                ->label('Fallback Message'),
        ];
    }
}
