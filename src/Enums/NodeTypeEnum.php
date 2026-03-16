<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Enums;

enum NodeTypeEnum: string
{
    case TRIGGER = 'trigger';
    case CONDITION = 'condition';
    case DELAY = 'delay';
    case ACTION = 'action';

    public function label(): string
    {
        return match ($this) {
            self::TRIGGER => 'Trigger',
            self::CONDITION => 'Condition',
            self::DELAY => 'Delay',
            self::ACTION => 'Action',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TRIGGER => '#22c55e',
            self::CONDITION => '#eab308',
            self::DELAY => '#3b82f6',
            self::ACTION => '#8b5cf6',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TRIGGER => 'heroicon-o-bolt',
            self::CONDITION => 'heroicon-o-funnel',
            self::DELAY => 'heroicon-o-clock',
            self::ACTION => 'heroicon-o-play',
        };
    }
}
