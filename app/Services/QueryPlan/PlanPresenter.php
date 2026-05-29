<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use App\Models\QueryRun;

final class PlanPresenter
{
    /**
     * Read-path shim so pre-Bucket QueryRun documents come out in the current {field, bucket} groupBy shape.
     *
     * @param array<string, mixed> $plan
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

        if (! isset($plan['dataset'])) {
            $plan['dataset'] = TargetDataset::RegisteredVehicles->value;
        }

        return $plan;
    }

    /**
     * Applies `normalisePersisted` to each step's nested `plan` so pre-change QueryRun documents
     * deserialise into the shape the frontend's `Plan` type now declares.
     *
     * @param list<array<string, mixed>>|null $steps
     * @return list<array<string, mixed>>
     */
    public static function normalisePersistedSteps(?array $steps): array
    {
        if ($steps === null) {
            return [];
        }

        return array_map(static function (array $step): array {
            if (isset($step['plan']) && is_array($step['plan'])) {
                $step['plan'] = self::normalisePersisted($step['plan']);
            }

            return $step;
        }, $steps);
    }

    /**
     * @param list<LedgerEntry> $steps
     * @return list<array<string, mixed>>
     */
    public static function stepsToArray(array $steps): array
    {
        return array_map(static fn (LedgerEntry $entry): array => [
            'id' => $entry->id,
            'plan' => self::toArray($entry->plan),
            'soql' => $entry->result->soql,
            'url' => $entry->result->url,
            'rowCount' => count($entry->result->rows),
        ], $steps);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function presentationToArray(?Presentation $presentation, ?Derived $derived): ?array
    {
        if ($presentation === null) {
            return null;
        }

        return [
            'resultRef' => $presentation->resultRef,
            'display' => $presentation->display->value,
            'derive' => $presentation->derive?->toArray(),
            'derived' => $derived?->toArray(),
            'explanation' => $presentation->explanation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Plan $plan): array
    {
        return [
            'dataset' => $plan->dataset->value,
            'where' => array_map(static fn (WhereClause $c): array => $c->values === []
                ? ['field' => $c->field, 'op' => $c->op->value, 'value' => $c->value]
                : ['field' => $c->field, 'op' => $c->op->value, 'value' => $c->value, 'values' => $c->values],
                $plan->where,
            ),
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
