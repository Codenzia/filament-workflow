<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Enums;

enum ConditionOperatorEnum: string
{
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case GREATER_THAN = 'greater_than';
    case LESS_THAN = 'less_than';
    case GREATER_THAN_OR_EQUAL = 'greater_than_or_equal';
    case LESS_THAN_OR_EQUAL = 'less_than_or_equal';
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';
    case IS_NULL = 'is_null';
    case IS_NOT_NULL = 'is_not_null';
    case IN = 'in';
    case NOT_IN = 'not_in';

    public function label(): string
    {
        return match ($this) {
            self::EQUALS => 'Equals',
            self::NOT_EQUALS => 'Not Equals',
            self::GREATER_THAN => 'Greater Than',
            self::LESS_THAN => 'Less Than',
            self::GREATER_THAN_OR_EQUAL => 'Greater Than or Equal',
            self::LESS_THAN_OR_EQUAL => 'Less Than or Equal',
            self::CONTAINS => 'Contains',
            self::NOT_CONTAINS => 'Does Not Contain',
            self::IS_NULL => 'Is Empty',
            self::IS_NOT_NULL => 'Is Not Empty',
            self::IN => 'Is One Of',
            self::NOT_IN => 'Is Not One Of',
        };
    }
}
