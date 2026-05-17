<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

/**
 * Validated, structured query plan produced by the LLM. Lives independent of
 * any HTTP/LLM concern — the runner consumes this and emits a typed RDW query.
 */
final readonly class Plan
{
    /**
     * @param list<WhereClause> $where
     * @param list<string> $select English field names from RegisteredVehicleField
     * @param list<GroupKey> $groupBy
     * @param list<AggregateClause> $aggregates
     * @param list<OrderClause> $orderBy
     */
    public function __construct(
        public array $where,
        public array $select,
        public array $groupBy,
        public array $aggregates,
        public array $orderBy,
        public ?int $limit,
        public DisplayHint $display,
        public string $explanation,
    ) {
    }
}
