<?php

declare(strict_types=1);

namespace Tests\Feature\Rdw;

use App\Models\QueryRun;
use Tests\TestCase;

final class PopularQueriesTest extends TestCase
{
    public function test_popular_returns_upvoted_prompts_for_the_locale_ranked_by_votes(): void
    {
        QueryRun::factory()->count(3)->ratedUp()->create([
            'locale' => 'en',
            'prompt' => 'How many electric cars',
        ]);
        QueryRun::factory()->count(1)->ratedUp()->create([
            'locale' => 'en',
            'prompt' => 'How many trucks',
        ]);
        QueryRun::factory()->ratedDown()->createOne([
            'locale' => 'en',
            'prompt' => 'Bad prompt that was downvoted',
        ]);
        QueryRun::factory()->ratedUp()->createOne([
            'locale' => 'nl',
            'prompt' => 'Wrong locale prompt',
        ]);

        $response = $this->getJson(route('rdw.query.popular') . '?locale=en')
            ->assertOk();

        $prompts = $response->json('prompts');
        self::assertIsArray($prompts);
        self::assertSame('How many electric cars', $prompts[0]);
        self::assertContains('How many trucks', $prompts);
        self::assertNotContains('Bad prompt that was downvoted', $prompts);
        self::assertNotContains('Wrong locale prompt', $prompts);
    }

    public function test_popular_falls_back_to_recent_when_no_upvotes_exist(): void
    {
        QueryRun::factory()->createOne([
            'locale' => 'en',
            'prompt' => 'Recent only prompt',
        ]);

        $response = $this->getJson(route('rdw.query.popular') . '?locale=en')
            ->assertOk();

        self::assertContains('Recent only prompt', $response->json('prompts'));
    }

    public function test_popular_returns_empty_list_when_collection_is_empty(): void
    {
        $this->getJson(route('rdw.query.popular') . '?locale=en')
            ->assertOk()
            ->assertJsonPath('prompts', []);
    }

    public function test_popular_uses_app_locale_when_query_parameter_is_missing(): void
    {
        QueryRun::factory()->ratedUp()->createOne([
            'locale' => 'en',
            'prompt' => 'English popular prompt',
        ]);

        $this->getJson(route('rdw.query.popular'))
            ->assertOk()
            ->assertJsonPath('prompts.0', 'English popular prompt');
    }

    public function test_popular_falls_back_to_app_locale_when_query_parameter_is_invalid(): void
    {
        QueryRun::factory()->ratedUp()->createOne([
            'locale' => 'en',
            'prompt' => 'English popular prompt',
        ]);
        QueryRun::factory()->ratedUp()->createOne([
            'locale' => 'fr',
            'prompt' => 'French prompt that should be ignored',
        ]);

        $this->getJson(route('rdw.query.popular') . '?locale=fr')
            ->assertOk()
            ->assertJsonPath('prompts.0', 'English popular prompt')
            ->assertJsonMissing(['prompts' => ['French prompt that should be ignored']]);
    }

    public function test_popular_tops_up_with_recent_when_upvotes_do_not_fill_the_limit(): void
    {
        QueryRun::factory()->ratedUp()->createOne([
            'locale' => 'en',
            'prompt' => 'Single upvoted prompt',
        ]);

        foreach (range(1, 5) as $i) {
            QueryRun::factory()->createOne([
                'locale' => 'en',
                'prompt' => "Recent prompt {$i}",
            ]);
        }

        $response = $this->getJson(route('rdw.query.popular') . '?locale=en')
            ->assertOk();

        $prompts = $response->json('prompts');
        self::assertIsArray($prompts);
        self::assertSame('Single upvoted prompt', $prompts[0]);
        self::assertGreaterThan(1, count($prompts));
    }
}
