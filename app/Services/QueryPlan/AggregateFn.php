<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

enum AggregateFn: string
{
    case Count = 'count';
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
}
