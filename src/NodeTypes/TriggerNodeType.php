<?php

declare(strict_types=1);

/**
 * TriggerNodeType - Visual definition for trigger nodes in the workflow diagrammer.
 *
 * Green diamond shape, entry point of workflow.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\NodeTypes;

use Codenzia\FilamentDiagrammer\Enums\NodeShape;
use Codenzia\FilamentDiagrammer\NodeTypes\BaseNodeType;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Filament\Forms\Components\Select;

class TriggerNodeType extends BaseNodeType
{
    public static function label(): string
    {
        return 'Trigger';
    }

    public static function description(): ?string
    {
        return __('filament-workflow::node-types.trigger.description');
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-bolt';
    }

    public static function defaultColor(): ?string
    {
        return '#22c55e';
    }

    public static function defaultShape(): NodeShape
    {
        return NodeShape::DIAMOND;
    }

    public static function defaultWidth(): ?float
    {
        return 180;
    }

    public static function editFormSchema(): array
    {
        $triggers = WorkflowEngine::getTriggers();
        $options = [];
        foreach ($triggers as $key => $class) {
            $options[$key] = $class::label();
        }

        $schema = [
            Select::make('type_config')
                ->label('Trigger Type')
                ->options($options)
                ->required()
                ->live(),
        ];

        // Use ->hidden() for trigger-type gating so it doesn't overwrite
        // any ->visible() the field already has for its own show/hide logic.
        foreach ($triggers as $key => $class) {
            foreach ($class::configSchema() as $field) {
                $schema[] = $field->hidden(fn (callable $get) => $get('type_config') !== $key);
            }
        }

        return $schema;
    }

    public static function allowedOutputTypes(): array
    {
        return [ConditionNodeType::class, ActionNodeType::class, DelayNodeType::class];
    }

    public static function maxInputConnections(): ?int
    {
        return 0; // Triggers are entry points — no incoming connections
    }
}
