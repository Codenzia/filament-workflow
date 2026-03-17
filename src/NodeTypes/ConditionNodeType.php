<?php

declare(strict_types=1);

/**
 * ConditionNodeType - Visual definition for condition nodes in the workflow diagrammer.
 *
 * Yellow diamond shape with Yes/No output ports.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\NodeTypes;

use Codenzia\FilamentDiagrammer\Enums\NodeShape;
use Codenzia\FilamentDiagrammer\NodeTypes\BaseNodeType;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Enums\ConditionOperatorEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class ConditionNodeType extends BaseNodeType
{
    public static function label(): string
    {
        return 'Condition';
    }

    public static function description(): ?string
    {
        return __('filament-workflow::node-types.condition.description');
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-funnel';
    }

    public static function defaultColor(): ?string
    {
        return '#eab308';
    }

    public static function defaultShape(): NodeShape
    {
        return NodeShape::DIAMOND;
    }

    public static function defaultWidth(): ?float
    {
        return 180;
    }

    public static function outputPorts(): array
    {
        return [
            ['id' => 'yes', 'label' => 'Yes', 'position' => 'BOTTOM_LEFT', 'color' => '#22c55e'],
            ['id' => 'no', 'label' => 'No', 'position' => 'BOTTOM_RIGHT', 'color' => '#ef4444'],
        ];
    }

    public static function editFormSchema(): array
    {
        return [
            Select::make('logic')
                ->label('Match Logic')
                ->options([
                    'and' => 'ALL conditions must match (AND)',
                    'or' => 'ANY condition can match (OR)',
                ])
                ->default('and'),

            Repeater::make('conditions')
                ->label('Conditions')
                ->schema([
                    Select::make('field')
                        ->label('Field')
                        ->options(fn () => WorkflowEngine::getModelFields() ?: ['status' => 'status', 'priority' => 'priority'])
                        ->searchable()
                        ->required(),

                    Select::make('operator')
                        ->label('Operator')
                        ->options(
                            collect(ConditionOperatorEnum::cases())
                                ->mapWithKeys(fn (ConditionOperatorEnum $op) => [$op->value => $op->label()])
                                ->toArray()
                        )
                        ->required(),

                    TextInput::make('value')
                        ->label('Value')
                        ->placeholder('Expected value'),
                ])
                ->columns(3)
                ->defaultItems(0)
                ->addActionLabel('Add Condition'),
        ];
    }

    public static function maxOutputConnections(): ?int
    {
        return 2; // Yes and No branches
    }
}
