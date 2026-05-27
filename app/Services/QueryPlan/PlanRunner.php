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
use NiekNijland\RDW\Records\RegisteredVehicle;
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
 *
 * Bucketed group keys ({@see GroupKey} with a non-`None` {@see Bucket}) wrap
 * the field's rdwKey in a `date_trunc_y` / `date_trunc_ym` / `date_trunc_ymd`
 * expression, aliased back to the field's PascalCase enum name so the row
 * projection looks identical to the non-bucketed groupBy path. The expression
 * is added to `$group` via {@see QueryBuilder::groupByRaw()} and to `$select`
 * via {@see QueryBuilder::selectRaw()}; `$group` is built in plan order so the
 * SoQL the user sees in the debug pane matches the plan they see in the UI.
 */
final readonly class PlanRunner
{
    public function __construct(private Rdw $rdw)
    {
    }

    public function run(Plan $plan): RunnerResult
    {
        // A refusal plan ({@see DisplayHint::Unsupported}) describes "I won't
        // answer this" — there is no SoQL to send and no rows to fetch. Skip
        // the dataset call entirely so an off-topic / injection prompt never
        // bills against the RDW rate limit or surfaces ghost data.
        if ($plan->display === DisplayHint::Unsupported) {
            return new RunnerResult(rows: [], soql: [], url: '');
        }

        $buckets = $this->buildBucketsByField($plan->groupBy);

        $builder = $this->rdw->registeredVehicles();
        $builder = $this->applyWhere($builder, $plan->where);
        $builder = $this->applySelectAndGroupBy($builder, $plan, $buckets);
        $builder = $this->applyAggregates($builder, $plan->aggregates);
        $builder = $this->applyOrderBy($builder, $plan->orderBy, $plan->aggregates, $buckets);

        if ($plan->limit !== null) {
            $builder = $builder->limit($plan->limit);
        }

        $soql = $builder->toSoqlParams();
        $url = $this->buildRequestUrl($soql);

        try {
            $rows = $this->execute($builder, $plan, $buckets);
        } catch (RateLimitException $e) {
            // The controller treats RateLimitException specially (429 with a
            // Retry-After). Pass it through; do not wrap.
            throw $e;
        } catch (Throwable $e) {
            throw new QueryExecutionException($plan, $soql, $url, $e);
        }

        return new RunnerResult(rows: $rows, soql: $soql, url: $url);
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
     * @param QueryBuilder<RegisteredVehicle> $builder
     * @param list<WhereClause> $clauses
     * @return QueryBuilder<RegisteredVehicle>
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
     * Applies `$plan->select` and `$plan->groupBy` to the builder in a single
     * ordered pass so the resulting SoQL `$select` and `$group` clauses appear
     * in the same order the plan does. Bucketed keys go through
     * {@see QueryBuilder::selectRaw()} / {@see QueryBuilder::groupByRaw()};
     * plain keys go through the typed `select()` / `groupBy()`.
     *
     * @param QueryBuilder<RegisteredVehicle> $builder
     * @param array<string, BucketExpression> $buckets keyed by field enum case
     * @return QueryBuilder<RegisteredVehicle>
     */
    private function applySelectAndGroupBy(QueryBuilder $builder, Plan $plan, array $buckets): QueryBuilder
    {
        foreach ($plan->select as $name) {
            $builder = $builder->select($this->resolveField($name));
        }

        foreach ($plan->groupBy as $key) {
            $bucket = $buckets[$key->field] ?? null;
            if ($bucket !== null) {
                $builder = $builder
                    ->selectRaw($bucket->expression, $bucket->alias)
                    ->groupByRaw($bucket->expression);

                continue;
            }

            $field = $this->resolveField($key->field);
            $builder = $builder->select($field)->groupBy($field);
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<RegisteredVehicle> $builder
     * @param list<AggregateClause> $aggregates
     * @return QueryBuilder<RegisteredVehicle>
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
     * @param QueryBuilder<RegisteredVehicle> $builder
     * @param list<OrderClause> $orderBy
     * @param list<AggregateClause> $aggregates
     * @param array<string, BucketExpression> $buckets keyed by field enum case
     * @return QueryBuilder<RegisteredVehicle>
     */
    private function applyOrderBy(QueryBuilder $builder, array $orderBy, array $aggregates, array $buckets): QueryBuilder
    {
        $aliasSet = [];
        foreach ($aggregates as $agg) {
            $aliasSet[$agg->alias] = true;
        }

        // Socrata sorts NULLs first on DESC, so "top 5 heaviest" or "most
        // recent transfer" otherwise leads with rows where the sort column
        // is empty. Guarantee the sort column is populated for any plain
        // field orderBy; dedupe so the same field isn't filtered twice.
        $notNullApplied = [];

        foreach ($orderBy as $clause) {
            $direction = $clause->direction === OrderDirection::Desc ? SortDirection::Desc : SortDirection::Asc;

            if (isset($buckets[$clause->expr])) {
                $builder = $builder->orderByRaw($buckets[$clause->expr]->expression . ' ' . $direction->value);

                continue;
            }

            $field = $this->tryResolveField($clause->expr);
            if ($field !== null) {
                if (! isset($notNullApplied[$clause->expr])) {
                    $builder = $builder->whereNotNull($field);
                    $notNullApplied[$clause->expr] = true;
                }
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
     * @param QueryBuilder<RegisteredVehicle> $builder
     * @param array<string, BucketExpression> $buckets
     * @return list<array<string, mixed>>
     */
    private function execute(QueryBuilder $builder, Plan $plan, array $buckets): array
    {
        if ($plan->aggregates !== [] || $plan->groupBy !== []) {
            return $this->normaliseProjectionRows($builder->getProjection(), $plan->aggregates, $buckets);
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
     * never needs its own translation table. Bucket aliases (which already
     * match the field's enum case) and aggregate aliases pass through.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<AggregateClause> $aggregates
     * @param array<string, BucketExpression> $buckets keyed by field enum case
     * @return list<array<string, mixed>>
     */
    private function normaliseProjectionRows(array $rows, array $aggregates, array $buckets): array
    {
        $schema = $this->schema();
        $passThrough = [];
        foreach ($aggregates as $agg) {
            $passThrough[$agg->alias] = true;
        }
        foreach ($buckets as $bucket) {
            $passThrough[$bucket->alias] = true;
        }

        $out = [];
        foreach ($rows as $row) {
            $normalised = [];
            foreach ($row as $key => $value) {
                $renamed = isset($passThrough[$key])
                    ? $key
                    : ($schema->byRdwKey[$key]->enumCase ?? $key);
                $normalised[$renamed] = $this->normaliseValue($value);
            }
            $out[] = $normalised;
        }

        return $out;
    }

    /**
     * Translate every bucketed {@see GroupKey} into a SoQL expression bound to
     * the field's rdwKey, plus an alias matching the field's PascalCase enum
     * case so the projection row key looks identical to the non-bucket path.
     * Returns a map keyed by the field's enum case so both the select/group
     * pass and the orderBy pass can look the bucket up by `$clause->expr`
     * without rebuilding the index.
     *
     * @param list<GroupKey> $groupBy
     * @return array<string, BucketExpression>
     */
    private function buildBucketsByField(array $groupBy): array
    {
        $schema = $this->schema();
        $out = [];

        foreach ($groupBy as $key) {
            if ($key->bucket === Bucket::None) {
                continue;
            }

            $descriptor = $schema->byEnumCase[$key->field] ?? null;
            if ($descriptor === null) {
                throw new InvalidArgumentException(sprintf('Unknown RegisteredVehicleField "%s".', $key->field));
            }

            $fn = match ($key->bucket) {
                Bucket::Year => 'date_trunc_y',
                Bucket::Month => 'date_trunc_ym',
                Bucket::Day => 'date_trunc_ymd',
            };

            $out[$key->field] = new BucketExpression(
                alias: $key->field,
                expression: sprintf('%s(%s)', $fn, $descriptor->rdwKey),
            );
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
