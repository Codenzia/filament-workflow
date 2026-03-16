<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
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
}
