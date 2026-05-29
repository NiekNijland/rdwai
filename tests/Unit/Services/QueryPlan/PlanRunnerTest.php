<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Actions\Rdw\QueryExecutionException;
use App\Services\QueryPlan\AggregateClause;
use App\Services\QueryPlan\AggregateFn;
use App\Services\QueryPlan\Bucket;
use App\Services\QueryPlan\DisplayHint;
use App\Services\QueryPlan\GroupKey;
use App\Services\QueryPlan\OrderClause;
use App\Services\QueryPlan\OrderDirection;
use App\Services\QueryPlan\Plan;
use App\Services\QueryPlan\PlanRunner;
use App\Services\QueryPlan\TargetDataset;
use App\Services\QueryPlan\WhereClause;
use App\Services\QueryPlan\WhereOp;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use InvalidArgumentException;
use NiekNijland\RDW\Datasets\DatasetId;
use NiekNijland\RDW\Http\Configuration as RdwConfiguration;
use NiekNijland\RDW\Http\SocrataClient;
use NiekNijland\RDW\Rdw;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PlanRunnerTest extends TestCase
{
    public function test_row_path_returns_columns_in_select_order_with_pascalcase_keys(): void
    {
        $runner = $this->runnerReturning([
            // Source rows are keyed by Dutch snake_case; the runner renames to PascalCase.
            [
                'kenteken' => '12-AB-345',
                'handelsbenaming' => 'GOLF',
                'merk' => 'VOLKSWAGEN',
                'datum_tenaamstelling_dt' => '2024-01-15',
            ],
        ]);

        $plan = new Plan(
            where: [new WhereClause('Brand', WhereOp::Equals, 'VOLKSWAGEN')],
            // Reverse the source order to prove select-order wins.
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

    public function test_decimal_where_value_is_emitted_as_unquoted_number(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [new WhereClause('CatalogPrice', WhereOp::GreaterThan, '50000')],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
        );

        self::assertSame('catalogusprijs > 50000', $runner->run($plan)->soql['$where']);
    }

    public function test_fuels_dataset_dispatches_to_the_fuels_endpoint(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
            dataset: TargetDataset::RegisteredVehicleFuels,
        );

        $result = $runner->run($plan);

        self::assertStringContainsString('/resource/8ys7-d773.json', $result->url);
    }

    public function test_decimal_compare_on_text_stored_fuel_column_wraps_in_to_number(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [new WhereClause('NetMaximumPower', WhereOp::GreaterThan, '150')],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::CountDistinct, 'LicensePlate', 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
            dataset: TargetDataset::RegisteredVehicleFuels,
        );

        $soql = $runner->run($plan)->soql;
        self::assertSame('to_number(nettomaximumvermogen) > 150', $soql['$where']);
        self::assertStringContainsString('count(distinct kenteken) AS n', $soql['$select']);
    }

    public function test_non_numeric_value_on_text_stored_fuel_comparison_is_rejected_before_reaching_soql(): void
    {
        // Defence against SoQL injection: the to_number(...) path interpolates the value verbatim,
        // so a non-numeric string must never reach it.
        $runner = $this->runnerReturning([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Numeric comparison on field "NetMaximumPower" requires a numeric value, got "150 OR 1=1"');

        $runner->run(new Plan(
            where: [new WhereClause('NetMaximumPower', WhereOp::GreaterThan, '150 OR 1=1')],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
            dataset: TargetDataset::RegisteredVehicleFuels,
        ));
    }

    public function test_in_op_on_text_stored_fuel_column_wraps_each_value_in_to_number(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [new WhereClause('NetMaximumPower', WhereOp::In, '{{q1.NetMaximumPower}}', ['150', '200', '250'])],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
            dataset: TargetDataset::RegisteredVehicleFuels,
        );

        self::assertSame(
            'to_number(nettomaximumvermogen) IN (150, 200, 250)',
            $runner->run($plan)->soql['$where'],
        );
    }

    public function test_in_op_on_text_stored_fuel_column_rejects_a_non_numeric_value(): void
    {
        $runner = $this->runnerReturning([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Numeric comparison on field "NetMaximumPower"');

        $runner->run(new Plan(
            where: [new WhereClause('NetMaximumPower', WhereOp::In, '{{q1.NetMaximumPower}}', ['150', '200; DROP'])],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
            dataset: TargetDataset::RegisteredVehicleFuels,
        ));
    }

    public function test_decimal_compare_on_vehicles_dataset_does_not_wrap_in_to_number(): void
    {
        // Lock-in for the per-column allowlist: CatalogPrice lives on RegisteredVehicles (stored as
        // a real number), so the to_number wrap must never be applied.
        $runner = $this->runnerReturning([]);

        $where = $runner->run(new Plan(
            where: [new WhereClause('CatalogPrice', WhereOp::GreaterThan, '50000')],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
        ))->soql['$where'];

        self::assertSame('catalogusprijs > 50000', $where);
        self::assertStringNotContainsString('to_number', $where);
    }

    public function test_in_op_emits_a_soql_in_clause_with_normalised_plates(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [new WhereClause('LicensePlate', WhereOp::In, '{{q1.LicensePlate}}', ['AA-001-A', 'BB 002 B'])],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::CountDistinct, 'LicensePlate', 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
            dataset: TargetDataset::RegisteredVehicleFuels,
        );

        $where = $runner->run($plan)->soql['$where'];
        // Plates are uppercased and stripped of separators (the existing castValue rule).
        self::assertStringContainsString("kenteken IN ('AA001A', 'BB002B')", $where);
    }

    public function test_cache_key_distinguishes_datasets_for_identical_soql(): void
    {
        $runner = $this->runnerForQueue([
            new Psr7Response(200, ['Content-Type' => 'application/json'], '[]'),
            new Psr7Response(200, ['Content-Type' => 'application/json'], '[]'),
        ]);

        $vehicles = new Plan(
            where: [],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
        );
        $fuels = new Plan(
            where: [],
            select: [],
            groupBy: [],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [],
            limit: null,
            display: DisplayHint::Count,
            explanation: '',
            dataset: TargetDataset::RegisteredVehicleFuels,
        );

        self::assertNotSame($runner->run($vehicles)->url, $runner->run($fuels)->url);
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
            // Bucket expression is aliased back to PascalCase, so rows are already keyed by RegistrationDate.
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

        // $group must list the bucket expression before the plain column, matching plan.groupBy order.
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

        // Bucket alias passes through verbatim; the plain group field is renamed to PascalCase.
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
            // Non-timeseries display so this is independent of PlanFactory's timeseries scrubber.
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

        // Without IS NOT NULL a top-N would lead with rows whose massa_ledig_voertuig is empty.
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

        // No plain-field orderBy → no IS NOT NULL against the aggregate alias.
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

        // Bucketed orderBy uses the date_trunc expression directly — no IS NOT NULL injected.
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
        self::assertStringContainsString("contains(replace(replace(handelsbenaming, ' ', ''), '-', ''), 'GOLF')", $soql['$where']);
        self::assertArrayHasKey('$group', $soql);
        self::assertStringContainsString('eerste_kleur', $soql['$group']);
        self::assertArrayHasKey('$select', $soql);
        self::assertStringContainsString('count(*) AS n', $soql['$select']);
        self::assertArrayHasKey('$order', $soql);
        self::assertStringContainsString('n DESC', $soql['$order']);
        self::assertSame('25', $soql['$limit']);
    }

    public function test_commercial_name_contains_strips_spaces_and_hyphens_on_both_sides(): void
    {
        $runner = $this->runnerReturning([]);

        $plan = new Plan(
            where: [
                new WhereClause('Brand', WhereOp::Equals, 'SUZUKI'),
                new WhereClause('CommercialName', WhereOp::Contains, 'GSX-R 750'),
            ],
            select: [],
            groupBy: [new GroupKey('FirstAdmissionDate', Bucket::Year)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('FirstAdmissionDate', OrderDirection::Asc)],
            limit: null,
            display: DisplayHint::Timeseries,
            explanation: '',
        );

        $soql = $runner->run($plan)->soql;

        // Both term and column drop spaces and hyphens so spelling variants all match.
        self::assertStringContainsString(
            "contains(replace(replace(handelsbenaming, ' ', ''), '-', ''), 'GSXR750')",
            $soql['$where'],
        );
    }

    public function test_unsupported_display_short_circuits_without_hitting_rdw(): void
    {
        // No response queued: any RDW call would make the MockHandler throw.
        $mock = new MockHandler;
        $stack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://opendata.rdw.nl/',
            'handler' => $stack,
        ]);
        $rdw = new Rdw(http: new SocrataClient(new RdwConfiguration, $guzzle));
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

    public function test_transient_timeout_is_retried_and_then_succeeds(): void
    {
        // First attempt times out, the runner retries, the second returns rows.
        $runner = $this->runnerForQueue([
            new ConnectException('cURL error 28: Operation timed out', new Psr7Request('GET', 'test')),
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['eerste_kleur' => 'GEEL', 'n' => '7'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $runner->run($this->colorCountPlan());

        self::assertSame('GEEL', $result->rows[0]['PrimaryColor']);
        self::assertSame('7', $result->rows[0]['n']);
    }

    public function test_transient_timeout_exhausts_retries_and_throws_a_transient_execution_exception(): void
    {
        $runner = $this->runnerForQueue([
            new ConnectException('cURL error 28: Operation timed out', new Psr7Request('GET', 'test')),
            new ConnectException('cURL error 28: Operation timed out', new Psr7Request('GET', 'test')),
        ]);

        try {
            $runner->run($this->colorCountPlan());
            self::fail('Expected a QueryExecutionException once retries are exhausted.');
        } catch (QueryExecutionException $e) {
            self::assertTrue($e->isTransient);
        }
    }

    public function test_rdw_server_error_is_treated_as_transient_and_retried(): void
    {
        // A 5xx is an RDW-side hiccup, not a bad query, so the runner retries it.
        $runner = $this->runnerForQueue([
            new Psr7Response(503, [], 'service unavailable'),
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['eerste_kleur' => 'GEEL', 'n' => '7'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $runner->run($this->colorCountPlan());

        self::assertSame('GEEL', $result->rows[0]['PrimaryColor']);
    }

    public function test_permanent_rejection_is_not_retried(): void
    {
        // Only one 400 is queued, so a retry would hit an empty MockHandler.
        $runner = $this->runnerForQueue([
            new Psr7Response(400, [], 'malformed where clause'),
        ]);

        try {
            $runner->run($this->colorCountPlan());
            self::fail('Expected a QueryExecutionException for the rejected query.');
        } catch (QueryExecutionException $e) {
            self::assertFalse($e->isTransient);
            self::assertSame('malformed where clause', $e->responseBody);
        }
    }

    public function test_identical_soql_is_served_from_cache_without_a_second_rdw_call(): void
    {
        // Only one response is queued, so a clean second result proves the cache served it.
        $stack = HandlerStack::create(new MockHandler([
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['eerste_kleur' => 'WIT', 'n' => '42'],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $guzzle = new GuzzleClient(['base_uri' => 'https://opendata.rdw.nl/', 'handler' => $stack]);
        $rdw = new Rdw(http: new SocrataClient(new RdwConfiguration, $guzzle));

        $runner = new PlanRunner($rdw, cache: new Repository(new ArrayStore), retryBackoffMs: 0);

        $first = $runner->run($this->colorCountPlan());
        $second = $runner->run($this->colorCountPlan());

        self::assertSame('WIT', $first->rows[0]['PrimaryColor']);
        self::assertSame($first->rows, $second->rows);
        // Only rows are cached; SoQL is recomputed each call and still matches.
        self::assertSame($first->soql, $second->soql);
    }

    public function test_cache_key_is_scoped_by_dataset_and_day_and_ttl_is_tiered_by_cost(): void
    {
        // Capture the (key, ttl) handed to the store on each miss.
        $store = new class extends ArrayStore
        {
            /** @var list<array{key: string, ttl: int}> */
            public array $puts = [];

            public function put($key, $value, $seconds)
            {
                $this->puts[] = ['key' => $key, 'ttl' => $seconds];

                return parent::put($key, $value, $seconds);
            }
        };

        $stack = HandlerStack::create(new MockHandler([
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['eerste_kleur' => 'WIT', 'n' => '42'],
            ], JSON_THROW_ON_ERROR)),
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['kenteken' => '12-AB-345'],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $guzzle = new GuzzleClient(['base_uri' => 'https://opendata.rdw.nl/', 'handler' => $stack]);
        $rdw = new Rdw(http: new SocrataClient(new RdwConfiguration, $guzzle));

        $runner = new PlanRunner($rdw, cache: new Repository($store), retryBackoffMs: 0);

        // Aggregate path → long (daily) TTL.
        $runner->run($this->colorCountPlan());

        // Plain row path → short TTL.
        $runner->run(new Plan(
            where: [new WhereClause('LicensePlate', WhereOp::Equals, '12-AB-345')],
            select: ['LicensePlate'],
            groupBy: [],
            aggregates: [],
            orderBy: [],
            limit: 1,
            display: DisplayHint::Table,
            explanation: '',
        ));

        $prefix = sprintf(
            'rdw:%s:%s:',
            DatasetId::RegisteredVehicles->value,
            CarbonImmutable::now('Europe/Amsterdam')->toDateString(),
        );

        self::assertCount(2, $store->puts);
        self::assertStringStartsWith($prefix, $store->puts[0]['key']);
        self::assertStringStartsWith($prefix, $store->puts[1]['key']);
        self::assertNotSame($store->puts[0]['key'], $store->puts[1]['key']);
        self::assertSame(86_400, $store->puts[0]['ttl']);
        self::assertSame(600, $store->puts[1]['ttl']);
    }

    public function test_projection_path_pages_past_socratas_default_when_no_limit_is_set(): void
    {
        // A full page means "there may be more" so the runner fetches the next; a short page ends it.
        $page1 = array_map(
            static fn (int $i): array => ['eerste_kleur' => 'C'.$i, 'n' => (string) $i],
            range(1, 1000),
        );
        $page2 = [
            ['eerste_kleur' => 'WIT', 'n' => '5'],
            ['eerste_kleur' => 'ZWART', 'n' => '3'],
        ];

        $transactions = [];
        $stack = HandlerStack::create(new MockHandler([
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($page1, JSON_THROW_ON_ERROR)),
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($page2, JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($transactions));
        $guzzle = new GuzzleClient(['base_uri' => 'https://opendata.rdw.nl/', 'handler' => $stack]);
        $rdw = new Rdw(http: new SocrataClient(new RdwConfiguration, $guzzle));
        $runner = new PlanRunner($rdw, cache: new Repository(new ArrayStore), retryBackoffMs: 0);

        $result = $runner->run(new Plan(
            where: [],
            select: [],
            groupBy: [new GroupKey('PrimaryColor', Bucket::None)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('PrimaryColor', OrderDirection::Asc)],
            limit: null,
            display: DisplayHint::Bars,
            explanation: '',
        ));

        self::assertCount(1002, $result->rows);
        self::assertSame('WIT', $result->rows[1000]['PrimaryColor']);

        // Two requests (offset 0, then 1000); the user-facing SoQL stays limit-less.
        self::assertCount(2, (array) $transactions);
        $first = urldecode((string) $transactions[0]['request']->getUri());
        $second = urldecode((string) $transactions[1]['request']->getUri());
        self::assertStringContainsString('$limit=1000', $first);
        self::assertStringContainsString('$offset=0', $first);
        self::assertStringContainsString('$offset=1000', $second);
        self::assertArrayNotHasKey('$limit', $result->soql);
        self::assertArrayNotHasKey('$offset', $result->soql);
    }

    public function test_projection_path_does_not_page_when_a_limit_is_set(): void
    {
        // An explicit limit is an intentional bound, so it stays a single request.
        $rows = array_map(
            static fn (int $i): array => ['eerste_kleur' => 'C'.$i, 'n' => (string) $i],
            range(1, 1000),
        );
        $runner = $this->runnerForQueue([
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($rows, JSON_THROW_ON_ERROR)),
        ]);

        $result = $runner->run(new Plan(
            where: [],
            select: [],
            groupBy: [new GroupKey('PrimaryColor', Bucket::None)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('n', OrderDirection::Desc)],
            limit: 1000,
            display: DisplayHint::Bars,
            explanation: '',
        ));

        self::assertCount(1000, $result->rows);
        self::assertSame('1000', $result->soql['$limit']);
    }

    public function test_projection_paging_stops_at_the_max_rows_ceiling(): void
    {
        // With the ceiling at 2500 the runner takes three full pages (3000 rows) and stops.
        $fullPage = static fn (string $tag): string => json_encode(
            array_map(
                static fn (int $i): array => ['eerste_kleur' => $tag.$i, 'n' => (string) $i],
                range(1, 1000),
            ),
            JSON_THROW_ON_ERROR,
        );

        $stack = HandlerStack::create(new MockHandler([
            new Psr7Response(200, ['Content-Type' => 'application/json'], $fullPage('A')),
            new Psr7Response(200, ['Content-Type' => 'application/json'], $fullPage('B')),
            new Psr7Response(200, ['Content-Type' => 'application/json'], $fullPage('C')),
        ]));
        $guzzle = new GuzzleClient(['base_uri' => 'https://opendata.rdw.nl/', 'handler' => $stack]);
        $rdw = new Rdw(http: new SocrataClient(new RdwConfiguration, $guzzle));
        $runner = new PlanRunner(
            $rdw,
            cache: new Repository(new ArrayStore),
            retryBackoffMs: 0,
            maxProjectionRows: 2500,
        );

        $result = $runner->run(new Plan(
            where: [],
            select: [],
            groupBy: [new GroupKey('CommercialName', Bucket::None)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('CommercialName', OrderDirection::Asc)],
            limit: null,
            display: DisplayHint::Bars,
            explanation: '',
        ));

        self::assertCount(3000, $result->rows);
    }

    private function colorCountPlan(): Plan
    {
        return new Plan(
            where: [],
            select: [],
            groupBy: [new GroupKey('PrimaryColor', Bucket::None)],
            aggregates: [new AggregateClause(AggregateFn::Count, null, 'n')],
            orderBy: [new OrderClause('n', OrderDirection::Desc)],
            limit: 25,
            display: DisplayHint::Bars,
            explanation: '',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function runnerReturning(array $rows): PlanRunner
    {
        return $this->runnerForQueue([
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($rows, JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * @param  list<Psr7Response|Throwable>  $queue
     */
    private function runnerForQueue(array $queue): PlanRunner
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://opendata.rdw.nl/',
            'handler' => $stack,
        ]);

        $socrata = new SocrataClient(new RdwConfiguration, $guzzle);
        $rdw = new Rdw(http: $socrata);

        return new PlanRunner($rdw, maxAttempts: 2, retryBackoffMs: 0);
    }
}
