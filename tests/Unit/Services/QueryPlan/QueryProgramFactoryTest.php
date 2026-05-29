<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\PlanFactory;
use App\Services\QueryPlan\PresentationFactory;
use App\Services\QueryPlan\QueryProgramFactory;
use App\Services\QueryPlan\WhereOp;
use InvalidArgumentException;
use NiekNijland\RDW\Schema\SchemaRegistry;
use PHPUnit\Framework\TestCase;

final class QueryProgramFactoryTest extends TestCase
{
    public function test_builds_the_plate_to_model_program_with_a_backward_reference(): void
    {
        $program = $this->factory()->fromArray([
            'queries' => [
                $this->lookupQuery('q1'),
                $this->countModelQuery('q2'),
            ],
            'presentation' => [
                'resultRef' => 'q2',
                'display' => 'count',
                'derive' => null,
                'explanation' => 'Same make and model as the plate.',
            ],
        ]);

        self::assertCount(2, $program->queries);
        self::assertSame('q1', $program->queries[0]->id);
        self::assertSame('q2', $program->queries[1]->id);

        // The reference token survives untouched; resolution is a runtime concern.
        self::assertSame(WhereOp::Equals, $program->queries[1]->plan->where[0]->op);
        self::assertSame('{{q1.Brand}}', $program->queries[1]->plan->where[0]->value);
        self::assertSame('{{q1.CommercialName}}', $program->queries[1]->plan->where[1]->value);

        self::assertSame('q2', $program->presentation->resultRef);
        self::assertFalse($program->presentation->isDerived());
    }

