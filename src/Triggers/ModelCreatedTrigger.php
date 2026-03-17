<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Triggers;

use Codenzia\FilamentWorkflow\Engine\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class ModelCreatedTrigger implements TriggerInterface
{
    public static function label(): string
    {
        return 'Model Created';
    }

    public function matches(Model $model, array $config, array $context): bool
    {
        return ($context['event'] ?? null) === 'created';
    }

    public static function configSchema(): array
    {
        return [];
    }
}
