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
use App\Services\QueryPlan\PlanRunner;
use App\Services\QueryPlan\WhereClause;
use App\Services\QueryPlan\WhereOp;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as Psr7Response;
use InvalidArgumentException;
use NiekNijland\RDW\Http\Configuration as RdwConfiguration;
use NiekNijland\RDW\Http\SocrataClient;
use NiekNijland\RDW\Rdw;
use PHPUnit\Framework\TestCase;

final class PlanRunnerTest extends TestCase
{
    public function test_row_path_returns_columns_in_select_order_with_pascalcase_keys(): void
    {
        $runner = $this->runnerReturning([
            // The HTTP layer returns rows keyed by Dutch snake_case. The
            // package hydrates them; the runner then renames to PascalCase.
            [
                'kenteken' => '12-AB-345',
                'handelsbenaming' => 'GOLF',
                'merk' => 'VOLKSWAGEN',
                'datum_tenaamstelling_dt' => '2024-01-15',
            ],
        ]);

        $plan = new Plan(
            where: [new WhereClause('Brand', WhereOp::Equals, 'VOLKSWAGEN')],
            // Deliberately reverse the source order to prove select-order wins.
            select: ['CommercialName', 'LicensePlate', 'RegistrationDate'],
            groupBy: [],
            aggregates: [],
            orderBy: [],
            limit: 5,
            display: DisplayHint::Table,
            explanation: '',
        );

        $result = $runner->run($plan);

        self::assertCount(1, $result->rows);
        self::assertSame(
            ['CommercialName', 'LicensePlate', 'RegistrationDate'],
            array_keys($result->rows[0]),
        );
        self::assertSame('GOLF', $result->rows[0]['CommercialName']);
        self::assertSame('12-AB-345', $result->rows[0]['LicensePlate']);
        // Dates are normalised to YYYY-MM-DD strings.
        self::assertSame('2024-01-15', $result->rows[0]['RegistrationDate']);
    }

    public function test_aggregate_path_normalises_dutch_keys_to_pascalcase(): void
    {
        $runner = $this->runnerReturning([
            ['eerste_kleur' => 'WIT', 'n' => '42'],
            ['eerste_kleur' => 'ZWART', 'n' => '17'],
        ]);

        $plan = new Plan(
            where: [],
            select: [],
            groupBy: [new GroupKey('PrimaryColor', Bucket::None)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('n', OrderDirection::Desc)],
            limit: 25,
            display: DisplayHint::Bars,
            explanation: '',
        );

        $result = $runner->run($plan);

        self::assertSame(['PrimaryColor', 'n'], array_keys($result->rows[0]));
        self::assertSame('WIT', $result->rows[0]['PrimaryColor']);
        self::assertSame('42', $result->rows[0]['n']);
    }

