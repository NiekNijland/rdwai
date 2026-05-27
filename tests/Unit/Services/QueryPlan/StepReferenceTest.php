<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\StepReference;
use PHPUnit\Framework\TestCase;

final class StepReferenceTest extends TestCase
{
    public function test_parses_a_whole_value_token(): void
    {
        $reference = StepReference::tryParse('{{q1.Brand}}');

        self::assertNotNull($reference);
        self::assertSame('q1', $reference->queryId);
        self::assertSame('Brand', $reference->field);
    }

    public function test_tolerates_inner_whitespace(): void
    {
        $reference = StepReference::tryParse('{{  q2 . CommercialName  }}');

        self::assertNotNull($reference);
        self::assertSame('q2', $reference->queryId);
        self::assertSame('CommercialName', $reference->field);
    }

    public function test_plain_literal_is_not_a_reference(): void
    {
        self::assertNull(StepReference::tryParse('VOLKSWAGEN'));
        self::assertNull(StepReference::tryParse(''));
    }

    public function test_rejects_partial_or_mixed_tokens(): void
    {
        self::assertNull(StepReference::tryParse('prefix {{q1.Brand}}'));
        self::assertNull(StepReference::tryParse('{{q1.Brand}} suffix'));
        self::assertNull(StepReference::tryParse('{{q1.Brand}}{{q2.Brand}}'));
        self::assertNull(StepReference::tryParse('{{q1}}'));
        self::assertNull(StepReference::tryParse('{{q1.}}'));
    }

    public function test_token_round_trips(): void
    {
        self::assertSame('{{q3.PrimaryColor}}', (new StepReference('q3', 'PrimaryColor'))->token());
    }
}
