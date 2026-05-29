<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\AggregateClause;
use App\Services\QueryPlan\AggregateFn;
use App\Services\QueryPlan\Bucket;
use App\Services\QueryPlan\DisplayHint;
use App\Services\QueryPlan\GroupKey;
use App\Services\QueryPlan\OrderClause;
use App\Services\QueryPlan\OrderDirection;
use App\Services\QueryPlan\Plan;
use App\Services\QueryPlan\PlanPresenter;
use App\Services\QueryPlan\TargetDataset;
use App\Services\QueryPlan\WhereClause;
use App\Services\QueryPlan\WhereOp;
use PHPUnit\Framework\TestCase;

final class PlanPresenterTest extends TestCase
{
    public function test_to_array_flattens_value_objects_into_a_serialisable_shape(): void
    {
        $plan = new Plan(
            dataset: TargetDataset::RegisteredVehicles,
            where: [new WhereClause('Brand', WhereOp::Equals, 'VW')],
            select: ['Brand'],
            groupBy: [
                new GroupKey('PrimaryColor', Bucket::None),
                new GroupKey('RegistrationDate', Bucket::Month),
            ],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('n', OrderDirection::Desc)],
            limit: 25,
            display: DisplayHint::Bars,
            explanation: 'colors of VWs',
        );

        $array = PlanPresenter::toArray($plan);

        self::assertSame([
            'dataset' => 'RegisteredVehicles',
            'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => 'VW']],
            'select' => ['Brand'],
            'groupBy' => [
                ['field' => 'PrimaryColor', 'bucket' => 'none'],
                ['field' => 'RegistrationDate', 'bucket' => 'month'],
            ],
            'aggregates' => [['fn' => 'count', 'field' => null, 'alias' => 'n']],
            'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
            'limit' => 25,
            'display' => 'bars',
            'explanation' => 'colors of VWs',
        ], $array);
    }

    public function test_normalise_persisted_upgrades_legacy_string_group_by_items(): void
    {
        // Legacy QueryRun.plan: groupBy was a bare list of field names.
        $legacy = [
            'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => 'VW']],
            'select' => [],
            'groupBy' => ['PrimaryColor', 'CommercialName'],
            'aggregates' => [['fn' => 'count', 'field' => null, 'alias' => 'n']],
            'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
            'limit' => 25,
            'display' => 'bars',
            'explanation' => '',
        ];

        $normalised = PlanPresenter::normalisePersisted($legacy);

        self::assertSame(
            [
                ['field' => 'PrimaryColor', 'bucket' => 'none'],
                ['field' => 'CommercialName', 'bucket' => 'none'],
            ],
            $normalised['groupBy'],
        );
        // Everything else is untouched.
        self::assertSame($legacy['where'], $normalised['where']);
        self::assertSame($legacy['aggregates'], $normalised['aggregates']);
        self::assertSame($legacy['display'], $normalised['display']);
    }

    public function test_normalise_persisted_passes_a_full_current_shape_through_unchanged(): void
    {
        $current = [
            'dataset' => 'RegisteredVehicleFuels',
            'where' => [['field' => 'NetMaximumPower', 'op' => 'gt', 'value' => '150']],
            'select' => [],
            'groupBy' => [
                ['field' => 'PrimaryColor', 'bucket' => 'none'],
                ['field' => 'RegistrationDate', 'bucket' => 'month'],
            ],
            'aggregates' => [['fn' => 'count_distinct', 'field' => 'LicensePlate', 'alias' => 'n']],
            'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
            'limit' => null,
            'display' => 'count',
            'explanation' => 'kW',
        ];

        self::assertSame($current, PlanPresenter::normalisePersisted($current));
    }

    public function test_normalise_persisted_steps_defaults_dataset_per_step_for_legacy_query_runs(): void
    {
        // Pre-change QueryRun rows store `steps[].plan` without a `dataset`. The frontend Plan type
        // now declares `dataset` as required, so the controller must backfill before serialising.
        $legacySteps = [
            [
                'id' => 'q1',
                'plan' => [
                    'where' => [],
                    'select' => ['Brand'],
                    'groupBy' => [],
                    'aggregates' => [],
                    'orderBy' => [],
                    'limit' => 1,
                    'display' => 'record',
                    'explanation' => '',
                ],
                'soql' => [],
                'url' => '',
                'rowCount' => 1,
            ],
            [
                'id' => 'q2',
                'plan' => [
                    'where' => [],
                    'select' => [],
                    'groupBy' => ['PrimaryColor'],
                    'aggregates' => [['fn' => 'count', 'field' => null, 'alias' => 'n']],
                    'orderBy' => [],
                    'limit' => null,
                    'display' => 'bars',
                    'explanation' => '',
                ],
                'soql' => [],
                'url' => '',
                'rowCount' => 2,
            ],
        ];

        $normalised = PlanPresenter::normalisePersistedSteps($legacySteps);

        self::assertCount(2, $normalised);
        self::assertSame('RegisteredVehicles', $normalised[0]['plan']['dataset']);
        self::assertSame('RegisteredVehicles', $normalised[1]['plan']['dataset']);
        // `groupBy` legacy-string upgrade also flows through.
        self::assertSame(
            [['field' => 'PrimaryColor', 'bucket' => 'none']],
            $normalised[1]['plan']['groupBy'],
        );
    }

    public function test_normalise_persisted_steps_returns_an_empty_list_for_null_input(): void
    {
        // `QueryRun::steps` is nullable when the run pre-dates the multi-step feature.
        self::assertSame([], PlanPresenter::normalisePersistedSteps(null));
    }

    public function test_normalise_persisted_defaults_dataset_for_pre_change_documents(): void
    {
        $legacy = [
            'where' => [],
            'select' => [],
            'groupBy' => [['field' => 'PrimaryColor', 'bucket' => 'none']],
            'aggregates' => [],
            'orderBy' => [],
            'limit' => null,
            'display' => 'bars',
            'explanation' => '',
        ];

        $normalised = PlanPresenter::normalisePersisted($legacy);

        self::assertSame('RegisteredVehicles', $normalised['dataset']);
    }
}
