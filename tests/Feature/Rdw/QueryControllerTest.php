<?php

declare(strict_types=1);

namespace Tests\Feature\Rdw;

use App\Actions\Rdw\RunNaturalLanguageQuery;
use App\Ai\Agents\QueryProgramAgent;
use App\Services\QueryPlan\PlanRunner;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Mockery;
use NiekNijland\RDW\Http\Configuration as RdwConfiguration;
use NiekNijland\RDW\Http\SocrataClient;
use NiekNijland\RDW\Rdw;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class QueryControllerTest extends TestCase
{
    public function test_index_renders_inertia_query_page(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('query/index'));
    }

    public function test_run_returns_plan_rows_and_soql_for_a_well_formed_response(): void
    {
        $this->fakeQueryPlan(
            [
                'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => 'VOLKSWAGEN']],
                'select' => [],
                'groupBy' => [['field' => 'PrimaryColor', 'bucket' => 'none']],
                'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
                'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
                'limit' => 25,
                'display' => 'bars',
                'explanation' => 'Colors of VWs',
            ],
            usage: new Usage(promptTokens: 800, completionTokens: 120),
            model: 'gpt-4.1-nano',
        );

        $this->fakeRdwWithRows([
            ['eerste_kleur' => 'WIT', 'n' => '42'],
            ['eerste_kleur' => 'ZWART', 'n' => '17'],
        ]);

        config()->set('rdwai.model_prices', [
            'gpt-4.1-nano' => ['input' => 0.10, 'cached_input' => 0.025, 'output' => 0.40],
        ]);

        $response = $this->postJson(route('rdw.query.run'), ['prompt' => 'Count colors']);

        $response->assertOk()
            ->assertJsonPath('plan.display', 'bars')
            ->assertJsonPath('displayHint', 'bars')
            ->assertJsonPath('plan.aggregates.0.alias', 'n')
            ->assertJsonPath('rows.0.PrimaryColor', 'WIT')
            ->assertJsonPath('rows.0.n', '42')
            ->assertJsonPath('model', 'gpt-4.1-nano')
            ->assertJsonPath('tokens.prompt', 800)
            ->assertJsonPath('tokens.completion', 120)
            ->assertJsonPath('tokens.cacheRead', 0)
            ->assertJsonPath('tokens.thought', 0)
            ->assertJsonStructure(['plan', 'soql', 'rows', 'displayHint', 'model', 'tokens', 'estimatedCost']);

        self::assertNotNull($response->json('estimatedCost'));
    }

    public function test_run_resolves_a_dependent_step_lookup_end_to_end(): void
    {
        $this->fakeProgram([
            'queries' => [
                [
                    'id' => 'q1',
                    'dataset' => 'RegisteredVehicles',
                    'where' => [['field' => 'LicensePlate', 'op' => 'eq', 'value' => '1-ZTZ-08']],
                    'select' => ['Brand', 'CommercialName'],
                    'groupBy' => [], 'aggregates' => [], 'orderBy' => [],
                    'limit' => 1, 'display' => 'record', 'explanation' => 'The vehicle.',
                ],
                [
                    'id' => 'q2',
                    'dataset' => 'RegisteredVehicles',
                    'where' => [
                        ['field' => 'Brand', 'op' => 'eq', 'value' => '{{q1.Brand}}'],
                        ['field' => 'CommercialName', 'op' => 'eq', 'value' => '{{q1.CommercialName}}'],
                    ],
                    'select' => [], 'groupBy' => [],
                    'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
                    'orderBy' => [], 'limit' => 1, 'display' => 'count', 'explanation' => 'Same model.',
                ],
            ],
            'presentation' => [
                'resultRef' => 'q2', 'display' => 'count', 'derive' => null,
                'explanation' => 'How many of the same make and model are registered.',
            ],
        ]);

        $this->fakeRdwWithResponses(
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['kenteken' => '1ZTZ08', 'merk' => 'VOLKSWAGEN', 'handelsbenaming' => 'UP'],
            ], JSON_THROW_ON_ERROR)),
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['n' => '58408'],
            ], JSON_THROW_ON_ERROR)),
        );

        $response = $this->postJson(route('rdw.query.run'), ['prompt' => 'How many of my model? 1-ZTZ-08']);

        $response->assertOk()
            ->assertJsonPath('rows.0.n', '58408')
            ->assertJsonPath('displayHint', 'count')
            ->assertJsonPath('presentation.resultRef', 'q2')
            ->assertJsonPath('steps.0.id', 'q1')
            ->assertJsonPath('steps.1.id', 'q2')
            ->assertJsonPath('steps.1.plan.where.0.value', 'VOLKSWAGEN')
            ->assertJsonPath('steps.1.plan.where.1.value', 'UP');

        self::assertCount(2, $response->json('steps'));
        QueryProgramAgent::assertPrompted(fn (): bool => true);
    }

    public function test_run_degrades_to_a_localized_unsupported_answer_when_a_reference_cannot_resolve(): void
    {
        $program = [
            'queries' => [
                [
                    'id' => 'q1',
                    'dataset' => 'RegisteredVehicles',
                    'where' => [['field' => 'LicensePlate', 'op' => 'eq', 'value' => 'XX-XX-XX']],
                    'select' => ['Brand'],
                    'groupBy' => [], 'aggregates' => [], 'orderBy' => [],
                    'limit' => 1, 'display' => 'record', 'explanation' => 'The vehicle.',
                ],
                [
                    'id' => 'q2',
                    'dataset' => 'RegisteredVehicles',
                    'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => '{{q1.Brand}}']],
                    'select' => [], 'groupBy' => [],
                    'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
                    'orderBy' => [], 'limit' => 1, 'display' => 'count', 'explanation' => 'Same model.',
                ],
            ],
            'presentation' => [
                'resultRef' => 'q2', 'display' => 'count', 'derive' => null,
                'explanation' => 'How many of the same make and model are registered.',
            ],
        ];

        // q1 returns no rows, so {{q1.Brand}} cannot resolve and degrades gracefully.
        $emptyRows = fn (): Psr7Response => new Psr7Response(
            200, ['Content-Type' => 'application/json'], json_encode([], JSON_THROW_ON_ERROR),
        );

        $this->fakeProgram($program);
        $this->fakeRdwWithResponse($emptyRows());

        $this->postJson(route('rdw.query.run'), ['prompt' => 'How many of my model? XX-XX-XX'])
            ->assertOk()
            ->assertJsonPath('displayHint', 'unsupported')
            ->assertJsonPath('plan.explanation', 'This question could not be answered with the available data.');

        $this->fakeProgram($program);
        $this->fakeRdwWithResponse($emptyRows());

        $this->postJson(route('rdw.query.run'), ['prompt' => 'Hoeveel van mijn model? XX-XX-XX'], ['Accept-Language' => 'nl'])
            ->assertOk()
            ->assertJsonPath('displayHint', 'unsupported')
            ->assertJsonPath('plan.explanation', 'Deze vraag kon niet worden beantwoord met de beschikbare gegevens.');
    }

    public function test_run_computes_a_group_share_figure_from_one_grouped_query(): void
    {
        $this->fakeProgram([
            'queries' => [[
                'id' => 'q1',
                'dataset' => 'RegisteredVehicles',
                'where' => [],
                'select' => [],
                'groupBy' => [['field' => 'PrimaryColor', 'bucket' => 'none']],
                'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
                'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
                'limit' => 25, 'display' => 'bars', 'explanation' => 'Colours.',
            ]],
            'presentation' => [
                'resultRef' => 'derived',
                'display' => 'count',
                'derive' => [
                    'op' => 'groupShare', 'source' => 'q1',
                    'selectorColumn' => 'PrimaryColor', 'selectorValue' => 'GEEL',
                ],
                'explanation' => 'The share of yellow cars.',
            ],
        ]);

        $this->fakeRdwWithRows([
            ['eerste_kleur' => 'GEEL', 'n' => '320'],
            ['eerste_kleur' => 'WIT', 'n' => '4680'],
            ['eerste_kleur' => 'ZWART', 'n' => '5000'],
        ]);

        $response = $this->postJson(route('rdw.query.run'), ['prompt' => 'What percentage of cars are yellow?']);

        $response->assertOk()
            ->assertJsonPath('presentation.resultRef', 'derived')
            ->assertJsonPath('presentation.derived.op', 'groupShare')
            ->assertJsonPath('displayHint', 'bars');

        self::assertEqualsWithDelta(320.0, $response->json('presentation.derived.numerator'), 0.001);
        self::assertEqualsWithDelta(10_000.0, $response->json('presentation.derived.denominator'), 0.001);
        self::assertEqualsWithDelta(0.032, $response->json('presentation.derived.value'), 1e-6);
    }

    public function test_run_computes_a_two_scalar_ratio_across_two_queries(): void
    {
        $this->fakeProgram([
            'queries' => [
                [
                    'id' => 'q1',
                    'dataset' => 'RegisteredVehicles',
                    'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => 'VOLKSWAGEN']],
                    'select' => [], 'groupBy' => [],
                    'aggregates' => [['fn' => 'avg', 'field' => 'EmptyMass', 'alias' => 'avg_mass']],
                    'orderBy' => [], 'limit' => 1, 'display' => 'count', 'explanation' => 'VW average mass.',
                ],
                [
                    'id' => 'q2',
                    'dataset' => 'RegisteredVehicles',
                    'where' => [], 'select' => [], 'groupBy' => [],
                    'aggregates' => [['fn' => 'avg', 'field' => 'EmptyMass', 'alias' => 'avg_mass']],
                    'orderBy' => [], 'limit' => 1, 'display' => 'count', 'explanation' => 'Overall average mass.',
                ],
            ],
            'presentation' => [
                'resultRef' => 'derived',
                'display' => 'count',
                'derive' => ['op' => 'ratio', 'numerator' => 'q1', 'denominator' => 'q2'],
                'explanation' => 'How VW mass compares to the fleet.',
            ],
        ]);

        $this->fakeRdwWithResponses(
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['avg_mass' => '1500'],
            ], JSON_THROW_ON_ERROR)),
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['avg_mass' => '1200'],
            ], JSON_THROW_ON_ERROR)),
        );

        $response = $this->postJson(route('rdw.query.run'), ['prompt' => 'Average mass of VWs vs all cars?']);

        $response->assertOk()
            ->assertJsonPath('presentation.resultRef', 'derived')
            ->assertJsonPath('presentation.derived.op', 'ratio')
            ->assertJsonPath('displayHint', 'count');

        self::assertCount(2, $response->json('steps'));
        self::assertEqualsWithDelta(1500.0, $response->json('presentation.derived.numerator'), 0.001);
        self::assertEqualsWithDelta(1200.0, $response->json('presentation.derived.denominator'), 0.001);
        self::assertEqualsWithDelta(1.25, $response->json('presentation.derived.value'), 1e-6);
    }

    public function test_run_validates_prompt_length(): void
    {
        $this->postJson(route('rdw.query.run'), ['prompt' => 'no'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prompt');

        $this->postJson(route('rdw.query.run'), ['prompt' => str_repeat('x', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prompt');

        $this->postJson(route('rdw.query.run'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prompt');
    }

    public function test_run_returns_422_with_malformed_message_when_plan_validation_fails(): void
    {
        $this->fakeQueryPlan([
            'where' => [['field' => 'NotAField', 'op' => 'eq', 'value' => 'x']],
            'select' => [],
            'groupBy' => [],
            'aggregates' => [],
            'orderBy' => [],
            'limit' => 10,
            'display' => 'table',
            'explanation' => '',
        ]);

        $response = $this->postJson(route('rdw.query.run'), ['prompt' => 'test prompt']);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'The generated query was malformed. Try rephrasing your question.');
    }

    public function test_run_returns_422_with_rejected_message_and_debug_payload_when_rdw_rejects_the_query(): void
    {
        $this->fakeQueryPlan([
            'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => 'VOLKSWAGEN']],
            'select' => [],
            'groupBy' => [],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [],
            'limit' => 10,
            'display' => 'count',
            'explanation' => '',
        ]);

        $this->fakeRdwWithResponse(new Psr7Response(400, [], 'malformed where clause'));

        $response = $this->postJson(route('rdw.query.run'), ['prompt' => 'test prompt']);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'The generated query was rejected. Try rephrasing your question.')
            ->assertJsonPath('responseBody', 'malformed where clause')
            ->assertJsonPath('plan.where.0.field', 'Brand')
            ->assertJsonStructure(['plan', 'soql', 'url', 'responseBody']);
    }

    public function test_run_returns_504_with_timeout_message_and_debug_payload_when_rdw_times_out(): void
    {
        $this->fakeQueryPlan([
            'where' => [],
            'select' => [],
            'groupBy' => [['field' => 'PrimaryColor', 'bucket' => 'none']],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [['expr' => 'n', 'direction' => 'desc']],
            'limit' => 25,
            'display' => 'bars',
            'explanation' => 'Color distribution',
        ]);

        // Both attempts time out; the controller maps the exhausted retry to a 504.
        $this->fakeRdwWithQueue([
            new ConnectException('cURL error 28: Operation timed out', new Psr7Request('GET', 'test')),
            new ConnectException('cURL error 28: Operation timed out', new Psr7Request('GET', 'test')),
        ]);

        $response = $this->postJson(route('rdw.query.run'), ['prompt' => 'How many cars are yellow?']);

        $response->assertStatus(504)
            ->assertJsonPath('error', 'RDW took too long to answer this query. Please try again in a moment.')
            ->assertJsonPath('responseBody', null)
            ->assertJsonStructure(['plan', 'soql', 'url']);
    }

    public function test_run_returns_429_when_rdw_rate_limits(): void
    {
        $this->fakeQueryPlan([
            'where' => [],
            'select' => [],
            'groupBy' => [],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [],
            'limit' => 1,
            'display' => 'count',
            'explanation' => '',
        ]);

        $this->fakeRdwWithResponse(new Psr7Response(429, ['Retry-After' => '17'], 'slow down'));

        $response = $this->postJson(route('rdw.query.run'), ['prompt' => 'test prompt']);

        $response->assertStatus(429)
            ->assertJsonPath('error', 'RDW rate limit reached. Try again in 17s.');
    }

    public function test_run_returns_500_with_sanitised_message_for_unexpected_errors(): void
    {
        $mock = Mockery::mock(RunNaturalLanguageQuery::class);
        // @phpstan-ignore method.notFound (Mockery fluent API is not statically resolvable)
        $mock->shouldReceive('execute')->andThrow(new RuntimeException('boom'));
        $this->app->instance(RunNaturalLanguageQuery::class, $mock);

        $response = $this->postJson(route('rdw.query.run'), ['prompt' => 'test prompt']);

        $response->assertStatus(500)
            ->assertJsonPath('error', 'Something went wrong building or running the query.');
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function fakeQueryPlan(array $plan, ?Usage $usage = null, string $model = 'fake'): void
    {
        $this->fakeProgram([
            'queries' => [['id' => 'q1', 'dataset' => 'RegisteredVehicles'] + $plan],
            'presentation' => [
                'resultRef' => 'q1',
                'display' => $plan['display'] ?? 'table',
                'derive' => null,
                'explanation' => $plan['explanation'] ?? '',
            ],
        ], $usage, $model);
    }

    /**
     * @param  array<string, mixed>  $program
     */
    private function fakeProgram(array $program, ?Usage $usage = null, string $model = 'fake'): void
    {
        QueryProgramAgent::fake([
            new StructuredTextResponse(
                $program,
                json_encode($program, JSON_THROW_ON_ERROR),
                $usage ?? new Usage,
                new Meta('openai', $model),
            ),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function fakeRdwWithRows(array $rows): void
    {
        $this->fakeRdwWithResponse(
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($rows, JSON_THROW_ON_ERROR)),
        );
    }

    private function fakeRdwWithResponse(Psr7Response $response): void
    {
        $this->fakeRdwWithResponses($response);
    }

    private function fakeRdwWithResponses(Psr7Response ...$responses): void
    {
        $this->fakeRdwWithQueue(array_values($responses));
    }

    /**
     * @param  list<Psr7Response|Throwable>  $queue
     */
    private function fakeRdwWithQueue(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));

        $guzzle = new GuzzleClient([
            'base_uri' => 'https://opendata.rdw.nl/',
            'handler' => $stack,
        ]);

        $rdw = new Rdw(http: new SocrataClient(new RdwConfiguration, $guzzle));
        $this->app->instance(Rdw::class, $rdw);
        $this->app->instance(PlanRunner::class, new PlanRunner($rdw, maxAttempts: 2, retryBackoffMs: 0));
    }
}