    public function test_rejects_a_forward_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->fromArray([
            'queries' => [
                // q1 references q2, which is defined later.
                $this->countModelQuery('q1', 'q2'),
                $this->lookupQuery('q2'),
            ],
            'presentation' => $this->presentation('q1'),
        ]);
    }

    public function test_rejects_a_self_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->fromArray([
            'queries' => [$this->countModelQuery('q1', 'q1')],
            'presentation' => $this->presentation('q1'),
        ]);
    }

    public function test_rejects_duplicate_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->fromArray([
            'queries' => [$this->lookupQuery('q1'), $this->lookupQuery('q1')],
            'presentation' => $this->presentation('q1'),
        ]);
    }

    public function test_rejects_a_reference_to_an_unknown_field(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->fromArray([
            'queries' => [
                $this->lookupQuery('q1'),
                [
                    'id' => 'q2',
                    'where' => [['field' => 'Brand', 'op' => 'eq', 'value' => '{{q1.NotAField}}']],
                    'select' => [], 'groupBy' => [],
                    'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
                    'orderBy' => [], 'limit' => 1, 'display' => 'count', 'explanation' => 'x',
                ],
            ],
            'presentation' => $this->presentation('q2'),
        ]);
    }

    public function test_rejects_more_than_the_query_cap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->fromArray([
            'queries' => array_map(fn (int $i): array => $this->lookupQuery("q{$i}"), range(1, 5)),
            'presentation' => $this->presentation('q1'),
        ]);
    }

    public function test_rejects_an_empty_program(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->fromArray([
            'queries' => [],
            'presentation' => $this->presentation('q1'),
        ]);
    }

    public function test_rejects_a_presentation_ref_to_an_unknown_query(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->fromArray([
            'queries' => [$this->lookupQuery('q1')],
            'presentation' => $this->presentation('q9'),
        ]);
    }

    public function test_rejects_a_query_with_missing_dataset(): void
    {
        // The JSON schema marks `dataset` as required; the factory must enforce that instead of
        // silently defaulting, otherwise a missing dataset would surface as a confusing
        // "Unknown field …" error downstream.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query "q1" must declare a dataset.');

        $query = $this->lookupQuery('q1');
        unset($query['dataset']);

        $this->factory()->fromArray([
            'queries' => [$query],
            'presentation' => $this->presentation('q1'),
        ]);
    }

    public function test_rejects_a_query_with_an_unknown_dataset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dataset "RegisteredVehicleBananas" on query "q1".');

        $query = $this->lookupQuery('q1');
        $query['dataset'] = 'RegisteredVehicleBananas';

        $this->factory()->fromArray([
            'queries' => [$query],
            'presentation' => $this->presentation('q1'),
        ]);
    }

    public function test_step_reference_field_is_resolved_against_the_referenced_querys_dataset(): void
    {
        // q1 lives on the fuels dataset and selects NetMaximumPower (a fuel-only field).
        // q2 references {{q1.NetMaximumPower}}. The validator must check NetMaximumPower
        // against the fuels lookup — not against q2's own dataset, which doesn't have it.
        $program = $this->factory()->fromArray([
            'queries' => [
                [
                    'id' => 'q1',
                    'dataset' => 'RegisteredVehicleFuels',
                    'where' => [['field' => 'LicensePlate', 'op' => 'eq', 'value' => '1-ZTZ-08']],
                    'select' => ['NetMaximumPower'],
                    'groupBy' => [], 'aggregates' => [], 'orderBy' => [],
                    'limit' => 1, 'display' => 'record', 'explanation' => 'lookup',
                ],
                [
                    'id' => 'q2',
                    'dataset' => 'RegisteredVehicles',
                    'where' => [['field' => 'LicensePlate', 'op' => 'eq', 'value' => '{{q1.NetMaximumPower}}']],
                    'select' => [], 'groupBy' => [],
                    'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
                    'orderBy' => [], 'limit' => 1, 'display' => 'count', 'explanation' => 'x',
                ],
            ],
            'presentation' => $this->presentation('q2'),
        ]);

        self::assertCount(2, $program->queries);
    }

    public function test_step_reference_rejects_a_field_missing_from_the_referenced_dataset(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->fromArray([
            'queries' => [
                [
                    'id' => 'q1',
                    'dataset' => 'RegisteredVehicleFuels',
                    'where' => [['field' => 'LicensePlate', 'op' => 'eq', 'value' => '1-ZTZ-08']],
                    'select' => ['NetMaximumPower'],
                    'groupBy' => [], 'aggregates' => [], 'orderBy' => [],
                    'limit' => 1, 'display' => 'record', 'explanation' => 'lookup',
                ],
                [
                    'id' => 'q2',
                    'dataset' => 'RegisteredVehicles',
                    // PrimaryColor lives on RegisteredVehicles, NOT RegisteredVehicleFuels.
                    'where' => [['field' => 'LicensePlate', 'op' => 'eq', 'value' => '{{q1.PrimaryColor}}']],
                    'select' => [], 'groupBy' => [],
                    'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
                    'orderBy' => [], 'limit' => 1, 'display' => 'count', 'explanation' => 'x',
                ],
            ],
            'presentation' => $this->presentation('q2'),
        ]);
    }

    private function factory(): QueryProgramFactory
    {
        return new QueryProgramFactory(new PlanFactory(new SchemaRegistry), new PresentationFactory);
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupQuery(string $id): array
    {
        return [
            'id' => $id,
            'dataset' => 'RegisteredVehicles',
            'where' => [['field' => 'LicensePlate', 'op' => 'eq', 'value' => '1-ZTZ-08']],
            'select' => ['Brand', 'CommercialName'],
            'groupBy' => [], 'aggregates' => [], 'orderBy' => [],
            'limit' => 1, 'display' => 'record', 'explanation' => 'The vehicle.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function countModelQuery(string $id, string $refId = 'q1'): array
    {
        return [
            'id' => $id,
            'dataset' => 'RegisteredVehicles',
            'where' => [
                ['field' => 'Brand', 'op' => 'eq', 'value' => "{{{$refId}.Brand}}"],
                ['field' => 'CommercialName', 'op' => 'eq', 'value' => "{{{$refId}.CommercialName}}"],
            ],
            'select' => [], 'groupBy' => [],
            'aggregates' => [['fn' => 'count', 'field' => '*', 'alias' => 'n']],
            'orderBy' => [], 'limit' => 1, 'display' => 'count', 'explanation' => 'Count of the model.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentation(string $resultRef): array
    {
        return ['resultRef' => $resultRef, 'display' => 'count', 'derive' => null, 'explanation' => 'x'];
    }
}
