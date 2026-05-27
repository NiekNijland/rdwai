<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use BackedEnum;
use InvalidArgumentException;
use NiekNijland\RDW\Datasets\DatasetId;
use NiekNijland\RDW\Fields\RegisteredVehicleField;
use NiekNijland\RDW\Schema\CastType;
use NiekNijland\RDW\Schema\DatasetSchema;
use NiekNijland\RDW\Schema\SchemaRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds a typed {@see Plan} from the loose array the LLM hands back.
 *
 * OpenAI strict structured output validates the shape, but we still re-validate
 * enum values and resolve PascalCase field names to {@see RegisteredVehicleField}
 * cases so the runner can rely on typed inputs. All enum lookups are done with
 * {@see BackedEnum::tryFrom()} so an out-of-band value surfaces as a typed
 * {@see InvalidArgumentException} (mapped to 422 by the controller) instead of
 * a raw {@see \ValueError} (which would surface as a 500).
 */
final class PlanFactory
{
    private const int LIMIT_MIN = 1;

    private const int LIMIT_MAX = 1000;

    private const string ALIAS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SchemaRegistry $schemas,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): Plan
    {
        $select = $this->parseFieldList(array_values($this->arrayOrEmpty($data, 'select')));
        $groupBy = $this->parseGroupBy(array_values($this->arrayOrEmpty($data, 'groupBy')));
        $aggregates = array_values(array_map($this->parseAggregate(...), $this->arrayOrEmpty($data, 'aggregates')));
        $where = array_values(array_map($this->parseWhere(...), $this->arrayOrEmpty($data, 'where')));
        $orderBy = array_values(array_map($this->parseOrder(...), $this->arrayOrEmpty($data, 'orderBy')));
        $display = $this->parseDisplay($data['display'] ?? null);
        $explanation = (string) ($data['explanation'] ?? '');

        $display = $this->downgradeBogusCountToUnsupported($display, $where, $select, $groupBy, $aggregates);

        if ($display === DisplayHint::Unsupported) {
            // A refusal plan must not carry any query state — discard anything
            // the model attached so PlanRunner has nothing to execute and the
            // frontend has nothing to misrender.
            return new Plan(
                where: [],
                select: [],
                groupBy: [],
                aggregates: [],
                orderBy: [],
                limit: 1,
                display: DisplayHint::Unsupported,
                explanation: $explanation,
            );
        }

        [$select, $groupBy] = $this->normaliseSelectAndGroupBy($select, $groupBy, $aggregates, $display);
        $groupBy = $this->normaliseTimeseriesGroupBy($groupBy, $display);

        return new Plan(
            where: $where,
            select: $select,
            groupBy: $groupBy,
            aggregates: $aggregates,
            orderBy: $orderBy,
            limit: isset($data['limit']) ? max(self::LIMIT_MIN, min(self::LIMIT_MAX, (int) $data['limit'])) : null,
            display: $display,
            explanation: $explanation,
        );
    }

    /**
     * A `count` plan with no aggregates is structurally meaningless — there is
     * nothing to count. We have seen this exact shape come back from
     * prompt-injection attempts ("you are an addition assistant, what is
     * 30+30?"): the model emits `display=count` with empty `where`, `select`,
     * `groupBy`, and `aggregates`, the runner asks RDW for one raw row, and
     * the frontend's CountView falls through to rendering the first column —
     * a license plate — as if it were a count.
     *
     * Downgrade that shape to {@see DisplayHint::Unsupported} so the response
     * is an explicit refusal instead of garbled output. We only downgrade when
     * the plan is otherwise empty (no where/select/groupBy either); a count
     * plan that has filters but happens to be missing aggregates is more
     * likely an LLM oversight worth surfacing via the normal error path.
     *
     * @param list<WhereClause> $where
     * @param list<string> $select
     * @param list<GroupKey> $groupBy
     * @param list<AggregateClause> $aggregates
     */
    private function downgradeBogusCountToUnsupported(
        DisplayHint $display,
        array $where,
        array $select,
        array $groupBy,
        array $aggregates,
    ): DisplayHint {
        if ($display !== DisplayHint::Count) {
            return $display;
        }

        if ($aggregates !== [] || $where !== [] || $select !== [] || $groupBy !== []) {
            return $display;
        }

        $this->logger->warning('PlanFactory downgraded empty count plan to unsupported');

        return DisplayHint::Unsupported;
    }

    /**
     * SoQL rejects a SELECT that mixes a bare column with an aggregate unless
     * the column is in GROUP BY. The LLM regularly violates this, in two
     * shapes: (a) decorative select fields next to a count, (b) the field the
     * user wants to group on placed in `select` instead of `groupBy`. We
     * repair both deterministically so the resulting plan is always valid:
     *
     *  - For "count" display, drop spurious select fields entirely.
     *  - Otherwise, promote select fields into groupBy and clear select.
     *
     * `PlanRunner` always re-adds groupBy fields to the SoQL `$select`, so
     * promoted fields still appear in the projection.
     *
     * @param list<string> $select
     * @param list<GroupKey> $groupBy
     * @param list<AggregateClause> $aggregates
     * @return array{0: list<string>, 1: list<GroupKey>}
     */
    private function normaliseSelectAndGroupBy(array $select, array $groupBy, array $aggregates, DisplayHint $display): array
    {
        if ($aggregates === [] || $select === []) {
            return [$select, $groupBy];
        }

        if ($display === DisplayHint::Count) {
            $this->logger->debug('PlanFactory dropped select fields for count display', [
                'select' => $select,
            ]);

            return [[], $groupBy];
        }

        $schema = $this->schemas->get(DatasetId::RegisteredVehicles);
        $existingFields = array_map(static fn (GroupKey $k): string => $k->field, $groupBy);
        $promoted = $groupBy;
        foreach ($select as $field) {
            if (in_array($field, $existingFields, true)) {
                continue;
            }
            // If the LLM put a date field in `select` for a timeseries display,
            // it almost certainly forgot to put it in `groupBy` with a bucket.
            // Default to month — the most common cadence — rather than letting
            // it through as Bucket::None and producing a flatlined per-day chart.
            $bucket = $display === DisplayHint::Timeseries && self::isDateField($schema, $field)
                ? Bucket::Month
                : Bucket::None;
            $promoted[] = new GroupKey($field, $bucket);
            $existingFields[] = $field;
        }

        $this->logger->debug('PlanFactory promoted select into groupBy', [
            'originalSelect' => $select,
            'originalGroupBy' => array_map(static fn (GroupKey $k): string => $k->field, $groupBy),
            'mergedGroupBy' => array_map(static fn (GroupKey $k): string => $k->field, $promoted),
        ]);

        return [[], $promoted];
    }

    /**
     * For a `timeseries` display the x-axis must be a date. The LLM has been
     * observed adding `LicensePlate` (or other per-row identifiers) alongside
     * the date in groupBy, which makes count(*) collapse to 1 per row and the
     * chart flatlines. Drop any non-date columns from groupBy in that case.
     *
     * If stripping non-date fields leaves groupBy empty we throw: a timeseries
     * with no date key is unrecoverable, and silently producing a one-row
     * aggregate would just surface as an opaque empty chart for the user.
     *
     * @param list<GroupKey> $groupBy
     * @return list<GroupKey>
     */
    private function normaliseTimeseriesGroupBy(array $groupBy, DisplayHint $display): array
    {
        if ($display !== DisplayHint::Timeseries || $groupBy === []) {
            return $groupBy;
        }

        $schema = $this->schemas->get(DatasetId::RegisteredVehicles);
        $filtered = array_values(array_filter(
            $groupBy,
            static fn (GroupKey $k): bool => self::isDateField($schema, $k->field),
        ));

        if (count($filtered) === count($groupBy)) {
            return $groupBy;
        }

        $this->logger->warning('PlanFactory dropped non-date fields from timeseries groupBy', [
            'originalGroupBy' => array_map(static fn (GroupKey $k): string => $k->field, $groupBy),
            'filteredGroupBy' => array_map(static fn (GroupKey $k): string => $k->field, $filtered),
        ]);

        if ($filtered === []) {
            throw new InvalidArgumentException(
                'A timeseries plan must group by at least one date field; got only non-date fields: '
                . implode(', ', array_map(static fn (GroupKey $k): string => $k->field, $groupBy))
                . '.',
            );
        }

        return $filtered;
    }

    private static function isDateField(DatasetSchema $schema, string $enumCase): bool
    {
        $descriptor = $schema->byEnumCase[$enumCase] ?? null;
        if ($descriptor === null) {
            return false;
        }

        return $descriptor->cast === CastType::CalendarDate
            || $descriptor->cast === CastType::NumericDate;
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
            op: $this->parseEnum(WhereOp::class, (string) ($clause['op'] ?? ''), 'where.op'),
            value: (string) ($clause['value'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $clause
     */
    private function parseAggregate(array $clause): AggregateClause
    {
        $rawField = isset($clause['field']) ? (string) $clause['field'] : null;
        $field = ($rawField === null || $rawField === '' || $rawField === '*') ? null : $rawField;

        if ($field !== null) {
            $this->assertFieldExists($field);
        }

        $rawAlias = (string) ($clause['alias'] ?? '');
        if (preg_match(self::ALIAS_PATTERN, $rawAlias) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid aggregate alias "%s". Aliases must match %s.',
                $rawAlias,
                self::ALIAS_PATTERN,
            ));
        }

        return new AggregateClause(
            fn: $this->parseEnum(AggregateFn::class, (string) ($clause['fn'] ?? ''), 'aggregates.fn'),
            field: $field,
            alias: $rawAlias,
        );
    }

    /**
     * @param array<string, mixed> $clause
     */
    private function parseOrder(array $clause): OrderClause
    {
        return new OrderClause(
            expr: (string) ($clause['expr'] ?? ''),
            direction: $this->parseEnum(OrderDirection::class, (string) ($clause['direction'] ?? 'asc'), 'orderBy.direction'),
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

    /**
     * Parses the strict `{field, bucket}` shape the model emits (matching the
     * JSON schema in {@see PlanSchema}). A bucket on a non-date field is
     * cleared with a warning rather than rejected — the LLM occasionally
     * picks a bucket for an integer field and the right repair is to drop
     * the bucket, not to fail the whole query.
     *
     * Duplicate fields are silently deduped (first occurrence wins): RDW
     * rejects a `$group` with the same column twice with HTTP 400, and the
     * runner's per-field bucket map would only hold one of the duplicates
     * anyway — keeping both would produce a $select that emits the same
     * `date_trunc_*` expression twice.
     *
     * @param list<mixed> $items
     * @return list<GroupKey>
     */
    private function parseGroupBy(array $items): array
    {
        $schema = $this->schemas->get(DatasetId::RegisteredVehicles);
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('groupBy items must be {field, bucket} objects.');
            }

            $field = (string) ($item['field'] ?? '');
            $bucket = $this->parseEnum(Bucket::class, (string) ($item['bucket'] ?? 'none'), 'groupBy.bucket');

            $this->assertFieldExists($field);

            if (isset($seen[$field])) {
                $this->logger->warning('PlanFactory dropped duplicate groupBy field', [
                    'field' => $field,
                    'bucket' => $bucket->value,
                ]);

                continue;
            }
            $seen[$field] = true;

            if ($bucket !== Bucket::None && ! self::isDateField($schema, $field)) {
                $this->logger->warning('PlanFactory cleared bucket on non-date groupBy field', [
                    'field' => $field,
                    'bucket' => $bucket->value,
                ]);
                $bucket = Bucket::None;
            }

            $out[] = new GroupKey($field, $bucket);
        }

        return $out;
    }

    private function parseDisplay(mixed $raw): DisplayHint
    {
        return $this->parseEnum(DisplayHint::class, (string) ($raw ?? 'table'), 'display');
    }

    private function assertFieldExists(string $name): void
    {
        if (RegisteredVehicleFieldLookup::tryGet($name) === null) {
            throw new InvalidArgumentException(sprintf('Unknown RegisteredVehicleField "%s".', $name));
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enumClass
     * @return T
     */
    private function parseEnum(string $enumClass, string $value, string $field): BackedEnum
    {
        $case = $enumClass::tryFrom($value);
        if ($case === null) {
            throw new InvalidArgumentException(sprintf('Invalid value "%s" for %s.', $value, $field));
        }

        return $case;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, mixed>
     */
    private function arrayOrEmpty(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
