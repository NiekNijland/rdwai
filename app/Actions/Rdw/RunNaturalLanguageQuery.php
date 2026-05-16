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

final readonly class RunNaturalLanguageQuery
{
    public function __construct(
        private PromptBuilder $promptBuilder,
        private PlanFactory $planFactory,
        private PlanRunner $planRunner,
    ) {}

    /**
     * @return array{plan: Plan, rows: list<array<string, mixed>>, soql: array<string, string>}
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

        try {
            $result = $this->planRunner->run($plan);
        } catch (\Throwable $e) {
            throw new QueryExecutionException($plan, $e);
        }

        return [
            'plan' => $plan,
            'rows' => $result['rows'],
            'soql' => $result['soql'],
        ];
    }
}
