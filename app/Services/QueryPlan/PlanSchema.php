<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use NiekNijland\RDW\Datasets\DatasetId;
use NiekNijland\RDW\Fields\RegisteredVehicleField;
use NiekNijland\RDW\Schema\CastType;
use NiekNijland\RDW\Schema\DatasetSchema;
use NiekNijland\RDW\Schema\FieldDescriptor;
use NiekNijland\RDW\Schema\SchemaRegistry;

/**
 * Builds the per-query structured-output schema embedded in
 * {@see QueryProgramSchema} (one entry per program query), expressed with
 * Laravel's {@see JsonSchema} builder.
 *
 * The shape mirrors the typed {@see Plan} that {@see PlanFactory} reconstructs:
 * every property is marked required so the OpenAI strict-schema mode validates
 * the overall shape before {@see PlanFactory} re-validates enum values.
 */
final class PlanSchema
{
    /**
     * @return array<string, Type>
     */
    public static function build(JsonSchema $schema): array
    {
        $datasetSchema = app(SchemaRegistry::class)->get(DatasetId::RegisteredVehicles);

        $fieldNames = array_map(static fn (RegisteredVehicleField $f): string => $f->name, RegisteredVehicleField::cases());

        $fieldDescription = 'English field name on the RegisteredVehicle dataset (PascalCase, e.g. Brand, CommercialName, PrimaryColor).';

        // Future optimisation: split the value field into a polymorphic schema
        // keyed on `field` so each field gets a discriminated enum of its known
        // values. The JSON schema builder doesn't expose a per-property
        // discriminator today and the marginal win over a vocabulary-rich
        // description is small.
        $whereItem = $schema->object([
            'field' => $schema->string()->enum($fieldNames)->description($fieldDescription)->required(),
            'op' => $schema->string()
                ->enum(array_map(static fn (WhereOp $o): string => $o->value, WhereOp::cases()))
                ->description('Comparison operator.')
                ->required(),
            'value' => $schema->string()->description(self::valueDescription($datasetSchema))->required(),
        ]);

        $aggregateItem = $schema->object([
            'fn' => $schema->string()
                ->enum(array_map(static fn (AggregateFn $f): string => $f->value, AggregateFn::cases()))
                ->description('Aggregate function.')
                ->required(),
            'field' => $schema->string()
                ->enum([...$fieldNames, '*'])
                ->description('Field to aggregate. For count, pass "*" to count rows.')
                ->required(),
            'alias' => $schema->string()
                ->description('Result alias used in groupBy/orderBy output, e.g. "n", "total". Must match [A-Za-z_][A-Za-z0-9_]*.')
                ->required(),
        ]);

        $orderItem = $schema->object([
            'expr' => $schema->string()->description('Field name or aggregate alias to sort by.')->required(),
            'direction' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction.')->required(),
        ]);

        $groupItem = $schema->object([
            'field' => $schema->string()->enum($fieldNames)->description($fieldDescription)->required(),
            'bucket' => $schema->string()
                ->enum(array_map(static fn (Bucket $b): string => $b->value, Bucket::cases()))
                ->description('Date truncation granularity. Use year/month/day only on date fields; use none for every other field.')
                ->required(),
        ]);

        return [
            'where' => $schema->array()
                ->description('List of filters. Combined with AND.')
                ->items($whereItem)
                ->required(),
            'select' => $schema->array()
                ->description('Columns to return when listing rows. Leave empty for count-only or fully-aggregated queries.')
                ->items($schema->string()->description('Field name (PascalCase).'))
                ->required(),
            'groupBy' => $schema->array()
                ->description('Group by these keys. Each key is a field plus a bucket (none for raw value; year/month/day to truncate a date field).')
                ->items($groupItem)
                ->required(),
            'aggregates' => $schema->array()
                ->description('Aggregates to compute. Required if groupBy is non-empty, or for count-only questions.')
                ->items($aggregateItem)
                ->required(),
            'orderBy' => $schema->array()
                ->description('Ordering applied after grouping. Reference field names or aggregate aliases.')
                ->items($orderItem)
                ->required(),
            'limit' => $schema->integer()
                ->nullable()
                ->description('Row cap, or null for no cap. Set a number ONLY when the answer is a bounded set of rows: a fixed-size row list (table), a single record (1), or an explicit top-N ranking (bars — 1 for "most common", otherwise the N asked for, default 25). Leave null for every complete breakdown (timeseries, histogram, stacked_bars, pie) and for count/stats — a cap there silently drops rows, so a timeseries would lose its most recent periods and a share/pie its long tail. Max 1000; RDW already returns at most 1000 rows when null.')
                ->required(),
            'display' => $schema->string()
                ->enum(array_map(static fn (DisplayHint $d): string => $d->value, DisplayHint::cases()))
                ->description('How to render the answer.')
                ->required(),
            'explanation' => $schema->string()
                ->description('One short sentence summarising what this query answers, written in the language specified by the system prompt.')
                ->required(),
        ];
    }

    private static function valueDescription(DatasetSchema $schema): string
    {
        $lines = ['Comparison value. String comparisons are case-sensitive and casing differs per field (e.g. "Personenauto" but "GEEL", "TOYOTA") — copy the exact casing listed below. Booleans as "true"/"false". Dates as YYYY-MM-DD.'];

        foreach ($schema->fieldsWithVocabulary() as $field) {
            $vocabulary = $field->vocabulary;
            if ($vocabulary === null) {
                continue;
            }
            $values = implode(', ', $vocabulary->values);
            $lines[] = $vocabulary->exhaustive
                ? sprintf('%s: one of %s', $field->enumCase, $values)
                : sprintf('%s (examples — field is open): %s', $field->enumCase, $values);
        }

        $booleanFields = array_values(array_filter(
            $schema->exposedFields(),
            static fn (FieldDescriptor $f): bool => $f->cast === CastType::Boolean,
        ));
        if ($booleanFields !== []) {
            $names = implode(', ', array_map(static fn (FieldDescriptor $f): string => $f->enumCase, $booleanFields));
            $lines[] = sprintf('Boolean fields (%s): "true" or "false".', $names);
        }

        return implode(' ', $lines);
    }
}
