<?php

declare(strict_types=1);

namespace Tests\Feature\Rdw;

use App\Models\QueryRun;
use App\Models\User;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as Psr7Response;
use NiekNijland\RDW\Http\Configuration as RdwConfiguration;
use NiekNijland\RDW\Http\SocrataClient;
use NiekNijland\RDW\Rdw;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Tests\TestCase;

final class QueryRunPersistenceTest extends TestCase
{
    public function test_successful_query_persists_a_query_run_and_returns_its_slug(): void
    {
        $this->fakePrismWithPlan([
            'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => 'VOLKSWAGEN']],
            'select' => [],
            'groupBy' => [],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [],
            'limit' => 1,
            'display' => 'count',
            'explanation' => 'How many VWs',
        ]);

        $this->fakeRdwWithRows([['n' => '42']]);

        $response = $this->postJson(route('rdw.query.run'), [
            'prompt' => 'How many VWs are there?',
        ]);

        $response->assertOk();
        $slug = $response->json('slug');
        self::assertIsString($slug);
        self::assertNotSame('', $slug);

        $run = QueryRun::query()->where('slug', $slug)->first();
        self::assertInstanceOf(QueryRun::class, $run);
        self::assertSame('How many VWs are there?', $run->prompt);
        self::assertSame('count', $run->display_hint);
        self::assertSame('en', $run->locale);
        self::assertNull($run->user_id);
        self::assertSame('VOLKSWAGEN', $run->plan['where'][0]['value']);
        self::assertSame([['n' => '42']], $run->rows);
    }

    public function test_persisted_run_records_the_authenticated_user(): void
    {
        $user = User::factory()->createOne();

        $this->fakePrismWithPlan([
            'where' => [],
            'select' => [],
            'groupBy' => [],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [],
            'limit' => 1,
            'display' => 'count',
            'explanation' => '',
        ]);

        $this->fakeRdwWithRows([['n' => '1']]);

        $response = $this->actingAs($user)->postJson(route('rdw.query.run'), [
            'prompt' => 'count everything',
        ]);

        $response->assertOk();
        $slug = $response->json('slug');
        $run = QueryRun::query()->where('slug', $slug)->first();
        self::assertInstanceOf(QueryRun::class, $run);
        self::assertSame((string) $user->getKey(), $run->user_id);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function fakePrismWithPlan(array $plan): void
    {
        Prism::fake([
            StructuredResponseFake::make()->withStructured($plan),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function fakeRdwWithRows(array $rows): void
    {
        $response = new Psr7Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($rows, JSON_THROW_ON_ERROR),
        );
        $mock = new MockHandler([$response]);
        $stack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://opendata.rdw.nl/',
            'handler' => $stack,
        ]);

        $this->app->instance(Rdw::class, new Rdw(
            http: new SocrataClient(new RdwConfiguration(), $guzzle),
        ));
    }
}