    public function test_orderby_accepts_field_names_and_aggregate_aliases_but_rejects_others(): void
    {
        $runner = $this->runnerReturning([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('orderBy expression "totally_random_alias"');

        $runner->run(new Plan(
            where: [],
            select: [],
            groupBy: [new GroupKey('PrimaryColor', Bucket::None)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('totally_random_alias', OrderDirection::Desc)],
            limit: null,
            display: DisplayHint::Bars,
            explanation: '',
        ));
    }

    public function test_sum_avg_min_max_require_a_field(): void
    {
        $runner = $this->runnerReturning([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Aggregate sum requires a field.');

        $runner->run(new Plan(
            where: [],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::Sum, null, 'total')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
        ));
    }

    public function test_month_bucket_emits_date_trunc_ym_and_returns_pascalcase_keys(): void
    {
        $runner = $this->runnerReturning([
            // Socrata returns the bucket expression aliased back to the field's
            // PascalCase enum case, so the projection row should already be
            // keyed by `RegistrationDate` (no Dutch rdwKey to rename).
            ['RegistrationDate' => '2025-01-01T00:00:00.000', 'n' => '12'],
            ['RegistrationDate' => '2025-02-01T00:00:00.000', 'n' => '8'],
        ]);

        $plan = new Plan(
            where: [
                new WhereClause('Brand', WhereOp::Equals, 'VOLKSWAGEN'),
                new WhereClause('CommercialName', WhereOp::Contains, 'UP'),
                new WhereClause('RegistrationDate', WhereOp::GreaterThanOrEqual, '2025-01-01'),
                new WhereClause('RegistrationDate', WhereOp::LessThan, '2026-01-01'),
            ],
            select: [],
            groupBy: [new GroupKey('RegistrationDate', Bucket::Month)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('RegistrationDate', OrderDirection::Asc)],
            limit: 400,
            display: DisplayHint::Timeseries,
            explanation: '',
        );

        $result = $runner->run($plan);

        self::assertArrayHasKey('$group', $result->soql);
        self::assertStringContainsString('date_trunc_ym(datum_tenaamstelling_dt)', $result->soql['$group']);
        self::assertStringContainsString(
            'date_trunc_ym(datum_tenaamstelling_dt) AS RegistrationDate',
            $result->soql['$select'],
        );
        self::assertStringContainsString(
            'date_trunc_ym(datum_tenaamstelling_dt) ASC',
            $result->soql['$order'],
        );
        self::assertSame(['RegistrationDate', 'n'], array_keys($result->rows[0]));
    }

    public function test_stacked_bars_mixes_bucketed_and_plain_group_keys_in_plan_order(): void
    {
        $runner = $this->runnerReturning([
            ['FirstAdmissionDate' => '2020-01-01T00:00:00.000', 'eerste_kleur' => 'WIT', 'n' => '42'],
            ['FirstAdmissionDate' => '2021-01-01T00:00:00.000', 'eerste_kleur' => 'ZWART', 'n' => '17'],
        ]);

        $plan = new Plan(
            where: [new WhereClause('Brand', WhereOp::Equals, 'VOLKSWAGEN')],
            select: [],
            groupBy: [
                new GroupKey('FirstAdmissionDate', Bucket::Year),
                new GroupKey('PrimaryColor', Bucket::None),
            ],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('FirstAdmissionDate', OrderDirection::Asc)],
            limit: 200,
            display: DisplayHint::StackedBars,
            explanation: '',
        );

        $result = $runner->run($plan);

        // $group must list the bucket expression *before* the plain column to
        // match plan.groupBy ordering — the frontend relies on that ordering
        // for the outer-vs-inner axis assignment in stacked_bars.
        self::assertSame(
            'date_trunc_y(datum_eerste_toelating_dt), eerste_kleur',
            $result->soql['$group'],
        );
        self::assertStringContainsString(
            'date_trunc_y(datum_eerste_toelating_dt) AS FirstAdmissionDate',
            $result->soql['$select'],
        );
        self::assertStringContainsString('eerste_kleur', $result->soql['$select']);
        self::assertStringContainsString(
            'date_trunc_y(datum_eerste_toelating_dt) ASC',
            $result->soql['$order'],
        );

        // Bucket alias passes through verbatim; the Dutch rdwKey for the plain
        // group field is renamed to PascalCase.
        self::assertSame(
            ['FirstAdmissionDate', 'PrimaryColor', 'n'],
            array_keys($result->rows[0]),
        );
        self::assertSame('WIT', $result->rows[0]['PrimaryColor']);
    }

    public function test_orderby_by_bucketed_alias_emits_date_trunc_expression(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [],
            select: [],
            // Use a non-timeseries display to confirm the orderBy path works
            // independent of the timeseries scrubber in PlanFactory.
            groupBy: [new GroupKey('FirstAdmissionDate', Bucket::Year)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('FirstAdmissionDate', OrderDirection::Desc)],
            limit: 50,
            display: DisplayHint::Histogram,
            explanation: '',
        );

        $soql = $runner->run($plan)->soql;

        self::assertStringContainsString(
            'date_trunc_y(datum_eerste_toelating_dt) DESC',
            $soql['$order'],
        );
    }

    public function test_plain_field_orderby_injects_is_not_null_so_top_n_skips_empty_rows(): void
    {
        $runner = $this->runnerReturning([]);

        // "Top 5 zwaarste voertuigen op kenteken" — without IS NOT NULL the
        // result would lead with rows whose massa_ledig_voertuig is empty.
        $plan = new Plan(
            where: [],
            select: ['LicensePlate', 'EmptyMass'],
            groupBy: [],
            aggregates: [],
            orderBy: [
                new OrderClause('EmptyMass', OrderDirection::Desc),
                // Repeating the same field must not add a second IS NOT NULL.
                new OrderClause('EmptyMass', OrderDirection::Asc),
            ],
            limit: 5,
            display: DisplayHint::Table,
            explanation: '',
        );

        $soql = $runner->run($plan)->soql;

        self::assertArrayHasKey('$where', $soql);
        self::assertStringContainsString('massa_ledig_voertuig IS NOT NULL', $soql['$where']);
        self::assertSame(
            1,
            substr_count($soql['$where'], 'massa_ledig_voertuig IS NOT NULL'),
            'IS NOT NULL should be injected once per field, not per orderBy clause.',
        );
    }

    public function test_aggregate_alias_orderby_does_not_inject_is_not_null(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [],
            select: [],
            groupBy: [new GroupKey('PrimaryColor', Bucket::None)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('n', OrderDirection::Desc)],
            limit: 25,
            display: DisplayHint::Bars,
            explanation: '',
        );

        $soql = $runner->run($plan)->soql;

        // No orderBy on a plain field → nothing to guard against. The $where
        // param should be absent (no other clauses) rather than carry a
        // spurious "n IS NOT NULL" against an aggregate alias.
        self::assertArrayNotHasKey('$where', $soql);
    }

