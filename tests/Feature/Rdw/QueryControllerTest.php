<?php

declare(strict_types=1);

namespace Tests\Feature\Rdw;

use App\Actions\Rdw\RunNaturalLanguageQuery;
use App\Ai\Agents\QueryPlanAgent;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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
     * @param array<string, mixed> $plan
     */
    private function fakeQueryPlan(array $plan, ?Usage $usage = null, string $model = 'fake'): void
    {
        QueryPlanAgent::fake([
            new StructuredTextResponse(
                $plan,
                json_encode($plan, JSON_THROW_ON_ERROR),
                $usage ?? new Usage(),
                new Meta('openai', $model),
            ),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function fakeRdwWithRows(array $rows): void
    {
        $this->fakeRdwWithResponse(
            new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($rows, JSON_THROW_ON_ERROR)),
        );
    }

    private function fakeRdwWithResponse(Psr7Response $response): void
    {
        $this->app->instance(Rdw::class, $this->makeFakeRdw($response));
    }

    private function makeFakeRdw(Psr7Response $response): Rdw
    {
        $mock = new MockHandler([$response]);
        $stack = HandlerStack::create($mock);

        $guzzle = new GuzzleClient([
            'base_uri' => 'https://opendata.rdw.nl/',
            'handler' => $stack,
        ]);

        return new Rdw(http: new SocrataClient(new RdwConfiguration(), $guzzle));
    }
}
