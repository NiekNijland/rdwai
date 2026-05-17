<?php

declare(strict_types=1);

namespace Tests\Feature\Rdw;

use App\Models\QueryRun;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class QueryShareTest extends TestCase
{
    public function test_home_passes_shared_run_when_q_parameter_matches_a_persisted_run(): void
    {
        QueryRun::factory()->createOne([
            'slug' => 'sharedabc1',
            'prompt' => 'shared prompt',
        ]);

        $this->get(route('home') . '?q=sharedabc1')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('query/index')
                    ->where('sharedRun.slug', 'sharedabc1')
                    ->where('sharedRun.prompt', 'shared prompt'),
            );
    }

    public function test_home_passes_null_shared_run_when_q_does_not_match(): void
    {
        $this->get(route('home') . '?q=nope1234')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('query/index')
                    ->where('sharedRun', null),
            );
    }

    public function test_home_passes_null_shared_run_when_q_is_absent(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('query/index')
                    ->where('sharedRun', null),
            );
    }
}
