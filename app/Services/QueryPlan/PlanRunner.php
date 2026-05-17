<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use App\Actions\Rdw\QueryExecutionException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use NiekNijland\RDW\Datasets\DatasetId;
use NiekNijland\RDW\Exceptions\RateLimitException;
use NiekNijland\RDW\Fields\RegisteredVehicleField;
use NiekNijland\RDW\Query\QueryBuilder;
use NiekNijland\RDW\Query\SortDirection;
use NiekNijland\RDW\Rdw;
use NiekNijland\RDW\Schema\CastType;
use NiekNijland\RDW\Schema\DatasetSchema;
use Throwable;

/**
 * Translates a validated {@see Plan} into a typed RDW {@see QueryBuilder},
 * picks the right terminal call, and returns rows + the SoQL params we sent
 * (for the debug pane).
 *
 * The returned rows are always keyed by the public PascalCase enum case name
 * (e.g. "LicensePlate"), regardless of whether they came from the row path
 * ({@see QueryBuilder::get()}, which returns hydrated record objects whose
 * properties are camelCase) or the aggregate/groupBy path
 * ({@see QueryBuilder::getProjection()}, which returns rows keyed by the
 * Dutch snake_case rdwKey). Aggregate aliases are passed through verbatim so
 * orderBy/expr references remain valid client-side.
 */
