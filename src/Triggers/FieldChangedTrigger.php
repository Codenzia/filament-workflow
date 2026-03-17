<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Triggers;

use Codenzia\FilamentWorkflow\Engine\Contracts\TriggerInterface;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

class FieldChangedTrigger implements TriggerInterface
{
    public static function label(): string
    {
        return 'Field Changed';
    }

    /**
     * Matches when a specific field changed, optionally from/to specific values.
     *
     * Config keys:
     * - field: string (required) — the field name to watch
     * - from: mixed (optional) — required old value
     * - to: mixed (optional) — required new value
     */
    public function matches(Model $model, array $config, array $context): bool
    {
        $field = $config['field'] ?? null;
        if (! $field) {
            return false;
        }

        $changedFields = $context['changed_fields'] ?? [];
        if (! in_array($field, $changedFields)) {
            return false;
        }

        // Check 'from' constraint
        if (isset($config['from'])) {
            $oldValue = $context['old'][$field] ?? null;
            if ($oldValue != $config['from']) {
                return false;
            }
        }

        // Check 'to' constraint
        if (isset($config['to'])) {
            $newValue = $context['new'][$field] ?? $model->getAttribute($field);
            if ($newValue != $config['to']) {
                return false;
            }
        }

        return true;
    }

    public static function configSchema(): array
    {
        return [
            Select::make('config.field')
                ->label('Field')
                ->options(fn () => WorkflowEngine::getModelFields())
                ->searchable()
                ->required(),
            TextInput::make('config.from')
                ->label('From Value (optional)')
                ->placeholder('Any'),
            TextInput::make('config.to')
                ->label('To Value (optional)')
                ->placeholder('Any'),
        ];
    }
}
