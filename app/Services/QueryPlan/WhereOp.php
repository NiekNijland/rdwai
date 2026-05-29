<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

enum WhereOp: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case Contains = 'contains';
    case StartsWith = 'startsWith';
    case In = 'in';
}
