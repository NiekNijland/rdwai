<?php

declare(strict_types=1);

namespace App\Actions\Rdw;

use App\Models\QueryRun;
use App\Services\QueryPlan\Plan;
use App\Services\QueryPlan\PlanPresenter;
use App\Services\QueryPlan\TokenUsage;
use Illuminate\Support\Str;
use LogicException;
use MongoDB\Driver\Exception\BulkWriteException;
use Throwable;

final class PersistQueryRun
{
    private const int SLUG_LENGTH = 10;

    private const int MAX_ATTEMPTS = 5;

    private const int DUPLICATE_KEY_CODE = 11000;

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $soql
     */
    public function execute(
        string $prompt,
        string $locale,
        Plan $plan,
        array $rows,
        array $soql,
        string $url,
        ?string $userId,
        string $model,
        TokenUsage $tokens,
        ?float $estimatedCost,
    ): QueryRun {
        $attributes = [
            'prompt' => $prompt,
            'locale' => $locale,
            'plan' => PlanPresenter::toArray($plan),
            'soql' => $soql,
            'url' => $url,
            'rows' => $rows,
            'display_hint' => $plan->display->value,
            'user_id' => $userId,
            'model' => $model,
            'prompt_tokens' => $tokens->prompt,
            'completion_tokens' => $tokens->completion,
            'cache_read_tokens' => $tokens->cacheRead,
            'thought_tokens' => $tokens->thought,
            'estimated_cost' => $estimatedCost,
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                /** @var QueryRun $run */
                $run = QueryRun::query()->create([
                    ...$attributes,
                    'slug' => $this->generateSlug(),
                ]);

                return $run;
            } catch (Throwable $e) {
                if (! $this->isDuplicateSlug($e) || $attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        // Unreachable: the loop either returns or throws.
        throw new LogicException('Exhausted slug attempts without resolution');
    }

    private function generateSlug(): string
    {
        return Str::lower(Str::random(self::SLUG_LENGTH));
    }

    private function isDuplicateSlug(Throwable $e): bool
    {
        return $e instanceof BulkWriteException && $e->getCode() === self::DUPLICATE_KEY_CODE;
    }
}
