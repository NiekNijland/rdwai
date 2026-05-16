<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

enum DisplayHint: string
{
    case Count = 'count';
    case Bars = 'bars';
    case Table = 'table';
    case Record = 'record';
}
