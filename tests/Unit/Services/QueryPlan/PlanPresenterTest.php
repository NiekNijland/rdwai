<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\AggregateClause;
use App\Services\QueryPlan\AggregateFn;
use App\Services\QueryPlan\DisplayHint;
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
            groupBy: ['PrimaryColor'],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('n', OrderDirection::Desc)],
            limit: 25,
            display: DisplayHint::Bars,
            explanation: 'colors of VWs',
        );

        $array = PlanPresenter::toArray($plan);

        self::assertSame([
            'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => 'VW']],
            'select' => ['Brand'],
            'groupBy' => ['PrimaryColor'],
            'aggregates' => [['fn' => 'count', 'field' => null, 'alias' => 'n']],
            'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
            'limit' => 25,
            'display' => 'bars',
            'explanation' => 'colors of VWs',
        ], $array);
    }
}
