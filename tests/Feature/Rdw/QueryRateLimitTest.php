<?php

declare(strict_types=1);

namespace Tests\Feature\Rdw;

use App\Actions\Rdw\RunNaturalLanguageQuery;
use App\Services\QueryPlan\DisplayHint;
use App\Services\QueryPlan\Plan;
use App\Services\QueryPlan\QueryResult;
use App\Services\QueryPlan\TokenUsage;
use Mockery;
use Tests\TestCase;

final class QueryRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The action is mocked so we never hit OpenAI or Socrata. It returns
        // an empty plan/rows result so the controller falls through to its
        // happy-path JSON response and the rate limiter is what gates traffic.
        $mock = Mockery::mock(RunNaturalLanguageQuery::class);
        // @phpstan-ignore method.notFound (Mockery fluent API is not statically resolvable)
        $mock->shouldReceive('execute')->andReturn(new QueryResult(
            plan: new Plan(
                where: [],
                select: [],
                groupBy: [],
                aggregates: [],
                orderBy: [],
                limit: null,
                display: DisplayHint::Count,
                explanation: '',
            ),
            rows: [],
            soql: [],
            url: 'https://opendata.rdw.nl/resource/test.json',
            model: 'fake',
            tokens: new TokenUsage(prompt: 0, completion: 0, cacheRead: 0, thought: 0),
            estimatedCost: null,
        ));
        $this->app->instance(RunNaturalLanguageQuery::class, $mock);
    }

    public function test_per_minute_burst_returns_429_after_threshold(): void
    {
        config()->set('rdwai.rate_limit.per_minute', 3);
        config()->set('rdwai.rate_limit.per_day_global', 1000);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('rdw.query.run'), ['prompt' => 'count vehicles'])
                ->assertOk();
        }

        $this->postJson(route('rdw.query.run'), ['prompt' => 'count vehicles'])
            ->assertStatus(429);
    }

    public function test_global_daily_cap_returns_429_even_across_different_ips(): void
    {
        config()->set('rdwai.rate_limit.per_minute', 1000);
        config()->set('rdwai.rate_limit.per_day_global', 2);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson(route('rdw.query.run'), ['prompt' => 'count vehicles'])
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson(route('rdw.query.run'), ['prompt' => 'count vehicles'])
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
            ->postJson(route('rdw.query.run'), ['prompt' => 'count vehicles'])
            ->assertStatus(429);
    }
}
