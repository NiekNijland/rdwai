<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

/**
 * Serialises a {@see Plan} into the plain array shape returned to the
 * frontend (and persisted on {@see \App\Models\QueryRun}). Kept separate so
 * the runtime Plan can stay readonly and free of presentation concerns.
 */
final class PlanPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Plan $plan): array
    {
        return [
            'where' => array_map(static fn (WhereClause $c): array => [
                'field' => $c->field,
                'op' => $c->op->value,
                'value' => $c->value,
            ], $plan->where),
            'select' => $plan->select,
            'groupBy' => $plan->groupBy,
            'aggregates' => array_map(static fn (AggregateClause $a): array => [
                'fn' => $a->fn->value,
                'field' => $a->field,
                'alias' => $a->alias,
            ], $plan->aggregates),
            'orderBy' => array_map(static fn (OrderClause $o): array => [
                'expr' => $o->expr,
                'direction' => $o->direction->value,
            ], $plan->orderBy),
            'limit' => $plan->limit,
            'display' => $plan->display->value,
            'explanation' => $plan->explanation,
        ];
    }
}
