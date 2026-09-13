<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Tests\Fixtures;

use Codenzia\FilamentWorkflow\Engine\Contracts\TimeTriggerInterface;
use Codenzia\FilamentWorkflow\Engine\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DueTimeTrigger implements TimeTriggerInterface, TriggerInterface
{
    public static function label(): string
    {
        return 'Due';
    }

    public function matches(Model $model, array $config, array $context): bool
    {
        return true;
    }

    public function matchingModels(Builder $query, array $config): Builder
    {
        return $query->where('status', 'due');
    }

    public static function configSchema(): array
    {
        return [];
    }
}
