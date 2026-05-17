<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QueryRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

/**
 * A single natural-language query the user ran. Stored so results can be
 * shared via a stable slug, rated, and aggregated into "popular" suggestions.
 *
 * @property string $id
 * @property string $slug
 * @property string $prompt
 * @property string $locale
 * @property array<string, mixed> $plan
 * @property array<string, string> $soql
 * @property string $url
 * @property list<array<string, mixed>> $rows
 * @property string $display_hint
 * @property string|null $user_id
 * @property string|null $rating
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $rated_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static QueryRun create(array<string, mixed> $attributes = [])
 * @method static \MongoDB\Laravel\Eloquent\Builder<QueryRun> query()
 */
#[Fillable([
    'slug',
    'prompt',
    'locale',
    'plan',
    'soql',
    'url',
    'rows',
    'display_hint',
    'user_id',
    'rating',
    'comment',
    'rated_at',
])]
class QueryRun extends Model
{
    /** @use HasFactory<QueryRunFactory> */
    use HasFactory;

    protected $connection = 'mongodb';

    public const string RATING_UP = 'up';

    public const string RATING_DOWN = 'down';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'soql' => 'array',
            'rows' => 'array',
            'rated_at' => 'datetime',
        ];
    }
}
