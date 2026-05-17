<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

final readonly class GroupKey
{
    public function __construct(
        public string $field,
        public Bucket $bucket,
    ) {
    }
}
