<?php

declare(strict_types=1);

namespace App\Actions\Rdw;

use App\Enums\Locale;
use App\Services\QueryPlan\CostEstimator;
use App\Services\QueryPlan\Plan;
use App\Services\QueryPlan\PlanFactory;
use App\Services\QueryPlan\PlanRunner;
use App\Services\QueryPlan\PlanSchema;
use App\Services\QueryPlan\PromptBuilder;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class RunNaturalLanguageQuery
{
    public function __construct(
        private readonly PromptBuilder $promptBuilder,
        private readonly PlanFactory $planFactory,
        private readonly PlanRunner $planRunner,
        private readonly CostEstimator $costEstimator,
    ) {
    }

    /**
     * @return array{
     *     plan: Plan,
     *     rows: list<array<string, mixed>>,
     *     soql: array<string, string>,
     *     url: string,
     *     model: string,
     *     tokens: array{prompt: int, completion: int, cacheRead: int, thought: int},
     *     estimatedCost: float|null,
     * }
     */
    public function execute(string $userPrompt, Locale $locale): array
    {
        $response = Prism::structured()
            ->using(Provider::OpenAI, (string) config('rdwai.llm_model', 'gpt-4.1-nano'))
            ->withSchema(PlanSchema::build())
            ->withSystemPrompt($this->promptBuilder->systemPrompt($locale))
            ->withPrompt($this->promptBuilder->userPrompt($userPrompt))
            ->asStructured();

        /** @var array<string, mixed> $raw */
        $raw = $response->structured;
        $plan = $this->planFactory->fromArray($raw);

        $result = $this->planRunner->run($plan);

        return [
            'plan' => $plan,
            'rows' => $result['rows'],
            'soql' => $result['soql'],
            'url' => $result['url'],
            'model' => $response->meta->model,
            'tokens' => [
                'prompt' => $response->usage->promptTokens,
                'completion' => $response->usage->completionTokens,
                'cacheRead' => $response->usage->cacheReadInputTokens ?? 0,
                'thought' => $response->usage->thoughtTokens ?? 0,
            ],
            'estimatedCost' => $this->costEstimator->estimate($response->meta->model, $response->usage),
        ];
    }
}
