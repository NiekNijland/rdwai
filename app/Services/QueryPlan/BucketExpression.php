<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

/**
 * Runtime payload describing a single bucketed group key: the raw SoQL
 * `date_trunc_*` expression spliced into the query, plus the alias it is
 * exposed under (always the field's PascalCase enum case, so the row key
 * after projection normalisation matches the non-bucketed groupBy path).
 */
final readonly class BucketExpression
{
    public function __construct(
        public string $alias,
        public string $expression,
    ) {
    }
}
