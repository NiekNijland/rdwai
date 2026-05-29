<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use BackedEnum;
use InvalidArgumentException;
use NiekNijland\RDW\Schema\CastType;
use NiekNijland\RDW\Schema\DatasetSchema;
use NiekNijland\RDW\Schema\SchemaRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
    public function fromArray(array $data, TargetDataset $dataset): Plan
    {
        $select = $this->parseFieldList(array_values($this->arrayOrEmpty($data, 'select')), $dataset);
        $groupBy = $this->parseGroupBy(array_values($this->arrayOrEmpty($data, 'groupBy')), $dataset);
        $aggregates = array_values(array_map(
            fn (mixed $clause): AggregateClause => $this->parseAggregate(is_array($clause) ? $clause : [], $dataset),
            $this->arrayOrEmpty($data, 'aggregates'),
        ));
        $where = array_values(array_map(
            fn (mixed $clause): WhereClause => $this->parseWhere(is_array($clause) ? $clause : [], $dataset),
            $this->arrayOrEmpty($data, 'where'),
        ));
        $orderBy = array_values(array_map($this->parseOrder(...), $this->arrayOrEmpty($data, 'orderBy')));
        $display = $this->parseDisplay($data['display'] ?? null);
        $explanation = (string) ($data['explanation'] ?? '');

        $display = $this->downgradeBogusCountToUnsupported($display, $where, $select, $groupBy, $aggregates);

        if ($display === DisplayHint::Unsupported) {
            return new Plan(
                dataset: $dataset,
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

        [$select, $groupBy] = $this->normaliseSelectAndGroupBy($select, $groupBy, $aggregates, $display, $dataset);
        $groupBy = $this->normaliseTimeseriesGroupBy($groupBy, $display, $dataset);

        return new Plan(
            dataset: $dataset,
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
     * Downgrades a wholly-empty `count` plan (a common prompt-injection shape) to a refusal.
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
     * Repairs the SoQL rule that a bare column may not mix with an aggregate unless it is in GROUP BY.
     *
     * @param list<string> $select
     * @param list<GroupKey> $groupBy
     * @param list<AggregateClause> $aggregates
     * @return array{0: list<string>, 1: list<GroupKey>}
     */
    private function normaliseSelectAndGroupBy(array $select, array $groupBy, array $aggregates, DisplayHint $display, TargetDataset $dataset): array
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

        $schema = $this->schemas->get($dataset->datasetId());
        $existingFields = array_map(static fn (GroupKey $k): string => $k->field, $groupBy);
        $promoted = $groupBy;
        foreach ($select as $field) {
            if (in_array($field, $existingFields, true)) {
                continue;
            }
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
     * Strips non-date fields from a timeseries groupBy so count(*) doesn't collapse to one per row.
     *
     * @param list<GroupKey> $groupBy
     * @return list<GroupKey>
     */
    private function normaliseTimeseriesGroupBy(array $groupBy, DisplayHint $display, TargetDataset $dataset): array
    {
        if ($display !== DisplayHint::Timeseries || $groupBy === []) {
            return $groupBy;
        }

        $schema = $this->schemas->get($dataset->datasetId());
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
    private function parseWhere(array $clause, TargetDataset $dataset): WhereClause
    {
        $field = (string) ($clause['field'] ?? '');
        $this->assertFieldExists($field, $dataset);

        $rawValues = $clause['values'] ?? [];
        $values = is_array($rawValues)
            ? array_values(array_map(static fn (mixed $v): string => (string) $v, $rawValues))
            : [];

        return new WhereClause(
            field: $field,
            op: $this->parseEnum(WhereOp::class, (string) ($clause['op'] ?? ''), 'where.op'),
            value: (string) ($clause['value'] ?? ''),
            values: $values,
        );
    }

    /**
     * @param array<string, mixed> $clause
     */
    private function parseAggregate(array $clause, TargetDataset $dataset): AggregateClause
    {
        $rawField = isset($clause['field']) ? (string) $clause['field'] : null;
        $field = ($rawField === null || $rawField === '' || $rawField === '*') ? null : $rawField;

        if ($field !== null) {
            $this->assertFieldExists($field, $dataset);
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
    private function parseFieldList(array $fields, TargetDataset $dataset): array
    {
        $out = [];
        foreach ($fields as $f) {
            $name = (string) $f;
            $this->assertFieldExists($name, $dataset);
            $out[] = $name;
        }

        return $out;
    }

    /**
     * @param list<mixed> $items
     * @return list<GroupKey>
     */
    private function parseGroupBy(array $items, TargetDataset $dataset): array
    {
        $schema = $this->schemas->get($dataset->datasetId());
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('groupBy items must be {field, bucket} objects.');
            }

            $field = (string) ($item['field'] ?? '');
            $bucket = $this->parseEnum(Bucket::class, (string) ($item['bucket'] ?? 'none'), 'groupBy.bucket');

            $this->assertFieldExists($field, $dataset);

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

    private function assertFieldExists(string $name, TargetDataset $dataset): void
    {
        if (FieldLookup::tryGet($dataset, $name) === null) {
            throw new InvalidArgumentException(sprintf('Unknown field "%s" for dataset %s.', $name, $dataset->value));
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