final readonly class PlanRunner
{
    public function __construct(private Rdw $rdw)
    {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, soql: array<string, string>, url: string}
     */
    public function run(Plan $plan): array
    {
        $builder = $this->rdw->registeredVehicles();
        $builder = $this->applyWhere($builder, $plan->where);
        $builder = $this->applySelect($builder, [...$plan->select, ...$plan->groupBy]);
        $builder = $this->applyGroupBy($builder, $plan->groupBy);
        $builder = $this->applyAggregates($builder, $plan->aggregates);
        $builder = $this->applyOrderBy($builder, $plan->orderBy, $plan->aggregates);

        if ($plan->limit !== null) {
            $builder = $builder->limit($plan->limit);
        }

        $soql = $builder->toSoqlParams();
        $url = $this->buildRequestUrl($soql);

        try {
            $rows = $this->execute($builder, $plan);
        } catch (RateLimitException $e) {
            // The controller treats RateLimitException specially (429 with a
            // Retry-After). Pass it through; do not wrap.
            throw $e;
        } catch (Throwable $e) {
            throw new QueryExecutionException($plan, $soql, $url, $e);
        }

        return ['rows' => $rows, 'soql' => $soql, 'url' => $url];
    }

    /**
     * @param array<string, string> $soql
     */
    private function buildRequestUrl(array $soql): string
    {
        $base = rtrim($this->rdw->configuration()->baseUrl, '/');
        $datasetId = DatasetId::RegisteredVehicles->value;
        $query = http_build_query($soql, '', '&', PHP_QUERY_RFC3986);

        return "{$base}/resource/{$datasetId}.json" . ($query !== '' ? "?{$query}" : '');
    }

    /**
     * @param QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle> $builder
     * @param list<WhereClause> $clauses
     * @return QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle>
     */
    private function applyWhere(QueryBuilder $builder, array $clauses): QueryBuilder
    {
        foreach ($clauses as $clause) {
            $field = $this->resolveField($clause->field);

            $builder = match ($clause->op) {
                WhereOp::Equals => $builder->where($field, $this->castValue($field, $clause->value), '='),
                WhereOp::NotEquals => $builder->where($field, $this->castValue($field, $clause->value), '!='),
                WhereOp::GreaterThan => $builder->where($field, $this->castValue($field, $clause->value), '>'),
                WhereOp::GreaterThanOrEqual => $builder->where($field, $this->castValue($field, $clause->value), '>='),
                WhereOp::LessThan => $builder->where($field, $this->castValue($field, $clause->value), '<'),
                WhereOp::LessThanOrEqual => $builder->where($field, $this->castValue($field, $clause->value), '<='),
                WhereOp::Contains => $builder->whereContains($field, $clause->value),
                WhereOp::StartsWith => $builder->whereStartsWith($field, $clause->value),
            };
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle> $builder
     * @param list<string> $fields
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
     * @param list<string> $fields
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
     * @param list<AggregateClause> $aggregates
     * @return QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle>
     */
    private function applyAggregates(QueryBuilder $builder, array $aggregates): QueryBuilder
    {
        foreach ($aggregates as $agg) {
            $field = $agg->field !== null ? $this->resolveField($agg->field) : null;

            $builder = match ($agg->fn) {
                AggregateFn::Count => $builder->count($field, $agg->alias),
                AggregateFn::Sum => $builder->sum($this->requireField($field, AggregateFn::Sum), $agg->alias),
                AggregateFn::Avg => $builder->avg($this->requireField($field, AggregateFn::Avg), $agg->alias),
                AggregateFn::Min => $builder->min($this->requireField($field, AggregateFn::Min), $agg->alias),
                AggregateFn::Max => $builder->max($this->requireField($field, AggregateFn::Max), $agg->alias),
            };
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle> $builder
     * @param list<OrderClause> $orderBy
     * @param list<AggregateClause> $aggregates
     * @return QueryBuilder<\NiekNijland\RDW\Records\RegisteredVehicle>
     */
    private function applyOrderBy(QueryBuilder $builder, array $orderBy, array $aggregates): QueryBuilder
    {
        $aliasSet = [];
        foreach ($aggregates as $agg) {
            $aliasSet[$agg->alias] = true;
        }

        foreach ($orderBy as $clause) {
            $direction = $clause->direction === OrderDirection::Desc ? SortDirection::Desc : SortDirection::Asc;

            $field = $this->tryResolveField($clause->expr);
            if ($field !== null) {
                $builder = $builder->orderBy($field, $direction);
                continue;
            }

            if (! isset($aliasSet[$clause->expr])) {
                throw new InvalidArgumentException(sprintf(
                    'orderBy expression "%s" is neither a known field nor a declared aggregate alias.',
                    $clause->expr,
                ));
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
        if ($plan->aggregates !== [] || $plan->groupBy !== []) {
            $rows = $builder->getProjection();

            return $this->normaliseProjectionRows($rows, $plan->aggregates);
        }

        $records = $builder->get();

        return array_map(fn (object $r): array => $this->recordToArray($r, $plan->select), $records);
    }

    /**
     * Build an output row for a hydrated record in the order requested by
     * `$plan->select` (PascalCase enum case names). When no select was given
     * we fall through to every property the record exposes.
     *
     * @param list<string> $select
     * @return array<string, mixed>
     */
    private function recordToArray(object $record, array $select): array
    {
        $vars = get_object_vars($record);
        $schema = $this->schema();

        if ($select === []) {
            $out = [];
            foreach ($vars as $key => $value) {
                $enumCase = $this->propertyToEnumCase($schema, $key) ?? $key;
                $out[$enumCase] = $this->normaliseValue($value);
            }

            return $out;
        }

        $out = [];
        foreach ($select as $enumCase) {
            $descriptor = $schema->byEnumCase[$enumCase] ?? null;
            if ($descriptor === null) {
                continue;
            }
            if (! array_key_exists($descriptor->propertyName, $vars)) {
                continue;
            }
            $out[$enumCase] = $this->normaliseValue($vars[$descriptor->propertyName]);
        }

        return $out;
    }

    /**
     * Aggregate/groupBy rows arrive keyed by the Dutch snake_case rdwKey.
     * Rewrite known keys to their public PascalCase enum case so the frontend
     * never needs its own translation table.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<AggregateClause> $aggregates
     * @return list<array<string, mixed>>
     */
    private function normaliseProjectionRows(array $rows, array $aggregates): array
    {
        $schema = $this->schema();
        $aliasSet = [];
        foreach ($aggregates as $agg) {
            $aliasSet[$agg->alias] = true;
        }

        $out = [];
        foreach ($rows as $row) {
            $normalised = [];
            foreach ($row as $key => $value) {
                $renamed = isset($aliasSet[$key])
                    ? $key
                    : ($schema->byRdwKey[$key]->enumCase ?? $key);
                $normalised[$renamed] = $this->normaliseValue($value);
            }
            $out[] = $normalised;
        }

        return $out;
    }

    private function normaliseValue(mixed $value): mixed
    {
        return $value instanceof CarbonImmutable ? $value->toDateString() : $value;
    }

    private function propertyToEnumCase(DatasetSchema $schema, string $propertyName): ?string
    {
        foreach ($schema->byEnumCase as $enumCase => $descriptor) {
            if ($descriptor->propertyName === $propertyName) {
                return $enumCase;
            }
        }

        return null;
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
        return RegisteredVehicleFieldLookup::tryGet($name);
    }

    private function requireField(?RegisteredVehicleField $field, AggregateFn $fn): RegisteredVehicleField
    {
        if ($field === null) {
            throw new InvalidArgumentException(sprintf('Aggregate %s requires a field.', $fn->value));
        }

        return $field;
    }

    private function castValue(RegisteredVehicleField $field, string $raw): mixed
    {
        $descriptor = $this->schema()->byEnumCase[$field->name] ?? null;
        if ($descriptor === null) {
            return $raw;
        }

        return match ($descriptor->cast) {
            CastType::Boolean => in_array(strtolower($raw), ['true', '1', 'ja', 'yes'], true),
            CastType::Integer => (int) $raw,
            CastType::Decimal => $raw,
            CastType::CalendarDate, CastType::NumericDate => CarbonImmutable::parse($raw, 'UTC'),
            default => $raw,
        };
    }

    private function schema(): DatasetSchema
    {
        return $this->rdw->schemas()->get(DatasetId::RegisteredVehicles);
    }
}
