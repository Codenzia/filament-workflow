<?php

declare(strict_types=1);

/**
 * ActionNodeType - Visual definition for action nodes in the workflow diagrammer.
 *
 * Purple rectangle shape, executes an operation on the model.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\NodeTypes;

use Codenzia\FilamentDiagrammer\Enums\NodeShape;
use Codenzia\FilamentDiagrammer\NodeTypes\BaseNodeType;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Filament\Forms\Components\Select;

class ActionNodeType extends BaseNodeType
{
    public static function label(): string
    {
        return 'Action';
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-play';
    }

    public static function defaultColor(): ?string
    {
        return '#8b5cf6';
    }

    public static function defaultShape(): NodeShape
    {
        return NodeShape::RECTANGLE;
    }

    public static function editFormSchema(): array
    {
        $actions = WorkflowEngine::getActions();
        $options = [];
        foreach ($actions as $key => $class) {
            $options[$key] = $class::label();
        }

        return [
            Select::make('type_config')
                ->label('Action Type')
                ->options($options)
                ->required()
                ->live(),
        ];
    }

    public static function allowedInputTypes(): array
    {
        return [TriggerNodeType::class, ConditionNodeType::class, DelayNodeType::class];
    }
}
