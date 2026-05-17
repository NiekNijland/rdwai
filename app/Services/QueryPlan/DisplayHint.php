<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

enum DisplayHint: string
{
    case Count = 'count';
    case Stats = 'stats';
    case Bars = 'bars';
    case StackedBars = 'stacked_bars';
    case Pie = 'pie';
    case Histogram = 'histogram';
    case Timeseries = 'timeseries';
    case Table = 'table';
    case Record = 'record';
    case Unsupported = 'unsupported';
}
