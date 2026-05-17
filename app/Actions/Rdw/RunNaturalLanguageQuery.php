<?php

declare(strict_types=1);

namespace App\Actions\Rdw;

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
    ) {
    }

    /**
     * @return array{plan: Plan, rows: list<array<string, mixed>>, soql: array<string, string>, url: string}
     */
    public function execute(string $userPrompt): array
    {
        $response = Prism::structured()
            ->using(Provider::OpenAI, (string) config('rdwai.llm_model', 'gpt-4.1-nano'))
            ->withSchema(PlanSchema::build())
            ->withSystemPrompt($this->promptBuilder->systemPrompt())
            ->withPrompt($userPrompt)
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
        ];
    }
}
