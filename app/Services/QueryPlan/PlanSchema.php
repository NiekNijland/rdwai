<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use NiekNijland\RDW\Datasets\DatasetId;
use NiekNijland\RDW\Fields\RegisteredVehicleField;
use NiekNijland\RDW\Schema\CastType;
use NiekNijland\RDW\Schema\DatasetSchema;
use NiekNijland\RDW\Schema\FieldDescriptor;
use NiekNijland\RDW\Schema\SchemaRegistry;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class PlanSchema
{
    public static function build(): ObjectSchema
    {
        $datasetSchema = app(SchemaRegistry::class)->get(DatasetId::RegisteredVehicles);

        $fieldNames = array_map(static fn (RegisteredVehicleField $f): string => $f->name, RegisteredVehicleField::cases());

        $fieldEnum = new EnumSchema(
            name: 'field',
            description: 'English field name on the RegisteredVehicle dataset (PascalCase, e.g. Brand, CommercialName, PrimaryColor).',
            options: $fieldNames,
        );

        // Future optimisation: split the value field into a polymorphic schema
        // keyed on `field` so each field gets a discriminated enum of its known
        // values. Prism doesn't expose a per-property discriminator today and
        // the marginal win over a vocabulary-rich description is small.
        $whereItem = new ObjectSchema(
            name: 'where_clause',
            description: 'A single where predicate against the RegisteredVehicle dataset.',
            properties: [
                $fieldEnum,
                new EnumSchema('op', 'Comparison operator.', array_map(static fn (WhereOp $o): string => $o->value, WhereOp::cases())),
                new StringSchema('value', self::valueDescription($datasetSchema)),
            ],
            requiredFields: ['field', 'op', 'value'],
        );

        $aggregateItem = new ObjectSchema(
            name: 'aggregate_clause',
            description: 'An aggregate function over the grouped result set.',
            properties: [
                new EnumSchema('fn', 'Aggregate function.', array_map(static fn (AggregateFn $f): string => $f->value, AggregateFn::cases())),
                new EnumSchema(
                    name: 'field',
                    description: 'Field to aggregate. For count, pass "*" to count rows.',
                    options: [...$fieldNames, '*'],
                ),
                new StringSchema('alias', 'Result alias used in groupBy/orderBy output, e.g. "n", "total". Must match [A-Za-z_][A-Za-z0-9_]*.'),
            ],
            requiredFields: ['fn', 'field', 'alias'],
        );

        $orderItem = new ObjectSchema(
            name: 'order_clause',
            description: 'A single order-by expression. expr may be a field name (PascalCase) or an aggregate alias.',
            properties: [
                new StringSchema('expr', 'Field name or aggregate alias to sort by.'),
                new EnumSchema('direction', 'Sort direction.', ['asc', 'desc']),
            ],
            requiredFields: ['expr', 'direction'],
        );

        return new ObjectSchema(
            name: 'query_plan',
            description: 'Structured plan that translates a natural-language question into an RDW dataset query.',
            properties: [
                new ArraySchema('where', 'List of filters. Combined with AND.', $whereItem),
                new ArraySchema('select', 'Columns to return when listing rows. Leave empty for count-only or fully-aggregated queries.', new StringSchema('field', 'Field name (PascalCase).')),
                new ArraySchema('groupBy', 'Group by these fields. Combine with aggregates.', new StringSchema('field', 'Field name (PascalCase).')),
                new ArraySchema('aggregates', 'Aggregates to compute. Required if groupBy is non-empty, or for count-only questions.', $aggregateItem),
                new ArraySchema('orderBy', 'Ordering applied after grouping. Reference field names or aggregate aliases.', $orderItem),
                new NumberSchema('limit', 'Maximum rows to return. 1-1000.'),
                new EnumSchema('display', 'How to render the answer.', array_map(static fn (DisplayHint $d): string => $d->value, DisplayHint::cases())),
                new StringSchema('explanation', 'One short Dutch or English sentence summarising what this query answers.'),
            ],
            requiredFields: ['where', 'select', 'groupBy', 'aggregates', 'orderBy', 'limit', 'display', 'explanation'],
        );
    }

    private static function valueDescription(DatasetSchema $schema): string
    {
        $lines = ['Comparison value. For Dutch RDW data use UPPERCASE Dutch values. Booleans as "true"/"false". Dates as YYYY-MM-DD.'];

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
