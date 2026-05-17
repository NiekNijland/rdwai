<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

final readonly class OrderClause
{
    public function __construct(
        public string $expr,
        public OrderDirection $direction,
    ) {
    }
}
