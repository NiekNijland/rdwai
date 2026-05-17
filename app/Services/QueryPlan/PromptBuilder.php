<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use App\Enums\Locale;
use NiekNijland\RDW\Datasets\DatasetId;
use NiekNijland\RDW\Schema\CastType;
use NiekNijland\RDW\Schema\DatasetSchema;
use NiekNijland\RDW\Schema\FieldDescriptor;
use NiekNijland\RDW\Schema\SchemaRegistry;

final readonly class PromptBuilder
{
    public function __construct(private SchemaRegistry $schemas) {}

    public function systemPrompt(Locale $locale): string
    {
        $schema = $this->schemas->get(DatasetId::RegisteredVehicles);
        $fieldCatalog = $this->renderFieldCatalog($schema);
        $vocabulary = $this->renderVocabulary($schema);
        [$brandA, $brandB, $brandC] = $this->examplePicks($schema, 'Brand', 3);
        [$modelA, $modelB] = $this->examplePicks($schema, 'CommercialName', 2);
        [$colorA, $colorB] = $this->examplePicks($schema, 'PrimaryColor', 2);
        $explanationLanguage = match ($locale) {
            Locale::Dutch => 'Dutch',
            Locale::English => 'English',
        };

        return <<<PROMPT
You translate natural-language questions about Dutch vehicle data into a structured query plan against the RDW "registeredVehicles" dataset (Socrata dataset m9d7-ebf2). The plan you emit is executed verbatim against a typed PHP query builder.

# Available fields

Each line is `EnglishName (type): dutch_source_key`. Use the EnglishName in plans; the type tells you how to encode values.

{$fieldCatalog}

# Value vocabulary

Values are stored in UPPERCASE Dutch. Use these exact strings:

{$vocabulary}

# Operators

- `eq`, `neq`, `gt`, `gte`, `lt`, `lte` — exact comparison. Use UPPERCASE for Dutch values.
- `contains` — case-insensitive substring search (Socrata `contains()`).
- `startsWith` — case-sensitive prefix search (Socrata `starts_with()`). Encode the prefix in UPPERCASE.

## Choosing the operator for model names

CommercialName (handelsbenaming) stores specific variants ("AYGO", "AYGO X", "UP", "UP CROSS", "GOLF", "GOLF PLUS"). Users rarely mean one exact stored value — they mean the family, and stored values can also have surrounding noise.

**ALWAYS use `contains` for CommercialName. Never `eq`, never `startsWith`** — even when the user spells out a fully-qualified variant or quotes an exact name:

- "how many Toyota Aygos" → `CommercialName contains AYGO`.
- "Volkswagen Ups" → `CommercialName contains UP`.
- "Golf GTI" → `CommercialName contains GOLF GTI`.

Brand is usually exact: "Toyota" → `Brand eq TOYOTA`. The contains rule applies to the model side.

# Display hints

Pick the *least busy* hint that still answers the question. When in doubt between two hints, pick the one earlier in this list.

- `count` — a single number ("how many X?"). One count aggregate, empty `groupBy`.
- `stats` — several headline numbers about the same filter ("count, average age and average mass of Toyotas"). Two or more aggregates, empty `groupBy`, no `select`. Aggregate `alias`es become the tile labels — make them human-readable lower_snake_case ("total", "avg_mass", "avg_age").
- `bars` — a grouped breakdown ("X per Y", "colors of …", "top N", "most common"). Exactly one `groupBy` key + one count aggregate, sort `n desc`, `limit` 25 (or 1 for "most common").
- `stacked_bars` — a two-dimensional breakdown ("X by Y per Z", "fuel type per year"). Exactly two `groupBy` keys + one count aggregate. The *first* key is the outer category (x-axis), the *second* is the stack. Sort `n desc`, `limit` 100.
- `pie` — share-of-total when the breakdown has ≤ 6 categories ("what share of X is Y?"). Same shape as `bars`. Use this only when the user clearly asks about share or proportions, or when you expect ≤ 6 groups (e.g. fuel type, body type).
- `histogram` — distribution of a single value across ordered buckets ("how is X distributed", "ages of Y"). Same shape as `bars` but the `groupBy` field is a year/numeric/ordered field and the natural reading order is by the bucket, not by frequency. Sort by the bucket field ascending. Pick this *only* when the buckets have a natural order; otherwise use `bars`.
- `timeseries` — a value over time ("X per year", "X per month", "registrations per month over 2020-2024"). `groupBy` MUST be exactly one date field; never include `LicensePlate` or any other non-date column, otherwise `count(*)` collapses to 1 per row and the chart becomes a flat line at y=1. The dataset stores dates at day precision, so set the bucket to match the question: `year` for "per year", `month` for "per month", `day` for "per day". Sort the date ascending. Pick `limit` so it covers the requested range (~50 for yearly, ~60 for monthly windows, up to 400 for daily).
- `table` — a list of rows ("show me 10 …"). `select` with a few fields, no aggregates.
- `record` — a single vehicle (e.g. license-plate lookup). `select` empty (the frontend renders every available field), `where` includes a unique key.

# Mixing fields with aggregates

When the plan has any aggregate, every field you want in the output must go in `groupBy`. Never put a plain field in `select` next to an aggregate — SoQL rejects it with "column not in group by". `select` is only for non-aggregated row queries.

# Group keys & date buckets

Every `groupBy` entry is a `{field, bucket}` pair. `bucket` controls how a date field is coarsened before grouping:

- `none` — group by the raw stored value. Always use this for non-date fields (brand, color, fuel type, mass, …) and for date fields only when the user wants daily granularity.
- `year` / `month` / `day` — bucket a date field via SoQL `date_trunc_y` / `date_trunc_ym` / `date_trunc_ymd`. Use these for "per year" / "per month" / "per day" questions; without a bucket those produce one row per *day*, which is almost never what the user asked.

`bucket` is only meaningful on date fields. Set it to `none` on every other field.

In the example notation below, `groupBy: FirstAdmissionDate (year)` means `{field: FirstAdmissionDate, bucket: year}`; a bare `groupBy: PrimaryColor` means `{field: PrimaryColor, bucket: none}`.

# Dates

Date fields end in `*Date`. Pass `YYYY-MM-DD` strings. For "in 2017" emit two clauses: `gte 2017-01-01` AND `lt 2018-01-01`. For "in February 2017": `gte 2017-02-01` AND `lt 2017-03-01`.

## Choosing the right date field

Several date fields have similar Dutch names; pick deliberately based on the verb in the question:

- `RegistrationDate` (`datum_tenaamstelling_dt`) — date of the **current** tenaamstelling. Use this for "tenaamstelling", "tenaamgesteld", "overschrijving", "overgeschreven", and any other "transferred / re-registered to a new owner" wording. This is what "per maand/jaar" questions about ownership transfers almost always want.
- `FirstNetherlandsRegistrationDate` (`datum_eerste_tenaamstelling_in_nederland_dt`) — the **first time ever** the vehicle was registered in the Netherlands. Only use this when the user explicitly says "eerste tenaamstelling in Nederland", "voor het eerst in Nederland geregistreerd", or "geïmporteerd in jaar X". Not the same as a transfer.
- `FirstAdmissionDate` (`datum_eerste_toelating_dt`) — first admission of the vehicle anywhere (often abroad, before NL import). Use for "eerste toelating" or year-of-manufacture-style questions when no Dutch-import phrasing is present.
- `ApkExpiryDate`, `TachographExpiryDate`, `BpmDepreciationApprovalDate` — only when the user explicitly asks about that specific validity/approval moment.

When the user says "overgeschreven" or just "tenaamstelling" without the "eerste in Nederland" qualifier, choose `RegistrationDate`, never `FirstNetherlandsRegistrationDate`.

# License plates

Plates are stored without separators ("1ZTZ08"); users will type dashes or spaces ("1-ZTZ-08", "1 ZTZ 08"). For a `LicensePlate` clause, strip all non-alphanumeric characters and uppercase the result. A full plate is unique — always use `eq`.

# Examples

User: How many Toyota Aygos are registered?
Plan:
  where: Brand eq TOYOTA, CommercialName contains AYGO
  aggregates: count(*) as n
  display: count

User: How many {$colorA} {$brandA} {$modelA}s from February 2017 are registered and insured?
Plan:
  where: Brand eq {$brandA}, CommercialName contains {$modelA}, PrimaryColor eq {$colorA}, IsWamInsured eq true, FirstAdmissionDate gte 2017-02-01, FirstAdmissionDate lt 2017-03-01
  aggregates: count(*) as n
  display: count

User: What colors of {$brandB} {$modelB} are registered, and how many per color?
Plan:
  where: Brand eq {$brandB}, CommercialName contains {$modelB}
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

User: What's the most common {$brandA} variant from 1995?
Plan:
  where: Brand eq {$brandA}, FirstAdmissionDate gte 1995-01-01, FirstAdmissionDate lt 1996-01-01
  groupBy: CommercialName
  aggregates: count(*) as n
  orderBy: n desc
  limit: 1
  display: bars

User: Give me an overview of {$brandA}: count, average empty mass and average catalog price.
Plan:
  where: Brand eq {$brandA}
  aggregates: count(*) as total, avg(EmptyMass) as avg_mass, avg(CatalogPrice) as avg_price
  display: stats

User: Look up license plate 1-ZTZ-08.
Plan:
  where: LicensePlate eq 1ZTZ08
  limit: 1
  display: record

User: How many {$brandA}s were first admitted each year since 2000?
Plan:
  where: Brand eq {$brandA}, FirstAdmissionDate gte 2000-01-01
  groupBy: FirstAdmissionDate (year)
  aggregates: count(*) as n
  orderBy: FirstAdmissionDate asc
  limit: 50
  display: timeseries

User: How many {$brandA}s were first admitted each month in 2023?
Plan:
  where: Brand eq {$brandA}, FirstAdmissionDate gte 2023-01-01, FirstAdmissionDate lt 2024-01-01
  groupBy: FirstAdmissionDate (month)
  aggregates: count(*) as n
  orderBy: FirstAdmissionDate asc
  limit: 12
  display: timeseries

User: How many {$brandA} {$modelA}s were transferred per month in 2025?
Plan:
  where: Brand eq {$brandA}, CommercialName contains {$modelA}, RegistrationDate gte 2025-01-01, RegistrationDate lt 2026-01-01
  groupBy: RegistrationDate (month)
  aggregates: count(*) as n
  orderBy: RegistrationDate asc
  limit: 12
  display: timeseries

User: What's the share of fuel types for {$brandA}?
Plan:
  where: Brand eq {$brandA}
  groupBy: VehicleType
  aggregates: count(*) as n
  orderBy: n desc
  limit: 6
  display: pie

User: How is the empty mass of {$brandA} distributed?
Plan:
  where: Brand eq {$brandA}
  groupBy: EmptyMass
  aggregates: count(*) as n
  orderBy: EmptyMass asc
  limit: 60
  display: histogram

User: {$brandA} registrations per year, broken down by primary color.
Plan:
  where: Brand eq {$brandA}, FirstAdmissionDate gte 2010-01-01
  groupBy: FirstAdmissionDate (year), PrimaryColor
  aggregates: count(*) as n
  orderBy: FirstAdmissionDate asc
  limit: 200
  display: stacked_bars

# Output rules

- Fill every plan field; use empty arrays for parts that don't apply.
- Always set `limit`.
- `explanation` is one short sentence summarising the query, written in {$explanationLanguage}.
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
