<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\DisplayHint;
use App\Services\QueryPlan\LedgerEntry;
use App\Services\QueryPlan\Plan;
use App\Services\QueryPlan\QueryLedger;
use App\Services\QueryPlan\RunnerResult;
use App\Services\QueryPlan\StepReferenceException;
use App\Services\QueryPlan\StepReferenceResolver;
use App\Services\QueryPlan\TargetDataset;
use App\Services\QueryPlan\WhereClause;
use App\Services\QueryPlan\WhereOp;
use PHPUnit\Framework\TestCase;

final class StepReferenceResolverTest extends TestCase
{
    public function test_resolves_references_from_a_single_row_lookup(): void
    {
        $ledger = $this->ledgerWith('q1', [['Brand' => 'VOLKSWAGEN', 'CommercialName' => 'UP']]);

        $resolved = (new StepReferenceResolver)->resolve(
            $this->plan([
                new WhereClause('Brand', WhereOp::Equals, '{{q1.Brand}}'),
                new WhereClause('CommercialName', WhereOp::Equals, '{{q1.CommercialName}}'),
            ]),
            $ledger,
        );

        self::assertSame('VOLKSWAGEN', $resolved->where[0]->value);
        self::assertSame('UP', $resolved->where[1]->value);
        self::assertSame('Brand', $resolved->where[0]->field);
        self::assertSame(WhereOp::Equals, $resolved->where[0]->op);
    }

    public function test_passes_plain_literals_through_untouched(): void
    {
        $resolved = (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('Brand', WhereOp::Equals, 'TOYOTA')]),
            new QueryLedger,
        );

        self::assertSame('TOYOTA', $resolved->where[0]->value);
    }

    public function test_throws_when_referenced_query_is_missing(): void
    {
        $this->expectException(StepReferenceException::class);

        (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('Brand', WhereOp::Equals, '{{q9.Brand}}')]),
            new QueryLedger,
        );
    }

    public function test_throws_when_referenced_query_returned_no_rows(): void
    {
        $this->expectException(StepReferenceException::class);

        (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('Brand', WhereOp::Equals, '{{q1.Brand}}')]),
            $this->ledgerWith('q1', []),
        );
    }

    public function test_throws_when_referenced_query_returned_multiple_rows(): void
    {
        $this->expectException(StepReferenceException::class);

        (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('Brand', WhereOp::Equals, '{{q1.Brand}}')]),
            $this->ledgerWith('q1', [['Brand' => 'A'], ['Brand' => 'B']]),
        );
    }

    public function test_throws_when_referenced_column_is_absent(): void
    {
        $this->expectException(StepReferenceException::class);

        (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('CommercialName', WhereOp::Equals, '{{q1.CommercialName}}')]),
            $this->ledgerWith('q1', [['Brand' => 'VOLKSWAGEN']]),
        );
    }

    public function test_throws_when_referenced_value_is_null(): void
    {
        $this->expectException(StepReferenceException::class);

        (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('Brand', WhereOp::Equals, '{{q1.Brand}}')]),
            $this->ledgerWith('q1', [['Brand' => null]]),
        );
    }

    public function test_in_op_resolves_a_multi_row_reference_into_values(): void
    {
        $ledger = $this->ledgerWith('q1', [
            ['LicensePlate' => 'AA-001-A'],
            ['LicensePlate' => 'BB-002-B'],
            ['LicensePlate' => 'AA-001-A'],
        ]);

        $resolved = (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('LicensePlate', WhereOp::In, '{{q1.LicensePlate}}')]),
            $ledger,
        );

        // Duplicates collapse and original order is preserved.
        self::assertSame(['AA-001-A', 'BB-002-B'], $resolved->where[0]->values);
        self::assertSame('{{q1.LicensePlate}}', $resolved->where[0]->value);
    }

    public function test_in_op_accepts_a_lookup_at_exactly_the_limit(): void
    {
        // The prompt tells the LLM to set lookup `limit: 1000`; refusing a complete cap-sized
        // result would silently break those questions.
        $rows = array_map(
            static fn (int $i): array => ['LicensePlate' => sprintf('AA-%03d-A', $i)],
            range(1, StepReferenceResolver::LIST_LIMIT),
        );

        $resolved = (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('LicensePlate', WhereOp::In, '{{q1.LicensePlate}}')]),
            $this->ledgerWith('q1', $rows),
        );

        self::assertCount(StepReferenceResolver::LIST_LIMIT, $resolved->where[0]->values);
    }

    public function test_in_op_rejects_a_lookup_that_exceeds_the_limit(): void
    {
        $rows = array_map(
            static fn (int $i): array => ['LicensePlate' => sprintf('AA-%04d-A', $i)],
            range(1, StepReferenceResolver::LIST_LIMIT + 1),
        );

        $this->expectException(StepReferenceException::class);
        $this->expectExceptionMessage('exceeds the 1000-row cross-dataset limit');

        (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('LicensePlate', WhereOp::In, '{{q1.LicensePlate}}')]),
            $this->ledgerWith('q1', $rows),
        );
    }

    public function test_in_op_throws_when_lookup_is_empty(): void
    {
        $this->expectException(StepReferenceException::class);

        (new StepReferenceResolver)->resolve(
            $this->plan([new WhereClause('LicensePlate', WhereOp::In, '{{q1.LicensePlate}}')]),
            $this->ledgerWith('q1', []),
        );
    }

    /**
     * @param  list<WhereClause>  $where
     */
    private function plan(array $where): Plan
    {
        return new Plan(TargetDataset::RegisteredVehicles, $where, [], [], [], [], 1, DisplayHint::Count, '');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function ledgerWith(string $id, array $rows): QueryLedger
    {
        $ledger = new QueryLedger;
        $ledger->record(new LedgerEntry(
            $id,
            $this->plan([]),
            new RunnerResult(rows: $rows, soql: [], url: ''),
        ));

        return $ledger;
    }
}
