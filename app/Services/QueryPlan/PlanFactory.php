<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use BackedEnum;
use InvalidArgumentException;
use NiekNijland\RDW\Fields\RegisteredVehicleField;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds a typed {@see Plan} from the loose array Prism hands back.
 *
 * Prism + OpenAI strict schema validates the shape, but we still re-validate
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

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): Plan
    {
        $select = $this->parseFieldList(array_values($this->arrayOrEmpty($data, 'select')));
        $groupBy = $this->parseFieldList(array_values($this->arrayOrEmpty($data, 'groupBy')));
        $aggregates = array_values(array_map($this->parseAggregate(...), $this->arrayOrEmpty($data, 'aggregates')));
        $display = $this->parseDisplay($data['display'] ?? null);

        [$select, $groupBy] = $this->normaliseSelectAndGroupBy($select, $groupBy, $aggregates, $display);

        return new Plan(
            where: array_values(array_map($this->parseWhere(...), $this->arrayOrEmpty($data, 'where'))),
            select: $select,
            groupBy: $groupBy,
            aggregates: $aggregates,
            orderBy: array_values(array_map($this->parseOrder(...), $this->arrayOrEmpty($data, 'orderBy'))),
            limit: isset($data['limit']) ? max(self::LIMIT_MIN, min(self::LIMIT_MAX, (int) $data['limit'])) : null,
            display: $display,
            explanation: (string) ($data['explanation'] ?? ''),
        );
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
     * @param list<string> $groupBy
     * @param list<AggregateClause> $aggregates
     * @return array{0: list<string>, 1: list<string>}
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

        $merged = array_values(array_unique([...$groupBy, ...$select]));

        $this->logger->debug('PlanFactory promoted select into groupBy', [
            'originalSelect' => $select,
            'originalGroupBy' => $groupBy,
            'mergedGroupBy' => $merged,
        ]);

        return [[], $merged];
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
