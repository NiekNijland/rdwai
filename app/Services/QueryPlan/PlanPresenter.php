<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use App\Models\QueryRun;

/**
 * Serialises a {@see Plan} into the plain array shape returned to the
 * frontend (and persisted on {@see QueryRun}). Kept separate so
 * the runtime Plan can stay readonly and free of presentation concerns.
 */
final class PlanPresenter
{
    /**
     * Normalises a plan array loaded from persistence so older documents
     * (pre-Bucket migration) come out in the current {field, bucket} groupBy
     * shape. The strict LLM/runtime contract still requires the new shape;
     * this is purely a read-path compatibility shim for QueryRun documents
     * stored before the groupBy schema gained buckets.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function normalisePersisted(array $plan): array
    {
        if (isset($plan['groupBy']) && is_array($plan['groupBy'])) {
            $plan['groupBy'] = array_map(
                static fn (mixed $item): array => is_string($item)
                    ? ['field' => $item, 'bucket' => 'none']
                    : $item,
                array_values($plan['groupBy']),
            );
        }

        return $plan;
    }

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
            'groupBy' => array_map(static fn (GroupKey $k): array => [
                'field' => $k->field,
                'bucket' => $k->bucket->value,
            ], $plan->groupBy),
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
