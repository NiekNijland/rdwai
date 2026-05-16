<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use InvalidArgumentException;
use NiekNijland\RDW\Fields\RegisteredVehicleField;

/**
 * Builds a typed {@see Plan} from the loose array Prism hands back.
 *
 * Prism + OpenAI strict schema validates the shape, but we still re-validate
 * enum values and resolve PascalCase field names to {@see RegisteredVehicleField}
 * cases so the runner can rely on typed inputs.
 */
final class PlanFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): Plan
    {
        return new Plan(
            where: array_values(array_map($this->parseWhere(...), $data['where'] ?? [])),
            select: $this->parseFieldList($data['select'] ?? []),
            groupBy: $this->parseFieldList($data['groupBy'] ?? []),
            aggregates: array_values(array_map($this->parseAggregate(...), $data['aggregates'] ?? [])),
            orderBy: array_values(array_map($this->parseOrder(...), $data['orderBy'] ?? [])),
            limit: isset($data['limit']) ? max(1, min(1000, (int) $data['limit'])) : null,
            display: DisplayHint::from((string) ($data['display'] ?? 'table')),
            explanation: (string) ($data['explanation'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $clause
     */
    private function parseWhere(array $clause): WhereClause
    {
        $field = (string) ($clause['field'] ?? '');
        $this->assertFieldExists($field);

        return new WhereClause(
            field: $field,
            op: WhereOp::from((string) ($clause['op'] ?? '')),
            value: (string) ($clause['value'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $clause
     */
    private function parseAggregate(array $clause): AggregateClause
    {
        $field = isset($clause['field']) ? (string) $clause['field'] : null;
        if ($field !== null && $field !== '*' && $field !== '') {
            $this->assertFieldExists($field);
        }
        $field = ($field === '' || $field === '*') ? null : $field;

        return new AggregateClause(
            fn: AggregateFn::from((string) ($clause['fn'] ?? '')),
            field: $field,
            alias: $this->sanitiseAlias((string) ($clause['alias'] ?? 'value')),
        );
    }

    /**
     * @param array<string, mixed> $clause
     */
    private function parseOrder(array $clause): OrderClause
    {
        return new OrderClause(
            expr: (string) ($clause['expr'] ?? ''),
            direction: OrderDirection::from((string) ($clause['direction'] ?? 'asc')),
        );
    }

    /**
     * @param list<mixed> $fields
     * @return list<string>
     */
    private function parseFieldList(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $name = (string) $f;
            $this->assertFieldExists($name);
            $out[] = $name;
        }

        return $out;
    }

    private function assertFieldExists(string $name): void
    {
        foreach (RegisteredVehicleField::cases() as $case) {
            if ($case->name === $name) {
                return;
            }
        }
        throw new InvalidArgumentException(sprintf('Unknown RegisteredVehicleField "%s".', $name));
    }

    private function sanitiseAlias(string $alias): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) === 1 ? $alias : 'value';
    }
}
