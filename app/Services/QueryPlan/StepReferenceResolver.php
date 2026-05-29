<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

final class StepReferenceResolver
{
    public const int LIST_LIMIT = 1000;

    public function resolve(Plan $plan, QueryLedger $ledger): Plan
    {
        $where = array_map(
            fn (WhereClause $clause): WhereClause => $this->resolveClause($clause, $ledger),
            $plan->where,
        );

        return new Plan(
            dataset: $plan->dataset,
            where: $where,
            select: $plan->select,
            groupBy: $plan->groupBy,
            aggregates: $plan->aggregates,
            orderBy: $plan->orderBy,
            limit: $plan->limit,
            display: $plan->display,
            explanation: $plan->explanation,
        );
    }

    private function resolveClause(WhereClause $clause, QueryLedger $ledger): WhereClause
    {
        $reference = StepReference::tryParse($clause->value);
        if ($reference === null) {
            return $clause;
        }

        if ($clause->op === WhereOp::In) {
            return new WhereClause(
                field: $clause->field,
                op: $clause->op,
                value: $clause->value,
                values: $this->resolveListReference($reference, $ledger),
            );
        }

        return new WhereClause(
            field: $clause->field,
            op: $clause->op,
            value: $this->resolveScalarReference($reference, $ledger),
        );
    }

    private function resolveScalarReference(StepReference $reference, QueryLedger $ledger): string
    {
        $rows = $this->referencedRows($reference, $ledger);
        if (count($rows) !== 1) {
            throw new StepReferenceException(sprintf(
                'Reference "%s" expects query "%s" to return exactly one row, got %d.',
                $reference->token(),
                $reference->queryId,
                count($rows),
            ));
        }

        $value = $this->columnValue($rows[0], $reference);
        if ($value === null) {
            throw new StepReferenceException(sprintf(
                'Column "%s" of query "%s" is null.',
                $reference->field,
                $reference->queryId,
            ));
        }

        return (string) $value;
    }

    /**
     * @return list<string>
     */
    private function resolveListReference(StepReference $reference, QueryLedger $ledger): array
    {
        $rows = $this->referencedRows($reference, $ledger);
        if ($rows === []) {
            throw new StepReferenceException(sprintf(
                'Reference "%s" expects query "%s" to return at least one row.',
                $reference->token(),
                $reference->queryId,
            ));
        }

        // The prompt instructs the LLM to set lookup `limit: 1000` and PlanFactory clamps to the
        // same ceiling, so a row count *at* LIST_LIMIT is a complete result the model asked for;
        // only refuse when it spilled past the cap.
        if (count($rows) > self::LIST_LIMIT) {
            throw new StepReferenceException(sprintf(
                'Reference "%s" returned %d rows, which exceeds the %d-row cross-dataset limit; ask a more specific question.',
                $reference->token(),
                count($rows),
                self::LIST_LIMIT,
            ));
        }

        $values = [];
        $seen = [];
        foreach ($rows as $row) {
            $value = $this->columnValue($row, $reference);
            if ($value === null) {
                continue;
            }
            $stringified = (string) $value;
            if (isset($seen[$stringified])) {
                continue;
            }
            $seen[$stringified] = true;
            $values[] = $stringified;
        }

        if ($values === []) {
            throw new StepReferenceException(sprintf(
                'Column "%s" of query "%s" is null on every row.',
                $reference->field,
                $reference->queryId,
            ));
        }

        return $values;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function referencedRows(StepReference $reference, QueryLedger $ledger): array
    {
        $entry = $ledger->get($reference->queryId);
        if ($entry === null) {
            throw new StepReferenceException(sprintf('Reference to unknown query "%s".', $reference->queryId));
        }

        return $entry->result->rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function columnValue(array $row, StepReference $reference): mixed
    {
        if (! array_key_exists($reference->field, $row)) {
            throw new StepReferenceException(sprintf(
                'Query "%s" did not return column "%s".',
                $reference->queryId,
                $reference->field,
            ));
        }

        return $row[$reference->field];
    }
}
