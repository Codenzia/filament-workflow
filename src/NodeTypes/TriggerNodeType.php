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

    public static function editFormSchema(): array
    {
        $triggers = WorkflowEngine::getTriggers();
        $options = [];
        foreach ($triggers as $key => $class) {
            $options[$key] = $class::label();
        }

        return [
            Select::make('type_config')
                ->label('Trigger Type')
                ->options($options)
                ->required()
                ->live(),
        ];
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
