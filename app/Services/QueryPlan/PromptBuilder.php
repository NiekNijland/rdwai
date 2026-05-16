<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use NiekNijland\RDW\Datasets\DatasetId;
use NiekNijland\RDW\Schema\CastType;
use NiekNijland\RDW\Schema\DatasetSchema;
use NiekNijland\RDW\Schema\FieldDescriptor;
use NiekNijland\RDW\Schema\SchemaRegistry;

final readonly class PromptBuilder
{
    public function __construct(private SchemaRegistry $schemas)
    {
    }

    public function systemPrompt(): string
    {
        $schema = $this->schemas->get(DatasetId::RegisteredVehicles);
        $fieldCatalog = $this->renderFieldCatalog($schema);
        $vocabulary = $this->renderVocabulary($schema);
        [$brandA, $brandB, $brandC] = $this->examplePicks($schema, 'Brand', 3);
        [$modelA, $modelB] = $this->examplePicks($schema, 'CommercialName', 2);
        [$colorA, $colorB] = $this->examplePicks($schema, 'PrimaryColor', 2);

        return <<<PROMPT
You translate natural-language questions about Dutch vehicle data into a structured query plan against the RDW "registeredVehicles" dataset (Socrata dataset m9d7-ebf2). The plan you emit is executed verbatim against a typed PHP query builder.

# Available fields

Use the English PascalCase name (left of the colon). The type tells you how to encode values:

{$fieldCatalog}

# Value vocabulary

The dataset is in Dutch and stores values in UPPERCASE. Use these exact strings:

{$vocabulary}

# Operator semantics

- eq, neq, gt, gte, lt, lte: exact comparison. Use UPPERCASE for the Dutch values.
- contains: case-insensitive substring search (Socrata contains()).
- startsWith: case-sensitive prefix search (Socrata starts_with()).

# How to choose a display hint

- "count" → a single number (e.g. "how many X are registered?"). Use one count aggregate, empty groupBy.
- "bars" → a grouped breakdown (e.g. "X per Y", "colors of …"). Use exactly one groupBy + one count aggregate, sort the aggregate desc, limit 25.
- "table" → a list of rows (e.g. "show me 10 …"). Use select with a few fields, no aggregates.
- "record" → a single vehicle (e.g. a license-plate lookup).

# Date handling

Date fields end in *Date. Pass values as YYYY-MM-DD strings. For "in 2017" use two clauses: gte 2017-01-01 AND lt 2018-01-01. For "in February 2017" use gte 2017-02-01 AND lt 2017-03-01.

# Examples

User: How many {$colorA} {$brandA} {$modelA}s from February 2017 are registered and insured?
Plan:
  where: Brand eq {$brandA}, CommercialName eq {$modelA}, PrimaryColor eq {$colorA}, IsWamInsured eq true, FirstAdmissionDate gte 2017-02-01, FirstAdmissionDate lt 2017-03-01
  aggregates: count(*) as n
  display: count

User: What colors of {$brandB} {$modelB} are registered, and how many per color?
Plan:
  where: Brand eq {$brandB}, CommercialName eq {$modelB}
  groupBy: PrimaryColor
  aggregates: count(*) as n
  orderBy: n desc
  limit: 25
  display: bars

User: Show me 10 {$colorB} {$brandC}s with their license plate, model and registration date
Plan:
  where: Brand eq {$brandC}, PrimaryColor eq {$colorB}
  select: LicensePlate, CommercialName, RegistrationDate
  orderBy: RegistrationDate desc
  limit: 10
  display: table

Always fill every plan field; use empty arrays for parts that don't apply. Always set limit. The explanation field must summarise the query in one sentence.
PROMPT;
    }

    private function renderFieldCatalog(DatasetSchema $schema): string
    {
        $lines = [];
        foreach ($schema->byEnumCase as $name => $descriptor) {
            $lines[] = sprintf('- %s (%s): %s', $name, $descriptor->cast->value, $descriptor->rdwKey);
        }

        return implode("\n", $lines);
    }

    /**
     * Pick the first $count values from a field's vocabulary, padding with the
     * first value when the vocabulary is shorter than requested. Returns a
     * fixed-length list so destructuring at the call site stays total.
     *
     * @return list<string>
     */
    private function examplePicks(DatasetSchema $schema, string $enumCase, int $count): array
    {
        $descriptor = $schema->byEnumCase[$enumCase] ?? null;
        $values = $descriptor !== null && $descriptor->vocabulary !== null
            ? $descriptor->vocabulary->values
            : [];

        if ($values === []) {
            return array_fill(0, $count, $enumCase);
        }

        $picks = array_slice($values, 0, $count);
        while (count($picks) < $count) {
            $picks[] = $values[0];
        }

        return $picks;
    }

    private function renderVocabulary(DatasetSchema $schema): string
    {
        $lines = [];
        foreach ($schema->fieldsWithVocabulary() as $field) {
            $vocabulary = $field->vocabulary;
            if ($vocabulary === null) {
                continue;
            }
            $values = implode(', ', $vocabulary->values);
            $lines[] = $vocabulary->exhaustive
                ? sprintf('- %s: one of %s', $field->enumCase, $values)
                : sprintf('- %s (examples — field is open): %s', $field->enumCase, $values);
        }

        $booleanFields = array_values(array_filter(
            $schema->exposedFields(),
            static fn (FieldDescriptor $f): bool => $f->cast === CastType::Boolean,
        ));
        if ($booleanFields !== []) {
            $names = implode(', ', array_map(static fn (FieldDescriptor $f): string => $f->enumCase, $booleanFields));
            $lines[] = sprintf('- boolean fields (%s): write "true" or "false" as the string value', $names);
        }

        return implode("\n", $lines);
    }
}
