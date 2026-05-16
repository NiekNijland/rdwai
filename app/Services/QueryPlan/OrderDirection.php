<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

enum OrderDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
