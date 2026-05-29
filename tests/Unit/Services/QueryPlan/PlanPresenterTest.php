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
use App\Services\QueryPlan\WhereClause;
use App\Services\QueryPlan\WhereOp;
use PHPUnit\Framework\TestCase;

final class PlanPresenterTest extends TestCase
{
    public function test_to_array_flattens_value_objects_into_a_serialisable_shape(): void
    {
        $plan = new Plan(
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

    public function test_normalise_persisted_passes_new_shape_through_unchanged(): void
    {
        $current = [
            'dataset' => 'RegisteredVehicles',
            'groupBy' => [
                ['field' => 'PrimaryColor', 'bucket' => 'none'],
                ['field' => 'RegistrationDate', 'bucket' => 'month'],
            ],
        ];

        self::assertSame($current, PlanPresenter::normalisePersisted($current));
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
