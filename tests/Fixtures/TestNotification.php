<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

class TestNotification extends Notification
{
    public function __construct(
        public Model $model,
        public array $context = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
