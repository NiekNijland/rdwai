<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use App\Actions\Rdw\QueryExecutionException;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository;
use InvalidArgumentException;
use NiekNijland\RDW\Exceptions\RateLimitException;
use NiekNijland\RDW\Fields\RegisteredVehicleField;
use NiekNijland\RDW\Fields\RegisteredVehicleFuelField;
use NiekNijland\RDW\Query\QueryBuilder;
use NiekNijland\RDW\Query\SortDirection;
use NiekNijland\RDW\Rdw;
use NiekNijland\RDW\Records\RegisteredVehicle;
use NiekNijland\RDW\Records\RegisteredVehicleFuel;
use NiekNijland\RDW\Schema\CastType;
use NiekNijland\RDW\Schema\DatasetSchema;
use Throwable;

final readonly class PlanRunner
{
    private const int AGGREGATE_TTL_SECONDS = 86_400;

    private const int ROW_TTL_SECONDS = 600;

    private const int PROJECTION_PAGE_SIZE = 1000;

    private const int DEFAULT_MAX_PROJECTION_ROWS = 50_000;

    /**
     * RegisteredVehicleFuels columns Socrata stores as `text`, by their Dutch source key (enum
     * `->value`). Comparisons against these need a `to_number(...)` wrap to avoid lexicographic
     * order. WLTP and range/electricity fields are intentionally absent: their underlying
     * `dataTypeName` hasn't been verified — extend the list once it is.
     */
    private const array TEXT_STORED_NUMERIC_FUEL_FIELDS = [
        'nettomaximumvermogen',
        'nominaal_continu_maximumvermogen',
        'co2_uitstoot_gecombineerd',
        'co2_uitstoot_gewogen',
        'brandstofverbruik_buiten',
        'brandstofverbruik_gecombineerd',
        'brandstofverbruik_stad',
        'brandstofverbruik_gewogen_gecombineerd',
        'geluidsniveau_rijdend',
        'geluidsniveau_stationair',
        'uitstoot_deeltjes_licht',
        'uitstoot_deeltjes_zwaar',
        'roetuitstoot',
        'toerental_geluidsniveau',
    ];

    public function __construct(
        private Rdw $rdw,
        private Repository $cache = new Repository(new NullStore()),
        private int $maxAttempts = 2,
        private int $retryBackoffMs = 250,
        private int $maxProjectionRows = self::DEFAULT_MAX_PROJECTION_ROWS,
    ) {
    }

    public function run(Plan $plan): RunnerResult
    {
        if ($plan->display === DisplayHint::Unsupported) {
            return new RunnerResult(rows: [], soql: [], url: '');
        }

        $buckets = $this->buildBucketsByField($plan->groupBy, $plan->dataset);

        $builder = $this->builderFor($plan->dataset);
        $builder = $this->applyWhere($builder, $plan->where, $plan->dataset);
        $builder = $this->applySelectAndGroupBy($builder, $plan, $buckets);
        $builder = $this->applyAggregates($builder, $plan->aggregates, $plan->dataset);
        $builder = $this->applyOrderBy($builder, $plan->orderBy, $plan->aggregates, $buckets, $plan->dataset);

        if ($plan->limit !== null) {
            $builder = $builder->limit($plan->limit);
        }

        $soql = $builder->toSoqlParams();
        $url = $this->buildRequestUrl($soql, $plan->dataset);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->cache->remember(
            $this->cacheKey($soql, $plan->dataset),
            $this->cacheTtlSeconds($plan),
            fn (): array => $this->fetch($builder, $plan, $buckets, $soql, $url),
        );

        return new RunnerResult(rows: $rows, soql: $soql, url: $url);
    }

    /**
     * @return QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel>
     */
    private function builderFor(TargetDataset $dataset): QueryBuilder
    {
        return match ($dataset) {
            TargetDataset::RegisteredVehicles => $this->rdw->registeredVehicles(),
            TargetDataset::RegisteredVehicleFuels => $this->rdw->registeredVehicleFuels(),
        };
    }

    /**
     * @param QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel> $builder
     * @param array<string, BucketExpression> $buckets
     * @param array<string, string> $soql
     * @return list<array<string, mixed>>
     */
    private function fetch(QueryBuilder $builder, Plan $plan, array $buckets, array $soql, string $url): array
    {
        $attempt = 1;
        while (true) {
            try {
                return $this->execute($builder, $plan, $buckets);
            } catch (RateLimitException $e) {
                throw $e;
            } catch (Throwable $e) {
                if ($attempt < $this->maxAttempts && QueryExecutionException::isTransientFailure($e)) {
                    $this->backoff();
                    $attempt++;

                    continue;
                }

                throw new QueryExecutionException($plan, $soql, $url, $e);
            }
        }
    }

    private function cacheTtlSeconds(Plan $plan): int
    {
        return $plan->aggregates !== [] || $plan->groupBy !== []
            ? self::AGGREGATE_TTL_SECONDS
            : self::ROW_TTL_SECONDS;
    }

    /**
     * @param array<string, string> $soql
     */
    private function cacheKey(array $soql, TargetDataset $dataset): string
    {
        ksort($soql);

        return sprintf(
            'rdw:%s:%s:%s',
            $dataset->datasetId()->value,
            CarbonImmutable::now('Europe/Amsterdam')->toDateString(),
            sha1(json_encode($soql, JSON_THROW_ON_ERROR)),
        );
    }

    private function backoff(): void
    {
        if ($this->retryBackoffMs > 0) {
            usleep($this->retryBackoffMs * 1000);
        }
    }

    /**
     * @param array<string, string> $soql
     */
    private function buildRequestUrl(array $soql, TargetDataset $dataset): string
    {
        $base = rtrim($this->rdw->configuration()->baseUrl, '/');
        $datasetId = $dataset->datasetId()->value;
        $query = http_build_query($soql, '', '&', PHP_QUERY_RFC3986);

        return "{$base}/resource/{$datasetId}.json" . ($query !== '' ? "?{$query}" : '');
    }

    /**
     * @param QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel> $builder
     * @param list<WhereClause> $clauses
     * @return QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel>
     */
    private function applyWhere(QueryBuilder $builder, array $clauses, TargetDataset $dataset): QueryBuilder
    {
        foreach ($clauses as $clause) {
            $field = $this->resolveField($clause->field, $dataset);

            $builder = match ($clause->op) {
                WhereOp::Equals => $this->applyComparison($builder, $field, $clause->value, '=', $dataset),
                WhereOp::NotEquals => $this->applyComparison($builder, $field, $clause->value, '!=', $dataset),
                WhereOp::GreaterThan => $this->applyComparison($builder, $field, $clause->value, '>', $dataset),
                WhereOp::GreaterThanOrEqual => $this->applyComparison($builder, $field, $clause->value, '>=', $dataset),
                WhereOp::LessThan => $this->applyComparison($builder, $field, $clause->value, '<', $dataset),
                WhereOp::LessThanOrEqual => $this->applyComparison($builder, $field, $clause->value, '<=', $dataset),
                WhereOp::Contains => $builder->whereRaw($this->normalisedContainsExpression($field, $clause->value)),
                WhereOp::StartsWith => $builder->whereStartsWith($field, $clause->value),
                WhereOp::In => $this->applyIn($builder, $field, $clause->values, $dataset),
            };
        }

        return $builder;
    }

    /**
     * Emits `to_number(col) <op> n` for allowlisted text-stored numerics, otherwise the typed
     * `where()` path. NB: `!=` on a text-stored numeric drops NULL/empty cells (SoQL evaluates
     * `to_number('') != n` to NULL); acceptable since empty cells aren't meaningfully numeric.
     *
     * @param QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel> $builder
     * @return QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel>
     */
    private function applyComparison(QueryBuilder $builder, BackedEnum $field, string $rawValue, string $operator, TargetDataset $dataset): QueryBuilder
    {
        if ($this->needsToNumberWrap($field, $dataset)) {
            return $builder->whereRaw(sprintf(
                'to_number(%s) %s %s',
                $field->value,
                $operator,
                $this->assertNumericLiteral($field, $rawValue),
            ));
        }

        return $builder->where($field, $this->castValue($field, $rawValue, $dataset), $operator);
    }

    /**
     * @param QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel> $builder
     * @param list<string> $values
     * @return QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel>
     */
    private function applyIn(QueryBuilder $builder, BackedEnum $field, array $values, TargetDataset $dataset): QueryBuilder
    {
        if ($values === []) {
            throw new InvalidArgumentException(sprintf(
                'WhereOp::In on field "%s" requires a non-empty values list.',
                $field->name,
            ));
        }

        if ($this->needsToNumberWrap($field, $dataset)) {
            $literals = array_map(
                fn (string $v): string => $this->assertNumericLiteral($field, $v),
                $values,
            );

            return $builder->whereRaw(sprintf(
                'to_number(%s) IN (%s)',
                $field->value,
                implode(', ', $literals),
            ));
        }

        return $builder->whereIn($field, $this->castValues($field, $values, $dataset));
    }

    /**
     * Whether numeric comparisons against this field must be wrapped in `to_number(...)`. Narrow on
     * purpose: wrapping a column already stored as `number` is wasted work, and the wrap is the only
     * reason a raw-SoQL path exists. Keyed by `->value` (the Dutch source key) so a vendor
     * enum-case rename doesn't silently disable the wrap.
     */
    private function needsToNumberWrap(BackedEnum $field, TargetDataset $dataset): bool
    {
        if ($dataset !== TargetDataset::RegisteredVehicleFuels) {
            return false;
        }

        return in_array($field->value, self::TEXT_STORED_NUMERIC_FUEL_FIELDS, true);
    }

    /** Strict decimal literal: optional sign, digits, optional fractional part. No `1e5`, no whitespace, no hex. */
    private const string NUMERIC_LITERAL_PATTERN = '/^-?\d+(\.\d+)?$/';

    /**
     * Guards the `whereRaw` interpolation against SoQL injection. `is_numeric` would let scientific
     * notation through (`"1e500"` → SoQL literal `INF`), and trims/locale forms could shift between
     * PHP versions, so we lock to a strict decimal grammar.
     */
    private function assertNumericLiteral(BackedEnum $field, string $raw): string
    {
        if (preg_match(self::NUMERIC_LITERAL_PATTERN, $raw) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Numeric comparison on field "%s" requires a numeric value, got "%s".',
                $field->name,
                $raw,
            ));
        }

        return $raw;
    }

    /**
     * Separator-insensitive substring predicate, since RDW free-text fields spell values with inconsistent spaces/hyphens.
     */
    private function normalisedContainsExpression(BackedEnum $field, string $value): string
    {
        $term = strtoupper(str_replace([' ', '-'], '', $value));
        $quoted = "'" . str_replace("'", "''", $term) . "'";

        return sprintf(
            "contains(replace(replace(%s, ' ', ''), '-', ''), %s)",
            $field->value,
            $quoted,
        );
    }

    /**
     * @param QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel> $builder
     * @param array<string, BucketExpression> $buckets
     * @return QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel>
     */
    private function applySelectAndGroupBy(QueryBuilder $builder, Plan $plan, array $buckets): QueryBuilder
    {
        foreach ($plan->select as $name) {
            $builder = $builder->select($this->resolveField($name, $plan->dataset));
        }

        foreach ($plan->groupBy as $key) {
            $bucket = $buckets[$key->field] ?? null;
            if ($bucket !== null) {
                $builder = $builder
                    ->selectRaw($bucket->expression, $bucket->alias)
                    ->groupByRaw($bucket->expression);

                continue;
            }

            $field = $this->resolveField($key->field, $plan->dataset);
            $builder = $builder->select($field)->groupBy($field);
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel> $builder
     * @param list<AggregateClause> $aggregates
     * @return QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel>
     */
    private function applyAggregates(QueryBuilder $builder, array $aggregates, TargetDataset $dataset): QueryBuilder
    {
        foreach ($aggregates as $agg) {
            $field = $agg->field !== null ? $this->resolveField($agg->field, $dataset) : null;

            $builder = match ($agg->fn) {
                AggregateFn::Count => $builder->count($field, $agg->alias),
                AggregateFn::CountDistinct => $builder->countDistinct($this->requireField($field, AggregateFn::CountDistinct), $agg->alias),
                AggregateFn::Sum => $builder->sum($this->requireField($field, AggregateFn::Sum), $agg->alias),
                AggregateFn::Avg => $builder->avg($this->requireField($field, AggregateFn::Avg), $agg->alias),
                AggregateFn::Min => $builder->min($this->requireField($field, AggregateFn::Min), $agg->alias),
                AggregateFn::Max => $builder->max($this->requireField($field, AggregateFn::Max), $agg->alias),
            };
        }

        return $builder;
    }

    /**
     * @param QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel> $builder
     * @param list<OrderClause> $orderBy
     * @param list<AggregateClause> $aggregates
     * @param array<string, BucketExpression> $buckets
     * @return QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel>
     */
    private function applyOrderBy(QueryBuilder $builder, array $orderBy, array $aggregates, array $buckets, TargetDataset $dataset): QueryBuilder
    {
        $aliasSet = [];
        foreach ($aggregates as $agg) {
            $aliasSet[$agg->alias] = true;
        }

        $notNullApplied = [];

        foreach ($orderBy as $clause) {
            $direction = $clause->direction === OrderDirection::Desc ? SortDirection::Desc : SortDirection::Asc;

            if (isset($buckets[$clause->expr])) {
                $builder = $builder->orderByRaw($buckets[$clause->expr]->expression . ' ' . $direction->value);

                continue;
            }

            $field = $this->tryResolveField($clause->expr, $dataset);
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
     * @param QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel> $builder
     * @param array<string, BucketExpression> $buckets
     * @return list<array<string, mixed>>
     */
    private function execute(QueryBuilder $builder, Plan $plan, array $buckets): array
    {
        if ($plan->aggregates !== [] || $plan->groupBy !== []) {
            return $this->normaliseProjectionRows($this->fetchProjectionRows($builder, $plan), $plan->aggregates, $buckets, $plan->dataset);
        }

        $records = $builder->get();

        return array_map(fn (object $r): array => $this->recordToArray($r, $plan->select, $plan->dataset), $records);
    }

    /**
     * @param QueryBuilder<RegisteredVehicle|RegisteredVehicleFuel> $builder
     * @return list<array<string, mixed>>
     */
    private function fetchProjectionRows(QueryBuilder $builder, Plan $plan): array
    {
        if ($plan->limit !== null) {
            return $builder->getProjection();
        }

        $rows = [];
        $offset = 0;
        do {
            $page = $builder->limit(self::PROJECTION_PAGE_SIZE)->offset($offset)->getProjection();
            $rows = array_merge($rows, $page);
            $offset += self::PROJECTION_PAGE_SIZE;
        } while (count($page) === self::PROJECTION_PAGE_SIZE && count($rows) < $this->maxProjectionRows);

        return $rows;
    }

    /**
     * @param list<string> $select
     * @return array<string, mixed>
     */
    private function recordToArray(object $record, array $select, TargetDataset $dataset): array
    {
        $vars = get_object_vars($record);
        $schema = $this->schema($dataset);

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
     * @param list<array<string, mixed>> $rows
     * @param list<AggregateClause> $aggregates
     * @param array<string, BucketExpression> $buckets
     * @return list<array<string, mixed>>
     */
    private function normaliseProjectionRows(array $rows, array $aggregates, array $buckets, TargetDataset $dataset): array
    {
        $schema = $this->schema($dataset);
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
     * @param list<GroupKey> $groupBy
     * @return array<string, BucketExpression>
     */
    private function buildBucketsByField(array $groupBy, TargetDataset $dataset): array
    {
        $schema = $this->schema($dataset);
        $out = [];

        foreach ($groupBy as $key) {
            if ($key->bucket === Bucket::None) {
                continue;
            }

            $descriptor = $schema->byEnumCase[$key->field] ?? null;
            if ($descriptor === null) {
                throw new InvalidArgumentException(sprintf('Unknown field "%s" for dataset %s.', $key->field, $dataset->value));
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

    private function resolveField(string $name, TargetDataset $dataset): BackedEnum
    {
        $field = $this->tryResolveField($name, $dataset);
        if ($field === null) {
            throw new InvalidArgumentException(sprintf('Unknown field "%s" for dataset %s.', $name, $dataset->value));
        }

        return $field;
    }

    private function tryResolveField(string $name, TargetDataset $dataset): ?BackedEnum
    {
        return FieldLookup::tryGet($dataset, $name);
    }

    private function requireField(?BackedEnum $field, AggregateFn $fn): BackedEnum
    {
        if ($field === null) {
            throw new InvalidArgumentException(sprintf('Aggregate %s requires a field.', $fn->value));
        }

        return $field;
    }

    /**
     * @param list<string> $raw
     * @return list<mixed>
     */
    private function castValues(BackedEnum $field, array $raw, TargetDataset $dataset): array
    {
        return array_map(
            fn (string $v): mixed => $this->castValue($field, $v, $dataset),
            $raw,
        );
    }

    private function castValue(BackedEnum $field, string $raw, TargetDataset $dataset): mixed
    {
        if ($field === RegisteredVehicleField::LicensePlate || $field === RegisteredVehicleFuelField::LicensePlate) {
            return PlateNormaliser::normalise($raw);
        }

        $cast = $this->fieldCast($field, $dataset);
        if ($cast === null) {
            return $raw;
        }

        return match ($cast) {
            CastType::Boolean => in_array(strtolower($raw), ['true', '1', 'ja', 'yes'], true),
            CastType::Integer => (int) $raw,
            CastType::Decimal => is_numeric($raw) ? (float) $raw : $raw,
            CastType::CalendarDate, CastType::NumericDate => CarbonImmutable::parse($raw, 'UTC'),
            default => $raw,
        };
    }

    private function fieldCast(BackedEnum $field, TargetDataset $dataset): ?CastType
    {
        return $this->schema($dataset)->byEnumCase[$field->name]->cast ?? null;
    }

    private function schema(TargetDataset $dataset): DatasetSchema
    {
        return $this->rdw->schemas()->get($dataset->datasetId());
    }
}
