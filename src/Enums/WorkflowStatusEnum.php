<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Enums;

enum WorkflowStatusEnum: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ACTIVE => 'success',
            self::INACTIVE => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'heroicon-o-pencil-square',
            self::ACTIVE => 'heroicon-o-check-circle',
            self::INACTIVE => 'heroicon-o-pause-circle',
        };
    }
}
