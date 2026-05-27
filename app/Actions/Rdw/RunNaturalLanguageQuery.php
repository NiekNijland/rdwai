<?php

declare(strict_types=1);

namespace App\Actions\Rdw;

use App\Ai\Agents\QueryPlanAgent;
use App\Enums\Locale;
use App\Services\QueryPlan\CostEstimator;
use App\Services\QueryPlan\PlanFactory;
use App\Services\QueryPlan\PlanRunner;
use App\Services\QueryPlan\QueryResult;
use App\Services\QueryPlan\TokenUsage;

class RunNaturalLanguageQuery
{
    public function __construct(
        private readonly PlanFactory $planFactory,
        private readonly PlanRunner $planRunner,
        private readonly CostEstimator $costEstimator,
    ) {
    }

    public function execute(string $userPrompt, Locale $locale): QueryResult
    {
        $response = QueryPlanAgent::make(locale: $locale)->ask($userPrompt);

        /** @var array<string, mixed> $raw */
        $raw = $response->structured;
        $plan = $this->planFactory->fromArray($raw);

        $result = $this->planRunner->run($plan);

        $model = $response->meta->model ?? '';

        return new QueryResult(
            plan: $plan,
            rows: $result->rows,
            soql: $result->soql,
            url: $result->url,
            model: $model,
            tokens: TokenUsage::fromUsage($response->usage),
            estimatedCost: $this->costEstimator->estimate($model, $response->usage),
        );
    }
}
