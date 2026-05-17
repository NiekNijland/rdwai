<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Enums\Locale;
use App\Services\QueryPlan\DisplayHint;
use App\Services\QueryPlan\PromptBuilder;
use NiekNijland\RDW\Schema\SchemaRegistry;
use PHPUnit\Framework\TestCase;

final class PromptBuilderTest extends TestCase
{
    public function test_prompt_documents_every_supported_display_hint(): void
    {
        $prompt = $this->builder()->systemPrompt(Locale::English);

        foreach (DisplayHint::cases() as $hint) {
            self::assertStringContainsString(
                "`{$hint->value}`",
                $prompt,
                "Display hint {$hint->value} should be documented in the prompt",
            );
        }
    }

    public function test_prompt_keeps_contains_rule_for_commercial_name(): void
    {
        $prompt = $this->builder()->systemPrompt(Locale::English);

        self::assertStringContainsString('ALWAYS use `contains` for CommercialName', $prompt);
        self::assertStringContainsString('CommercialName contains AYGO', $prompt);
    }

    public function test_prompt_uses_snake_case_stacked_bars_hint(): void
    {
        $prompt = $this->builder()->systemPrompt(Locale::English);

        self::assertStringContainsString('display: stacked_bars', $prompt);
        self::assertStringNotContainsString('display: stackedBars', $prompt);
    }

    public function test_prompt_disambiguates_the_lookalike_date_fields(): void
    {
        $prompt = $this->builder()->systemPrompt(Locale::English);

        self::assertStringContainsString('RegistrationDate', $prompt);
        self::assertStringContainsString('overgeschreven', $prompt);
        self::assertStringContainsString('FirstNetherlandsRegistrationDate', $prompt);
        self::assertStringContainsString('FirstAdmissionDate', $prompt);
    }

    public function test_prompt_forbids_license_plate_in_timeseries_group_by(): void
    {
        $prompt = $this->builder()->systemPrompt(Locale::English);

        self::assertStringContainsString('LicensePlate', $prompt);
        self::assertStringContainsString('flat line', $prompt);
    }

    public function test_prompt_picks_explanation_language_from_locale(): void
    {
        $builder = $this->builder();

        self::assertStringContainsString(
            'written in English',
            $builder->systemPrompt(Locale::English),
        );
        self::assertStringContainsString(
            'written in Dutch',
            $builder->systemPrompt(Locale::Dutch),
        );
    }

    private function builder(): PromptBuilder
    {
        return new PromptBuilder(new SchemaRegistry);
    }
}
