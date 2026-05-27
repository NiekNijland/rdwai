<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\Derivation;
use App\Services\QueryPlan\DerivationException;
use App\Services\QueryPlan\DeriveOp;
use PHPUnit\Framework\TestCase;

final class DerivationTest extends TestCase
{
    public function test_percentage_stores_the_raw_quotient_and_operands(): void
    {
        $derived = (new Derivation())->percentage(12_345, 380_210);

        self::assertSame(DeriveOp::Percentage, $derived->op);
        self::assertEqualsWithDelta(0.03247, $derived->value, 0.00001);
        self::assertSame(12_345.0, $derived->numerator);
        self::assertSame(380_210.0, $derived->denominator);
    }

    public function test_ratio_difference_and_sum(): void
    {
        $derivation = new Derivation();

        self::assertSame(2.0, $derivation->ratio(10, 5)->value);
        self::assertSame(5.0, $derivation->difference(8, 3)->value);
        self::assertSame(11.0, $derivation->sum(8, 3)->value);
    }

    public function test_division_by_zero_throws(): void
    {
        $this->expectException(DerivationException::class);

        (new Derivation())->percentage(1, 0);
    }

    public function test_group_share_selects_a_group_and_divides_by_the_column_total(): void
    {
        $derived = (new Derivation())->groupShare(
            rows: [
                ['PrimaryColor' => 'GEEL', 'n' => '320'],
                ['PrimaryColor' => 'WIT', 'n' => '4680'],
                ['PrimaryColor' => 'ZWART', 'n' => '5000'],
            ],
            labelColumn: 'PrimaryColor',
            value: 'GEEL',
            countColumn: 'n',
        );

        self::assertSame(DeriveOp::GroupShare, $derived->op);
        self::assertSame(320.0, $derived->numerator);
        self::assertSame(10_000.0, $derived->denominator);
        self::assertEqualsWithDelta(0.032, $derived->value, 0.00001);
    }

    public function test_group_share_throws_when_selector_matches_no_row(): void
    {
        $this->expectException(DerivationException::class);

        (new Derivation())->groupShare(
            rows: [['PrimaryColor' => 'WIT', 'n' => '4680']],
            labelColumn: 'PrimaryColor',
            value: 'GEEL',
            countColumn: 'n',
        );
    }
}
