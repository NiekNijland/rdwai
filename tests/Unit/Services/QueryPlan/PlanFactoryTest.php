<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\AggregateFn;
use App\Services\QueryPlan\DisplayHint;
use App\Services\QueryPlan\OrderDirection;
use App\Services\QueryPlan\PlanFactory;
use App\Services\QueryPlan\WhereOp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlanFactoryTest extends TestCase
{
    public function test_builds_a_complete_plan_from_a_well_formed_array(): void
    {
        $factory = new PlanFactory();

        $plan = $factory->fromArray([
            'where' => [
                ['field' => 'Brand', 'op' => 'eq', 'value' => 'VOLKSWAGEN'],
                ['field' => 'PrimaryColor', 'op' => 'contains', 'value' => 'wit'],
            ],
            'select' => [],
            'groupBy' => ['PrimaryColor'],
            'aggregates' => [
                ['fn' => 'count', 'field' => '*', 'alias' => 'n'],
            ],
            'orderBy' => [
                ['expr' => 'n', 'direction' => 'desc'],
            ],
            'limit' => 25,
            'display' => 'bars',
            'explanation' => 'Counts white VWs.',
        ]);

        self::assertCount(2, $plan->where);
        self::assertSame('Brand', $plan->where[0]->field);
        self::assertSame(WhereOp::Equals, $plan->where[0]->op);
        self::assertSame('VOLKSWAGEN', $plan->where[0]->value);
        self::assertSame(WhereOp::Contains, $plan->where[1]->op);

        self::assertSame([], $plan->select);
        self::assertSame(['PrimaryColor'], $plan->groupBy);

        self::assertCount(1, $plan->aggregates);
        self::assertSame(AggregateFn::Count, $plan->aggregates[0]->fn);
        self::assertNull($plan->aggregates[0]->field);
        self::assertSame('n', $plan->aggregates[0]->alias);

        self::assertCount(1, $plan->orderBy);
        self::assertSame('n', $plan->orderBy[0]->expr);
        self::assertSame(OrderDirection::Desc, $plan->orderBy[0]->direction);

        self::assertSame(25, $plan->limit);
        self::assertSame(DisplayHint::Bars, $plan->display);
        self::assertSame('Counts white VWs.', $plan->explanation);
    }

    public function test_drops_spurious_select_fields_for_count_display(): void
    {
        $factory = new PlanFactory();

        $plan = $factory->fromArray([
            'select' => ['LicensePlate'],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'count',
        ]);

        self::assertSame([], $plan->select);
        self::assertSame([], $plan->groupBy);
    }

    public function test_promotes_select_into_group_by_when_aggregates_are_present(): void
    {
        $factory = new PlanFactory();

        $plan = $factory->fromArray([
            'select' => ['CommercialName'],
            'groupBy' => [],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
            'limit' => 1,
            'display' => 'bars',
        ]);

        self::assertSame([], $plan->select);
        self::assertSame(['CommercialName'], $plan->groupBy);
    }

    public function test_merges_select_into_existing_group_by_without_duplicates(): void
    {
        $factory = new PlanFactory();

        $plan = $factory->fromArray([
            'select' => ['PrimaryColor', 'CommercialName'],
            'groupBy' => ['PrimaryColor'],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'bars',
        ]);

        self::assertSame([], $plan->select);
        self::assertSame(['PrimaryColor', 'CommercialName'], $plan->groupBy);
    }

    public function test_preserves_select_when_no_aggregates_are_present(): void
    {
        $factory = new PlanFactory();

        $plan = $factory->fromArray([
            'select' => ['LicensePlate', 'CommercialName'],
            'groupBy' => [],
            'aggregates' => [],
            'limit' => 10,
            'display' => 'table',
        ]);

        self::assertSame(['LicensePlate', 'CommercialName'], $plan->select);
        self::assertSame([], $plan->groupBy);
    }

    public function test_clamps_limit_to_the_supported_range(): void
    {
        $factory = new PlanFactory();

        self::assertSame(1, $this->planWithLimit($factory, 0)->limit);
        self::assertSame(1, $this->planWithLimit($factory, -5)->limit);
        self::assertSame(1000, $this->planWithLimit($factory, 99999)->limit);
        self::assertNull($this->planWithLimit($factory, null)->limit);
    }

    public function test_treats_empty_or_star_aggregate_field_as_null(): void
    {
        $factory = new PlanFactory();

        $planEmpty = $factory->fromArray([
            'aggregates' => [['fn' => 'count', 'field' => '', 'alias' => 'n']],
            'display' => 'count',
        ]);
        $planStar = $factory->fromArray([
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'count',
        ]);

        self::assertNull($planEmpty->aggregates[0]->field);
        self::assertNull($planStar->aggregates[0]->field);
    }

    public function test_rejects_unknown_field_names(): void
    {
        $factory = new PlanFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown RegisteredVehicleField "NotAField"');

        $factory->fromArray([
            'where' => [['field' => 'NotAField', 'op' => 'eq', 'value' => 'x']],
        ]);
    }

    public function test_rejects_unknown_enum_values_with_typed_exception(): void
    {
        $factory = new PlanFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value "like" for where.op');

        $factory->fromArray([
            'where' => [['field' => 'Brand', 'op' => 'like', 'value' => 'VW']],
        ]);
    }

    public function test_rejects_unknown_display_hint(): void
    {
        $factory = new PlanFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value "pie" for display');

        $factory->fromArray(['display' => 'pie']);
    }

    public function test_rejects_invalid_aggregate_alias_instead_of_silently_rewriting(): void
    {
        $factory = new PlanFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid aggregate alias "count(*)"');

        $factory->fromArray([
            'aggregates' => [
                ['fn' => 'count', 'field' => '*', 'alias' => 'count(*)'],
            ],
            'display' => 'count',
        ]);
    }

    public function test_tolerates_missing_keys_with_safe_defaults(): void
    {
        $factory = new PlanFactory();

        $plan = $factory->fromArray([]);

        self::assertSame([], $plan->where);
        self::assertSame([], $plan->select);
        self::assertSame([], $plan->groupBy);
        self::assertSame([], $plan->aggregates);
        self::assertSame([], $plan->orderBy);
        self::assertNull($plan->limit);
        self::assertSame(DisplayHint::Table, $plan->display);
        self::assertSame('', $plan->explanation);
    }

    public function test_ignores_non_array_collection_payloads(): void
    {
        $factory = new PlanFactory();

        $plan = $factory->fromArray([
            'where' => 'not-an-array',
            'select' => null,
        ]);

        self::assertSame([], $plan->where);
        self::assertSame([], $plan->select);
    }

    private function planWithLimit(PlanFactory $factory, ?int $limit): \App\Services\QueryPlan\Plan
    {
        return $factory->fromArray([
            'limit' => $limit,
            'display' => 'table',
        ]);
    }
}
