<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Actions;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Illuminate\Database\Eloquent\Model;

class ChangeFieldAction implements ActionHandlerInterface
{
    public static function label(): string
    {
        return 'Change Field';
    }

    /**
     * Update a field on the model.
     *
     * Config keys:
     * - field: string (required) — the field to update
     * - value: mixed (required) — the new value
     */
    public function execute(Model $model, array $config, array $context): array
    {
        $field = $config['field'] ?? null;
        $value = $config['value'] ?? null;

        if (! $field) {
            return ['error' => 'No field specified'];
        }

        $oldValue = $model->getAttribute($field);
        $model->update([$field => $value]);

        return [
            'action' => 'change_field',
            'field' => $field,
            'from' => $oldValue,
            'to' => $value,
        ];
    }
}
