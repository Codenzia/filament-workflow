<?php

declare(strict_types=1);

/**
 * DelayNodeType - Visual definition for delay nodes in the workflow diagrammer.
 *
 * Blue rectangle shape, pauses execution for a configured duration.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\NodeTypes;

use Codenzia\FilamentDiagrammer\Enums\NodeShape;
use Codenzia\FilamentDiagrammer\NodeTypes\BaseNodeType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class DelayNodeType extends BaseNodeType
{
    public static function label(): string
    {
        return 'Delay';
    }

    public static function description(): ?string
    {
        return __('filament-workflow::node-types.delay.description');
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-clock';
    }

    public static function defaultColor(): ?string
    {
        return '#3b82f6';
    }

    public static function defaultShape(): NodeShape
    {
        return NodeShape::RECTANGLE;
    }

    public static function editFormSchema(): array
    {
        return [
            TextInput::make('duration')
                ->label('Duration')
                ->numeric()
                ->required()
                ->minValue(1)
                ->default(1),

            Select::make('unit')
                ->label('Unit')
                ->options([
                    'seconds' => 'Seconds',
                    'minutes' => 'Minutes',
                    'hours' => 'Hours',
                    'days' => 'Days',
                ])
                ->default('hours')
                ->required(),
        ];
    }

    public static function maxInputConnections(): ?int
    {
        return 1;
    }

    public static function maxOutputConnections(): ?int
    {
        return 1;
    }
}
