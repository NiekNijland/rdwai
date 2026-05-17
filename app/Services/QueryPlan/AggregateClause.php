<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

final readonly class AggregateClause
{
    public function __construct(
        public AggregateFn $fn,
        public ?string $field,
        public string $alias,
    ) {
    }
}
