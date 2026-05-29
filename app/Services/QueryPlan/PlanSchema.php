<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use NiekNijland\RDW\Datasets\DatasetId;
use NiekNijland\RDW\Fields\RegisteredVehicleField;
use NiekNijland\RDW\Fields\RegisteredVehicleFuelField;
use NiekNijland\RDW\Schema\CastType;
use NiekNijland\RDW\Schema\DatasetSchema;
use NiekNijland\RDW\Schema\FieldDescriptor;
use NiekNijland\RDW\Schema\SchemaRegistry;

final class PlanSchema
{
    /**
     * @return array<string, Type>
     */
    public static function build(JsonSchema $schema): array
    {
        $registry = app(SchemaRegistry::class);
        $vehiclesSchema = $registry->get(DatasetId::RegisteredVehicles);

        $fieldNames = array_values(array_unique([
            ...array_map(static fn (RegisteredVehicleField $f): string => $f->name, RegisteredVehicleField::cases()),
            ...array_map(static fn (RegisteredVehicleFuelField $f): string => $f->name, RegisteredVehicleFuelField::cases()),
        ]));

        $fieldDescription = 'English field name (PascalCase). The enum lists every field across both datasets, but each value must belong to the dataset declared on this query — otherwise the server rejects the plan with `Unknown field "X" for dataset Y`.';

        $datasetNames = array_map(static fn (TargetDataset $d): string => $d->value, TargetDataset::cases());

        $whereItem = $schema->object([
            'field' => $schema->string()->enum($fieldNames)->description($fieldDescription)->required(),
            'op' => $schema->string()
                ->enum(array_map(static fn (WhereOp $o): string => $o->value, WhereOp::cases()))
                ->description('Comparison operator.')
                ->required(),
            'value' => $schema->string()->description(self::valueDescription($vehiclesSchema))->required(),
        ]);

        $aggregateItem = $schema->object([
            'fn' => $schema->string()
                ->enum(array_map(static fn (AggregateFn $f): string => $f->value, AggregateFn::cases()))
                ->description('Aggregate function. Use count_distinct for per-vehicle counts on RegisteredVehicleFuels (which has multiple rows per vehicle).')
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
            'dataset' => $schema->string()
                ->enum($datasetNames)
                ->description('Which RDW dataset this query runs against. RegisteredVehicles for general vehicle facts; RegisteredVehicleFuels for engine power (kW), CO2 emissions, fuel consumption, noise, and emission class.')
                ->required(),
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