    public function test_bucketed_orderby_does_not_inject_is_not_null(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [],
            select: [],
            groupBy: [new GroupKey('FirstAdmissionDate', Bucket::Year)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('FirstAdmissionDate', OrderDirection::Asc)],
            limit: 50,
            display: DisplayHint::Timeseries,
            explanation: '',
        );

        $soql = $runner->run($plan)->soql;

        // Bucketed orderBy goes through orderByRaw against the date_trunc
        // expression — no IS NOT NULL needed (and we'd have to filter the
        // underlying field, which is a separate decision).
        self::assertArrayNotHasKey('$where', $soql);
    }

    public function test_soql_params_reflect_where_select_groupby_orderby_and_limit(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [
                new WhereClause('Brand', WhereOp::Equals, 'VOLKSWAGEN'),
                new WhereClause('CommercialName', WhereOp::Contains, 'GOLF'),
            ],
            select: [],
            groupBy: [new GroupKey('PrimaryColor', Bucket::None)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('n', OrderDirection::Desc)],
            limit: 25,
            display: DisplayHint::Bars,
            explanation: '',
        );

        $soql = $runner->run($plan)->soql;

        self::assertArrayHasKey('$where', $soql);
        self::assertStringContainsString("merk = 'VOLKSWAGEN'", $soql['$where']);
        self::assertStringContainsString("contains(handelsbenaming, 'GOLF')", $soql['$where']);
        self::assertArrayHasKey('$group', $soql);
        self::assertStringContainsString('eerste_kleur', $soql['$group']);
        self::assertArrayHasKey('$select', $soql);
        self::assertStringContainsString('count(*) AS n', $soql['$select']);
        self::assertArrayHasKey('$order', $soql);
        self::assertStringContainsString('n DESC', $soql['$order']);
        self::assertSame('25', $soql['$limit']);
    }

    public function test_unsupported_display_short_circuits_without_hitting_rdw(): void
    {
        // No mock response is queued — if the runner tries to call RDW the
        // Guzzle MockHandler will throw "queue is empty", failing the test.
        $mock = new MockHandler();
        $stack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://opendata.rdw.nl/',
            'handler' => $stack,
        ]);
        $rdw = new Rdw(http: new SocrataClient(new RdwConfiguration(), $guzzle));
        $runner = new PlanRunner($rdw);

        $result = $runner->run(new Plan(
            where: [],
            select: [],
            groupBy: [],
            aggregates: [],
            orderBy: [],
            limit: 1,
            display: DisplayHint::Unsupported,
            explanation: 'Off-topic.',
        ));

        self::assertSame([], $result->rows);
        self::assertSame([], $result->soql);
        self::assertSame('', $result->url);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function runnerReturning(array $rows): PlanRunner
    {
        $mock = new MockHandler([
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($rows, JSON_THROW_ON_ERROR)),
        ]);

        $stack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://opendata.rdw.nl/',
            'handler' => $stack,
        ]);

        $socrata = new SocrataClient(new RdwConfiguration(), $guzzle);
        $rdw = new Rdw(http: $socrata);

        return new PlanRunner($rdw);
    }
}
