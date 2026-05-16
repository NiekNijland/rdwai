<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use NiekNijland\RDW\Fields\RegisteredVehicleField;
use NiekNijland\RDW\Query\QueryBuilder;
use NiekNijland\RDW\Query\SortDirection;
use NiekNijland\RDW\Rdw;
use NiekNijland\RDW\Schema\CastType;
use Throwable;

/**
 * Translates a validated {@see Plan} into a typed RDW {@see QueryBuilder},
 * picks the right terminal call, and returns rows + the SoQL params we sent
 * (for the debug pane).
 */
final readonly class PlanRunner
{
    public function __construct(private Rdw $rdw) {}

    /**
     * @return array{rows: list<array<string, mixed>>, soql: array<string, string>}
     */
    public function run(Plan $plan): array
    {
        $builder = $this->rdw->registeredVehicles();
        $builder = $this->applyWhere($builder, $plan->where);
        $builder = $this->applySelect($builder, [...$plan->select, ...$plan->groupBy]);
        $builder = $this->applyGroupBy($builder, $plan->groupBy);
        $builder = $this->applyAggregates($builder, $plan->aggregates);
        $builder = $this->applyOrderBy($builder, $plan->orderBy);

        if ($plan->limit !== null) {
            $builder = $builder->limit($plan->limit);
        }

        $soql = $builder->toSoqlParams();
        $rows = $this->execute($builder, $plan);

        return ['rows' => $rows, 'soql' => $soql];
    }

    /**
     * @param QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle> $builder
     * @param list<WhereClause>                                        $clauses
     * @return QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle>
     */
    private function applyWhere(QueryBuilder $builder, array $clauses): QueryBuilder
    {
        foreach ($clauses as $clause) {
            $field = $this->resolveField($clause->field);
            $value = $this->castValue($field, $clause->value);

            $builder = match ($clause->op) {
                WhereOp::Equals => $builder->where($field, $value, '='),
                WhereOp::NotEquals => $builder->where($field, $value, '!='),
                WhereOp::GreaterThan => $builder->where($field, $value, '>'),
                WhereOp::GreaterThanOrEqual => $builder->where($field, $value, '>='),
                WhereOp::LessThan => $builder->where($field, $value, '<'),
                WhereOp::LessThanOrEqual => $builder->where($field, $value, '<='),
                WhereOp::Contains => $builder->whereContains($field, $clause->value),
                WhereOp::StartsWith => $builder->whereStartsWith($field, $clause->value),
            };
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle> $builder
     * @param list<string>                                             $fields
     * @return QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle>
     */
    private function applySelect(QueryBuilder $builder, array $fields): QueryBuilder
    {
        foreach ($fields as $name) {
            $builder = $builder->select($this->resolveField($name));
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle> $builder
     * @param list<string>                                             $fields
     * @return QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle>
     */
    private function applyGroupBy(QueryBuilder $builder, array $fields): QueryBuilder
    {
        foreach ($fields as $name) {
            $builder = $builder->groupBy($this->resolveField($name));
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle> $builder
     * @param list<AggregateClause>                                    $aggregates
     * @return QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle>
     */
    private function applyAggregates(QueryBuilder $builder, array $aggregates): QueryBuilder
    {
        foreach ($aggregates as $agg) {
            $field = $agg->field !== null ? $this->resolveField($agg->field) : null;

            $builder = match ($agg->fn) {
                AggregateFn::Count => $builder->count($field, $agg->alias),
                AggregateFn::Sum => $builder->sum($field ?? throw new InvalidArgumentException('sum requires a field.'), $agg->alias),
                AggregateFn::Avg => $builder->avg($field ?? throw new InvalidArgumentException('avg requires a field.'), $agg->alias),
                AggregateFn::Min => $builder->min($field ?? throw new InvalidArgumentException('min requires a field.'), $agg->alias),
                AggregateFn::Max => $builder->max($field ?? throw new InvalidArgumentException('max requires a field.'), $agg->alias),
            };
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle> $builder
     * @param list<OrderClause>                                        $orderBy
     * @return QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle>
     */
    private function applyOrderBy(QueryBuilder $builder, array $orderBy): QueryBuilder
    {
        foreach ($orderBy as $clause) {
            $direction = $clause->direction === OrderDirection::Desc ? SortDirection::Desc : SortDirection::Asc;

            $field = $this->tryResolveField($clause->expr);
            if ($field !== null) {
                $builder = $builder->orderBy($field, $direction);
                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $clause->expr) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid orderBy expression "%s".', $clause->expr));
            }
            $builder = $builder->orderByRaw($clause->expr . ' ' . $direction->value);
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle> $builder
     * @return list<array<string, mixed>>
     */
    private function execute(QueryBuilder $builder, Plan $plan): array
    {
        try {
            if ($plan->aggregates !== [] || $plan->groupBy !== []) {
                return $builder->getProjection();
            }

            $records = $builder->get();
            $properties = $this->selectedPropertyNames($plan->select);

            return array_map(static fn (object $r): array => self::recordToArray($r, $properties), $records);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * @param list<string> $keepProperties When empty, every record property is kept.
     * @return array<string, mixed>
     */
    private static function recordToArray(object $record, array $keepProperties): array
    {
        $out = [];
        foreach (get_object_vars($record) as $key => $value) {
            if ($keepProperties !== [] && ! in_array($key, $keepProperties, true)) {
                continue;
            }
            $out[$key] = $value instanceof CarbonImmutable ? $value->toDateString() : $value;
        }

        return $out;
    }

    /**
     * Maps the plan's PascalCase enum names to the camelCase property names
     * the hydrated record exposes (e.g. LicensePlate → licensePlate). Falls
     * back to "keep everything" when the plan didn't constrain selects.
     *
     * @param list<string> $select
     * @return list<string>
     */
    private function selectedPropertyNames(array $select): array
    {
        if ($select === []) {
            return [];
        }

        $schema = $this->rdw->schemas()->get(\NiekNijland\RDW\Datasets\DatasetId::RegisteredVehicles);
        $out = [];
        foreach ($select as $name) {
            $descriptor = $schema->byEnumCase[$name] ?? null;
            if ($descriptor !== null) {
                $out[] = $descriptor->propertyName;
            }
        }

        return $out;
    }

    private function resolveField(string $name): RegisteredVehicleField
    {
        $field = $this->tryResolveField($name);
        if ($field === null) {
            throw new InvalidArgumentException(sprintf('Unknown RegisteredVehicleField "%s".', $name));
        }

        return $field;
    }

    private function tryResolveField(string $name): ?RegisteredVehicleField
    {
        foreach (RegisteredVehicleField::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }

    private function castValue(RegisteredVehicleField $field, string $raw): mixed
    {
        $descriptor = $this->rdw->schemas()->get(\NiekNijland\RDW\Datasets\DatasetId::RegisteredVehicles)
            ->byEnumCase[$field->name] ?? null;

        if ($descriptor === null) {
            return $raw;
        }

        return match ($descriptor->cast) {
            CastType::Boolean => in_array(strtolower($raw), ['true', '1', 'ja', 'yes'], true),
            CastType::Integer => (int) $raw,
            CastType::Decimal => $raw, // package keeps decimals as string to avoid precision loss
            CastType::CalendarDate, CastType::NumericDate => CarbonImmutable::parse($raw, 'UTC'),
            default => $raw,
        };
    }
}
