<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Triggers;

use Codenzia\FilamentWorkflow\Engine\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class ModelUpdatedTrigger implements TriggerInterface
{
    public static function label(): string
    {
        return 'Model Updated';
    }

    public function matches(Model $model, array $config, array $context): bool
    {
        return ($context['event'] ?? null) === 'updated';
    }
}
