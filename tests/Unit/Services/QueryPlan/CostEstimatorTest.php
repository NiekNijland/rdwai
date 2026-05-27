<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\CostEstimator;
use Laravel\Ai\Responses\Data\Usage;
use PHPUnit\Framework\TestCase;

final class CostEstimatorTest extends TestCase
{
    public function test_known_model_returns_expected_cost(): void
    {
        $estimator = new CostEstimator([
            'gpt-4.1-nano' => [
                'input' => 0.10,
                'cached_input' => 0.025,
                'output' => 0.40,
            ],
        ]);

        $usage = new Usage(
            promptTokens: 1_000_000,
            completionTokens: 1_000_000,
        );

        self::assertSame(0.50, $estimator->estimate('gpt-4.1-nano', $usage));
    }

    public function test_unknown_model_returns_null(): void
    {
        $estimator = new CostEstimator([
            'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
        ]);

        $usage = new Usage(promptTokens: 100, completionTokens: 50);

        self::assertNull($estimator->estimate('claude-haiku-4-5', $usage));
    }

    public function test_dated_variant_resolves_via_prefix_match(): void
    {
        $estimator = new CostEstimator([
            'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
        ]);

        $usage = new Usage(
            promptTokens: 1_000_000,
            completionTokens: 0,
        );

        self::assertSame(0.10, $estimator->estimate('gpt-4.1-nano-2025-04-14', $usage));
    }

    public function test_cache_read_falls_back_to_input_rate_when_cached_rate_missing(): void
    {
        $estimator = new CostEstimator([
            'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
        ]);

        $usage = new Usage(
            promptTokens: 1_000_000,
            completionTokens: 0,
            cacheReadInputTokens: 500_000,
        );

        // 500k fresh @ 0.10 + 500k cached @ 0.10 (fallback) = 0.10 total
        self::assertSame(0.10, $estimator->estimate('gpt-4.1-nano', $usage));
    }

    public function test_cache_read_uses_cached_rate_when_configured(): void
    {
        $estimator = new CostEstimator([
            'gpt-4.1-nano' => [
                'input' => 0.10,
                'cached_input' => 0.025,
                'output' => 0.40,
            ],
        ]);

        $usage = new Usage(
            promptTokens: 1_000_000,
            completionTokens: 0,
            cacheReadInputTokens: 500_000,
        );

        // 500k fresh @ 0.10 + 500k cached @ 0.025 = 0.0625
        self::assertSame(0.0625, $estimator->estimate('gpt-4.1-nano', $usage));
    }

    public function test_prefix_match_requires_dash_boundary_so_gpt_4_does_not_shadow_gpt_4o(): void
    {
        // Only the family-level `gpt-4` key is configured. A future
        // `gpt-4o-2024-11-20` variant must not silently inherit the gpt-4
        // family's price.
        $estimator = new CostEstimator([
            'gpt-4' => ['input' => 2.50, 'output' => 10.00],
        ]);

        $usage = new Usage(promptTokens: 1_000, completionTokens: 0);

        self::assertNull($estimator->estimate('gpt-4o-2024-11-20', $usage));
        self::assertNull($estimator->estimate('gpt-4.1-nano', $usage));
        self::assertNotNull($estimator->estimate('gpt-4-turbo-2024-04-09', $usage));
    }

    public function test_longest_dash_prefix_wins_when_multiple_keys_match(): void
    {
        $estimator = new CostEstimator([
            'gpt-4' => ['input' => 30.00, 'output' => 60.00],
            'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
        ]);

        $usage = new Usage(promptTokens: 1_000_000, completionTokens: 0);

        // 1M @ 10.00 / 1M = 10.00; the gpt-4-turbo key (longer) wins.
        self::assertSame(10.00, $estimator->estimate('gpt-4-turbo-2024-04-09', $usage));
    }

    public function test_thought_tokens_are_billed_at_the_output_rate(): void
    {
        $estimator = new CostEstimator([
            'o1-mini' => ['input' => 3.00, 'output' => 12.00],
        ]);

        $usage = new Usage(
            promptTokens: 1_000_000,
            completionTokens: 0,
            reasoningTokens: 1_000_000,
        );

        // 1M prompt @ 3.00 + 1M thought @ 12.00 = 15.00
        self::assertSame(15.00, $estimator->estimate('o1-mini', $usage));
    }
}
