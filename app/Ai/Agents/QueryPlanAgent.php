<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Enums\Locale;
use App\Services\QueryPlan\Plan;
use App\Services\QueryPlan\PlanSchema;
use App\Services\QueryPlan\PromptBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

/**
 * Translates a natural-language question about the RDW vehicle registry into a
 * structured {@see Plan} via OpenAI structured output.
 *
 * The locale is injected per request so the system prompt asks for the
 * explanation in the user's language. The model is read from config so it can
 * be tuned without a code change; the provider is pinned to OpenAI because the
 * strict structured-output schema in {@see PlanSchema} targets it.
 */
final class QueryPlanAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly PromptBuilder $promptBuilder,
        private readonly Locale $locale = Locale::English,
    ) {
    }

    /**
     * Ask the model a user question and return its structured plan response.
     *
     * {@see Promptable::prompt()} widens its return to the base
     * {@see \Laravel\Ai\Responses\AgentResponse}; because this agent declares
     * {@see HasStructuredOutput} the provider always answers with a
     * {@see StructuredAgentResponse}, so we narrow it back here and fail loudly
     * if a provider ever breaks that contract.
     */
    public function ask(string $question): StructuredAgentResponse
    {
        $response = $this->prompt($this->promptBuilder->userPrompt($question));

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('QueryPlanAgent did not return a structured response.');
        }

        return $response;
    }

    public function instructions(): string
    {
        return $this->promptBuilder->systemPrompt($this->locale);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return PlanSchema::build($schema);
    }

    public function provider(): Lab
    {
        return Lab::OpenAI;
    }

    public function model(): string
    {
        return (string) config('rdwai.llm_model', 'gpt-4.1-mini');
    }
}
