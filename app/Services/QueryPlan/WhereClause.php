<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

final readonly class WhereClause
{
    public function __construct(
        public string $field,
        public WhereOp $op,
        public string $value,
    ) {
    }
}
