<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use Prism\Prism\ValueObjects\Usage;

/**
 * Estimates USD cost for a single LLM call from token usage and the
 * model-pricing map configured in `config/rdwai.php`.
 *
 * Returns null when the model isn't priced. OpenAI sometimes returns a dated
 * variant id (e.g. `gpt-4.1-nano-2025-04-14`) that won't match the bare key in
 * config — we fall back to the longest configured key that the returned id
 * starts with, *up to a `-` boundary*, so dated variants still resolve to
 * their family's price without `gpt-4` accidentally shadowing `gpt-4o`.
 */
final readonly class CostEstimator
{
    private const float RATE_DIVISOR = 1_000_000.0;

    /**
     * @param array<string, array{input?: float|int, cached_input?: float|int, output?: float|int}> $prices
     */
    public function __construct(private array $prices)
    {
    }

    public function estimate(string $model, Usage $usage): ?float
    {
        $rates = $this->resolveRates($model);
        if ($rates === null) {
            return null;
        }

        $cacheRead = $usage->cacheReadInputTokens ?? 0;
        $freshPromptTokens = max(0, $usage->promptTokens - $cacheRead);

        $inputRate = (float) ($rates['input'] ?? 0);
        // Fall back to the regular input rate when the model entry doesn't
        // declare a cache-read rate. Better to slightly overestimate cost than
        // to silently drop the cached tokens from the total.
        $cachedRate = isset($rates['cached_input']) ? (float) $rates['cached_input'] : $inputRate;
        $outputRate = (float) ($rates['output'] ?? 0);

        // OpenAI reasoning models bill thought (reasoning) tokens at the output
        // rate; non-reasoning models report 0 here. Fold them into the output
        // bucket so adding an `o*` model later doesn't silently undercharge.
        $outputTokens = $usage->completionTokens + ($usage->thoughtTokens ?? 0);

        $cost = ($freshPromptTokens * $inputRate)
            + ($cacheRead * $cachedRate)
            + ($outputTokens * $outputRate);

        return $cost / self::RATE_DIVISOR;
    }

    /**
     * @return array{input?: float|int, cached_input?: float|int, output?: float|int}|null
     */
    private function resolveRates(string $model): ?array
    {
        if (isset($this->prices[$model])) {
            return $this->prices[$model];
        }

        $bestKey = null;
        foreach (array_keys($this->prices) as $key) {
            // Require a `-` boundary so `gpt-4` only matches `gpt-4-...`
            // family members, not `gpt-4o-...` or `gpt-4.1-...`.
            if (! str_starts_with($model, $key . '-')) {
                continue;
            }
            if ($bestKey === null || strlen($key) > strlen($bestKey)) {
                $bestKey = $key;
            }
        }

        return $bestKey === null ? null : $this->prices[$bestKey];
    }
}
