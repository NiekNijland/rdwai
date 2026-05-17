<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QueryRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QueryRun>
 */
class QueryRunFactory extends Factory
{
    protected $model = QueryRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => Str::lower(Str::random(12)),
            'prompt' => fake()->sentence(),
            'locale' => fake()->randomElement(['nl', 'en']),
            'plan' => [
                'where' => [],
                'select' => [],
                'groupBy' => [],
                'aggregates' => [['fn' => 'count', 'field' => null, 'alias' => 'n']],
                'orderBy' => [],
                'limit' => 10,
                'display' => 'count',
                'explanation' => '',
            ],
            'soql' => ['$select' => 'count(*) as n'],
            'url' => 'https://opendata.rdw.nl/resource/m9d7-ebf2.json',
            'rows' => [['n' => '1']],
            'display_hint' => 'count',
            'user_id' => null,
            'rating' => null,
            'comment' => null,
            'rated_at' => null,
        ];
    }

    public function ratedUp(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rating' => QueryRun::RATING_UP,
            'rated_at' => now(),
        ]);
    }

    public function ratedDown(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rating' => QueryRun::RATING_DOWN,
            'rated_at' => now(),
        ]);
    }
}
