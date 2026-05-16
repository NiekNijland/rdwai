<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use NiekNijland\RDW\Datasets\DatasetId;
use NiekNijland\RDW\Schema\SchemaRegistry;

final readonly class PromptBuilder
{
    public function __construct(private SchemaRegistry $schemas) {}

    public function systemPrompt(): string
    {
        $schema = $this->schemas->get(DatasetId::RegisteredVehicles);
        $fieldLines = [];
        foreach ($schema->byEnumCase as $name => $descriptor) {
            $fieldLines[] = sprintf('- %s (%s): %s', $name, $descriptor->cast->value, $descriptor->rdwKey);
        }
        $fieldCatalog = implode("\n", $fieldLines);

        return <<<PROMPT
You translate natural-language questions about Dutch vehicle data into a structured query plan against the RDW "registeredVehicles" dataset (Socrata dataset m9d7-ebf2). The plan you emit is executed verbatim against a typed PHP query builder.

# Available fields

Use the English PascalCase name (left of the colon). The type tells you how to encode values:

{$fieldCatalog}

# Value vocabulary

The dataset is in Dutch and stores values in UPPERCASE. Use these exact strings:

- Brand: VOLKSWAGEN, TOYOTA, OPEL, FORD, RENAULT, PEUGEOT, BMW, MERCEDES-BENZ, AUDI, KIA, HYUNDAI, FIAT, VOLVO, SKODA, SEAT, NISSAN, CITROEN, MAZDA, HONDA, MINI, TESLA
- CommercialName (model): POLO, GOLF, UP, PASSAT, TIGUAN, AYGO, YARIS, COROLLA, CORSA, ASTRA, CLIO, MEGANE, 208, 308, 3, 5, A1, A3, A4
- PrimaryColor / SecondaryColor: WIT, ZWART, GRIJS, BLAUW, ROOD, GROEN, GEEL, BRUIN, BEIGE, PAARS, ORANJE, ZILVER, GOUD, ROZE
- VehicleType: Personenauto, Bedrijfsauto, Motorfiets, Bromfiets, Aanhangwagen
- boolean fields: write "true" or "false" as the string value

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

User: How many white Volkswagen Ups from February 2017 are registered and insured?
Plan:
  where: Brand eq VOLKSWAGEN, CommercialName eq UP, PrimaryColor eq WIT, IsWamInsured eq true, FirstAdmissionDate gte 2017-02-01, FirstAdmissionDate lt 2017-03-01
  aggregates: count(*) as n
  display: count

User: What colors of Toyota Aygo are registered, and how many per color?
Plan:
  where: Brand eq TOYOTA, CommercialName eq AYGO
  groupBy: PrimaryColor
  aggregates: count(*) as n
  orderBy: n desc
  limit: 25
  display: bars

User: Show me 10 red BMWs with their license plate, model and registration date
Plan:
  where: Brand eq BMW, PrimaryColor eq ROOD
  select: LicensePlate, CommercialName, RegistrationDate
  orderBy: RegistrationDate desc
  limit: 10
  display: table

Always fill every plan field; use empty arrays for parts that don't apply. Always set limit. The explanation field must summarise the query in one sentence.
PROMPT;
    }
}
