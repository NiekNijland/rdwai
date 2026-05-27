<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\Derive;
use App\Services\QueryPlan\DeriveOp;
use App\Services\QueryPlan\DisplayHint;
use App\Services\QueryPlan\Presentation;
use App\Services\QueryPlan\PresentationFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PresentationFactoryTest extends TestCase
{
    public function test_builds_a_plain_presentation_pointing_at_a_query(): void
    {
        $presentation = (new PresentationFactory())->fromArray([
            'resultRef' => 'q2',
            'display' => 'count',
            'derive' => null,
            'explanation' => 'How many.',
        ], ['q1', 'q2']);

        self::assertSame('q2', $presentation->resultRef);
        self::assertSame(DisplayHint::Count, $presentation->display);
        self::assertFalse($presentation->isDerived());
    }

    public function test_rejects_a_result_ref_outside_the_program(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PresentationFactory())->fromArray([
            'resultRef' => 'q9', 'display' => 'count', 'derive' => null, 'explanation' => 'x',
        ], ['q1']);
    }

    public function test_builds_a_percentage_derive(): void
    {
        $presentation = (new PresentationFactory())->fromArray([
            'resultRef' => Presentation::DERIVED_REF,
            'display' => 'count',
            'derive' => ['op' => 'percentage', 'numerator' => 'q1', 'denominator' => 'q2'],
            'explanation' => 'Share.',
        ], ['q1', 'q2']);

        self::assertTrue($presentation->isDerived());

        $derive = $presentation->derive;
        self::assertInstanceOf(Derive::class, $derive);

        /** @var Derive $derive */
        self::assertSame(DeriveOp::Percentage, $derive->op);
        self::assertSame('q1', $derive->numerator);
        self::assertSame('q2', $derive->denominator);
    }

    public function test_a_derive_requires_the_derived_result_ref(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PresentationFactory())->fromArray([
            'resultRef' => 'q1',
            'display' => 'count',
            'derive' => ['op' => 'percentage', 'numerator' => 'q1', 'denominator' => 'q2'],
            'explanation' => 'x',
        ], ['q1', 'q2']);
    }

    public function test_rejects_a_derive_operand_outside_the_program(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PresentationFactory())->fromArray([
            'resultRef' => Presentation::DERIVED_REF,
            'display' => 'count',
            'derive' => ['op' => 'ratio', 'numerator' => 'q1', 'denominator' => 'q9'],
            'explanation' => 'x',
        ], ['q1', 'q2']);
    }

    public function test_builds_a_group_share_derive(): void
    {
        $presentation = (new PresentationFactory())->fromArray([
            'resultRef' => Presentation::DERIVED_REF,
            'display' => 'count',
            'derive' => [
                'op' => 'groupShare', 'source' => 'q1',
                'selectorColumn' => 'PrimaryColor', 'selectorValue' => 'GEEL',
            ],
            'explanation' => 'Yellow share.',
        ], ['q1']);

        $derive = $presentation->derive;
        self::assertInstanceOf(Derive::class, $derive);

        /** @var Derive $derive */
        self::assertSame(DeriveOp::GroupShare, $derive->op);
        self::assertSame('q1', $derive->source);
        self::assertSame('PrimaryColor', $derive->selectorColumn);
        self::assertSame('GEEL', $derive->selectorValue);
    }

    public function test_group_share_requires_a_selector(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PresentationFactory())->fromArray([
            'resultRef' => Presentation::DERIVED_REF,
            'display' => 'count',
            'derive' => ['op' => 'groupShare', 'source' => 'q1', 'selectorColumn' => '', 'selectorValue' => ''],
            'explanation' => 'x',
        ], ['q1']);
    }

    public function test_rejects_an_invalid_op_and_display(): void
    {
        $factory = new PresentationFactory();

        try {
            $factory->fromArray([
                'resultRef' => Presentation::DERIVED_REF, 'display' => 'count',
                'derive' => ['op' => 'nope', 'numerator' => 'q1', 'denominator' => 'q2'], 'explanation' => 'x',
            ], ['q1', 'q2']);
            self::fail('Expected an invalid op to throw.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->expectException(InvalidArgumentException::class);
        $factory->fromArray([
            'resultRef' => 'q1', 'display' => 'nonsense', 'derive' => null, 'explanation' => 'x',
        ], ['q1']);
    }
}
