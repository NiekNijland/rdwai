<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

/**
 * Everything a natural-language query produces: the validated {@see Plan}, the
 * executed {@see RunnerResult} (rows + SoQL + URL), and the LLM call metadata
 * (model, {@see TokenUsage}, estimated USD cost). Consumed by the controller to
 * build the JSON response and to persist the run.
 */
final readonly class QueryResult
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $soql
     */
    public function __construct(
        public Plan $plan,
        public array $rows,
        public array $soql,
        public string $url,
        public string $model,
        public TokenUsage $tokens,
        public ?float $estimatedCost,
    ) {
    }
}
