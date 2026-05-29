<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\AggregateFn;
use App\Services\QueryPlan\Bucket;
use App\Services\QueryPlan\DisplayHint;
use App\Services\QueryPlan\GroupKey;
use App\Services\QueryPlan\OrderDirection;
use App\Services\QueryPlan\Plan;
use App\Services\QueryPlan\PlanFactory;
use App\Services\QueryPlan\TargetDataset;
use App\Services\QueryPlan\WhereOp;
use InvalidArgumentException;
use NiekNijland\RDW\Schema\SchemaRegistry;
use PHPUnit\Framework\TestCase;

final class PlanFactoryTest extends TestCase
{
    public function test_builds_a_complete_plan_from_a_well_formed_array(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'where' => [
                ['field' => 'Brand', 'op' => 'eq', 'value' => 'VOLKSWAGEN'],
                ['field' => 'PrimaryColor', 'op' => 'contains', 'value' => 'wit'],
            ],
            'select' => [],
            'groupBy' => [['field' => 'PrimaryColor', 'bucket' => 'none']],
            'aggregates' => [
                ['fn' => 'count', 'field' => '*', 'alias' => 'n'],
            ],
            'orderBy' => [
                ['expr' => 'n', 'direction' => 'desc'],
            ],
            'limit' => 25,
            'display' => 'bars',
            'explanation' => 'Counts white VWs.',
        ], TargetDataset::RegisteredVehicles);

        self::assertCount(2, $plan->where);
        self::assertSame('Brand', $plan->where[0]->field);
        self::assertSame(WhereOp::Equals, $plan->where[0]->op);
        self::assertSame('VOLKSWAGEN', $plan->where[0]->value);
        self::assertSame(WhereOp::Contains, $plan->where[1]->op);

        self::assertSame([], $plan->select);
        self::assertEquals([new GroupKey('PrimaryColor', Bucket::None)], $plan->groupBy);

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
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'select' => ['LicensePlate'],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'count',
        ], TargetDataset::RegisteredVehicles);

        self::assertSame([], $plan->select);
        self::assertSame([], $plan->groupBy);
    }

    public function test_promotes_select_into_group_by_when_aggregates_are_present(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'select' => ['CommercialName'],
            'groupBy' => [],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
            'limit' => 1,
            'display' => 'bars',
        ], TargetDataset::RegisteredVehicles);

        self::assertSame([], $plan->select);
        self::assertEquals([new GroupKey('CommercialName', Bucket::None)], $plan->groupBy);
    }

    public function test_merges_select_into_existing_group_by_without_duplicates(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'select' => ['PrimaryColor', 'CommercialName'],
            'groupBy' => [['field' => 'PrimaryColor', 'bucket' => 'none']],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'bars',
        ], TargetDataset::RegisteredVehicles);

        self::assertSame([], $plan->select);
        self::assertEquals(
            [
                new GroupKey('PrimaryColor', Bucket::None),
                new GroupKey('CommercialName', Bucket::None),
            ],
            $plan->groupBy,
        );
    }

    public function test_promotes_date_select_into_groupby_with_month_bucket_for_timeseries(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'select' => ['RegistrationDate'],
            'groupBy' => [],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [['expr' => 'RegistrationDate', 'direction' => 'asc']],
            'limit' => 60,
            'display' => 'timeseries',
        ], TargetDataset::RegisteredVehicles);

        self::assertEquals([new GroupKey('RegistrationDate', Bucket::Month)], $plan->groupBy);
    }

    public function test_promotion_keeps_bucket_none_for_non_date_fields_or_non_timeseries(): void
    {
        $factory = $this->factory();

        $bars = $factory->fromArray([
            'select' => ['CommercialName'],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'bars',
        ], TargetDataset::RegisteredVehicles);
        self::assertEquals([new GroupKey('CommercialName', Bucket::None)], $bars->groupBy);

        $timeseries = $factory->fromArray([
            'select' => ['PrimaryColor'],
            'groupBy' => [['field' => 'RegistrationDate', 'bucket' => 'month']],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'timeseries',
        ], TargetDataset::RegisteredVehicles);
        self::assertEquals(
            [new GroupKey('RegistrationDate', Bucket::Month)],
            $timeseries->groupBy,
        );
    }

    public function test_deduplicates_group_by_when_the_same_field_appears_twice(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'groupBy' => [
                ['field' => 'FirstAdmissionDate', 'bucket' => 'year'],
                ['field' => 'FirstAdmissionDate', 'bucket' => 'month'],
            ],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'timeseries',
        ], TargetDataset::RegisteredVehicles);

        // First occurrence wins so the SoQL has no duplicated date_trunc_*.
        self::assertEquals([new GroupKey('FirstAdmissionDate', Bucket::Year)], $plan->groupBy);
    }

    public function test_preserves_existing_bucket_when_select_fields_merge_in(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'select' => ['FirstAdmissionDate', 'PrimaryColor'],
            'groupBy' => [['field' => 'FirstAdmissionDate', 'bucket' => 'year']],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'stacked_bars',
        ], TargetDataset::RegisteredVehicles);

        self::assertSame([], $plan->select);
        self::assertEquals(
            [
                new GroupKey('FirstAdmissionDate', Bucket::Year),
                new GroupKey('PrimaryColor', Bucket::None),
            ],
            $plan->groupBy,
        );
    }

    public function test_preserves_select_when_no_aggregates_are_present(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'select' => ['LicensePlate', 'CommercialName'],
            'groupBy' => [],
            'aggregates' => [],
            'limit' => 10,
            'display' => 'table',
        ], TargetDataset::RegisteredVehicles);

        self::assertSame(['LicensePlate', 'CommercialName'], $plan->select);
        self::assertSame([], $plan->groupBy);
    }

    public function test_drops_non_date_fields_from_timeseries_group_by(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'where' => [
                ['field' => 'Brand', 'op' => 'eq', 'value' => 'VOLKSWAGEN'],
                ['field' => 'CommercialName', 'op' => 'contains', 'value' => 'UP'],
                ['field' => 'RegistrationDate', 'op' => 'gte', 'value' => '2025-01-01'],
                ['field' => 'RegistrationDate', 'op' => 'lt', 'value' => '2026-01-01'],
            ],
            'groupBy' => [
                ['field' => 'RegistrationDate', 'bucket' => 'month'],
                ['field' => 'LicensePlate', 'bucket' => 'none'],
            ],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [['expr' => 'RegistrationDate', 'direction' => 'asc']],
            'limit' => 400,
            'display' => 'timeseries',
        ], TargetDataset::RegisteredVehicles);

        self::assertEquals([new GroupKey('RegistrationDate', Bucket::Month)], $plan->groupBy);
    }

    public function test_keeps_date_group_by_on_timeseries_untouched(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'groupBy' => [['field' => 'RegistrationDate', 'bucket' => 'month']],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'timeseries',
        ], TargetDataset::RegisteredVehicles);

        self::assertEquals([new GroupKey('RegistrationDate', Bucket::Month)], $plan->groupBy);
    }

    public function test_throws_when_timeseries_group_by_has_no_date_field(): void
    {
        $factory = $this->factory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A timeseries plan must group by at least one date field');

        $factory->fromArray([
            'groupBy' => [
                ['field' => 'LicensePlate', 'bucket' => 'none'],
                ['field' => 'PrimaryColor', 'bucket' => 'none'],
            ],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'timeseries',
        ], TargetDataset::RegisteredVehicles);
    }

    public function test_preserves_non_date_group_by_for_non_timeseries_display(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'groupBy' => [
                ['field' => 'PrimaryColor', 'bucket' => 'none'],
                ['field' => 'CommercialName', 'bucket' => 'none'],
            ],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'stacked_bars',
        ], TargetDataset::RegisteredVehicles);

        self::assertEquals(
            [
                new GroupKey('PrimaryColor', Bucket::None),
                new GroupKey('CommercialName', Bucket::None),
            ],
            $plan->groupBy,
        );
    }

    public function test_clears_bucket_on_non_date_group_by_field(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'groupBy' => [['field' => 'PrimaryColor', 'bucket' => 'month']],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'bars',
        ], TargetDataset::RegisteredVehicles);

        self::assertEquals([new GroupKey('PrimaryColor', Bucket::None)], $plan->groupBy);
    }

    public function test_rejects_bare_string_group_by_items(): void
    {
        $factory = $this->factory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('groupBy items must be {field, bucket} objects.');

        $factory->fromArray([
            'groupBy' => ['PrimaryColor'],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'bars',
        ], TargetDataset::RegisteredVehicles);
    }

    public function test_clamps_limit_to_the_supported_range(): void
    {
        $factory = $this->factory();

        self::assertSame(1, $this->planWithLimit($factory, 0)->limit);
        self::assertSame(1, $this->planWithLimit($factory, -5)->limit);
        self::assertSame(1000, $this->planWithLimit($factory, 99999)->limit);
        self::assertNull($this->planWithLimit($factory, null)->limit);
    }

    public function test_treats_empty_or_star_aggregate_field_as_null(): void
    {
        $factory = $this->factory();

        $planEmpty = $factory->fromArray([
            'aggregates' => [['fn' => 'count', 'field' => '', 'alias' => 'n']],
            'display' => 'count',
        ], TargetDataset::RegisteredVehicles);
        $planStar = $factory->fromArray([
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'count',
        ], TargetDataset::RegisteredVehicles);

        self::assertNull($planEmpty->aggregates[0]->field);
        self::assertNull($planStar->aggregates[0]->field);
    }

    public function test_parses_a_fuels_dataset_plan_against_the_fuels_field_lookup(): void
    {
        $plan = $this->factory()->fromArray([
            'where' => [['field' => 'NetMaximumPower', 'op' => 'gt', 'value' => '150']],
            'aggregates' => [['fn' => 'count_distinct', 'field' => 'LicensePlate', 'alias' => 'n']],
            'display' => 'count',
            'explanation' => 'kW',
        ], TargetDataset::RegisteredVehicleFuels);

        self::assertSame(TargetDataset::RegisteredVehicleFuels, $plan->dataset);
        self::assertSame('NetMaximumPower', $plan->where[0]->field);
        self::assertSame(AggregateFn::CountDistinct, $plan->aggregates[0]->fn);
    }

    public function test_rejects_a_vehicles_only_field_on_a_fuels_plan(): void
    {
        $factory = $this->factory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field "Brand" for dataset RegisteredVehicleFuels');

        $factory->fromArray([
            'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => 'TOYOTA']],
        ], TargetDataset::RegisteredVehicleFuels);
    }

    public function test_rejects_unknown_field_names(): void
    {
        $factory = $this->factory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field "NotAField" for dataset RegisteredVehicles');

        $factory->fromArray([
            'where' => [['field' => 'NotAField', 'op' => 'eq', 'value' => 'x']],
        ], TargetDataset::RegisteredVehicles);
    }

    public function test_rejects_unknown_enum_values_with_typed_exception(): void
    {
        $factory = $this->factory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value "like" for where.op');

        $factory->fromArray([
            'where' => [['field' => 'Brand', 'op' => 'like', 'value' => 'VW']],
        ], TargetDataset::RegisteredVehicles);
    }

    public function test_rejects_unknown_display_hint(): void
    {
        $factory = $this->factory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value "treemap" for display');

        $factory->fromArray(['display' => 'treemap'], TargetDataset::RegisteredVehicles);
    }

    public function test_rejects_unknown_bucket_value(): void
    {
        $factory = $this->factory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value "decade" for groupBy.bucket');

        $factory->fromArray([
            'groupBy' => [['field' => 'FirstAdmissionDate', 'bucket' => 'decade']],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'display' => 'timeseries',
        ], TargetDataset::RegisteredVehicles);
    }

    public function test_accepts_each_supported_display_hint(): void
    {
        $factory = $this->factory();

        // Include one aggregate so an empty count plan isn't downgraded to unsupported.
        foreach (DisplayHint::cases() as $hint) {
            $plan = $factory->fromArray([
                'display' => $hint->value,
                'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            ], TargetDataset::RegisteredVehicles);
            self::assertSame($hint, $plan->display);
        }
    }

    public function test_downgrades_empty_count_plan_to_unsupported(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'display' => 'count',
            'where' => [],
            'select' => [],
            'groupBy' => [],
            'aggregates' => [],
            'explanation' => 'Counts the result of 30 + 30.',
        ], TargetDataset::RegisteredVehicles);

        self::assertSame(DisplayHint::Unsupported, $plan->display);
        self::assertSame([], $plan->where);
        self::assertSame([], $plan->select);
        self::assertSame([], $plan->groupBy);
        self::assertSame([], $plan->aggregates);
        self::assertSame(1, $plan->limit);
        self::assertSame('Counts the result of 30 + 30.', $plan->explanation);
    }

    public function test_clears_query_state_for_unsupported_display(): void
    {
        $factory = $this->factory();

        // Stray clauses on a refusal plan must be stripped so PlanRunner has nothing to run.
        $plan = $factory->fromArray([
            'display' => 'unsupported',
            'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => 'TOYOTA']],
            'select' => ['LicensePlate'],
            'groupBy' => [['field' => 'PrimaryColor', 'bucket' => 'none']],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
            'limit' => 500,
            'explanation' => 'Not a vehicle question.',
        ], TargetDataset::RegisteredVehicles);

        self::assertSame(DisplayHint::Unsupported, $plan->display);
        self::assertSame([], $plan->where);
        self::assertSame([], $plan->select);
        self::assertSame([], $plan->groupBy);
        self::assertSame([], $plan->aggregates);
        self::assertSame([], $plan->orderBy);
        self::assertSame(1, $plan->limit);
        self::assertSame('Not a vehicle question.', $plan->explanation);
    }

    public function test_rejects_invalid_aggregate_alias_instead_of_silently_rewriting(): void
    {
        $factory = $this->factory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid aggregate alias "count(*)"');

        $factory->fromArray([
            'aggregates' => [
                ['fn' => 'count', 'field' => '*', 'alias' => 'count(*)'],
            ],
            'display' => 'count',
        ], TargetDataset::RegisteredVehicles);
    }

    public function test_tolerates_missing_keys_with_safe_defaults(): void
    {
        $factory = $this->factory();

        $plan = $factory->fromArray([], TargetDataset::RegisteredVehicles);

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
        $factory = $this->factory();

        $plan = $factory->fromArray([
            'where' => 'not-an-array',
            'select' => null,
        ], TargetDataset::RegisteredVehicles);

        self::assertSame([], $plan->where);
        self::assertSame([], $plan->select);
    }

    private function factory(): PlanFactory
    {
        return new PlanFactory(new SchemaRegistry());
    }

    private function planWithLimit(PlanFactory $factory, ?int $limit): Plan
    {
        return $factory->fromArray([
            'limit' => $limit,
            'display' => 'table',
        ], TargetDataset::RegisteredVehicles);
    }
}
