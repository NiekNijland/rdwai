<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use App\Models\QueryRun;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Token counts for a single LLM call, normalised from the laravel/ai
 * {@see Usage} value object into the fixed four-field shape the frontend and
 * {@see QueryRun} persistence expect. This is the single seam that
 * touches the SDK's usage type; everything downstream consumes this DTO.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $prompt,
        public int $completion,
        public int $cacheRead,
        public int $thought,
    ) {
    }

    public static function fromUsage(Usage $usage): self
    {
        return new self(
            prompt: $usage->promptTokens,
            completion: $usage->completionTokens,
            cacheRead: $usage->cacheReadInputTokens,
            thought: $usage->reasoningTokens,
        );
    }

    /**
     * Rebuild the usage from a persisted run, coalescing the nullable token
     * columns to 0 so the fixed four-field shape always holds.
     */
    public static function fromQueryRun(QueryRun $run): self
    {
        return new self(
            prompt: $run->prompt_tokens ?? 0,
            completion: $run->completion_tokens ?? 0,
            cacheRead: $run->cache_read_tokens ?? 0,
            thought: $run->thought_tokens ?? 0,
        );
    }

    /**
     * @return array{prompt: int, completion: int, cacheRead: int, thought: int}
     */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'completion' => $this->completion,
            'cacheRead' => $this->cacheRead,
            'thought' => $this->thought,
        ];
    }
}
