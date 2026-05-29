<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueryPlan;

use App\Services\QueryPlan\QueryProgramSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use PHPUnit\Framework\TestCase;

final class QueryProgramSchemaTest extends TestCase
{
    public function test_builds_a_program_schema_with_queries_and_presentation(): void
    {
        $built = QueryProgramSchema::build(new JsonSchemaTypeFactory());

        self::assertSame(['queries', 'presentation'], array_keys($built));
    }

    public function test_serialises_to_a_json_schema_without_error(): void
    {
        $built = QueryProgramSchema::build(new JsonSchemaTypeFactory());

        $schema = (new ObjectSchema($built))->toSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('queries', $schema['properties']);
        self::assertArrayHasKey('presentation', $schema['properties']);
        self::assertSame('array', $schema['properties']['queries']['type']);
        self::assertArrayHasKey('id', $schema['properties']['queries']['items']['properties']);
        self::assertArrayHasKey('derive', $schema['properties']['presentation']['properties']);
    }

    public function test_query_limit_is_nullable_so_breakdowns_can_opt_out_of_a_row_cap(): void
    {
        $built = QueryProgramSchema::build(new JsonSchemaTypeFactory());

        $schema = (new ObjectSchema($built))->toSchema();
        $limit = $schema['properties']['queries']['items']['properties']['limit'];

        // Nullable yet required, so the model can pass null on a complete breakdown.
        self::assertSame(['integer', 'null'], $limit['type']);
        self::assertContains('limit', $schema['properties']['queries']['items']['required']);
    }

    public function test_query_dataset_is_a_required_enum_listing_both_targets(): void
    {
        $built = QueryProgramSchema::build(new JsonSchemaTypeFactory());

        $schema = (new ObjectSchema($built))->toSchema();
        $dataset = $schema['properties']['queries']['items']['properties']['dataset'];

        // Both addressable datasets must surface to the LLM so it can pick the fuels dataset for
        // engine power / CO2 / fuel consumption questions.
        self::assertSame('string', $dataset['type']);
        self::assertContains('RegisteredVehicles', $dataset['enum']);
        self::assertContains('RegisteredVehicleFuels', $dataset['enum']);
        self::assertContains('dataset', $schema['properties']['queries']['items']['required']);
    }
}
