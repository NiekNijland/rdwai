# Multi-dataset query targeting

## Context

A user asked *"hoeveel procent van de autos heeft meer dan 150 kw vermogen?"* and got a misleading **0%**. Three layered causes:

1. **Decimal `where` values were quoted as strings** — already fixed in `PlanRunner::castValue` (decimals cast to `float`, emitted unquoted) with a `PlanRunnerTest` lock-in. This fix stays.
2. **The kW power field doesn't exist in `RegisteredVehicles`** — absolute power (`nettomaximumvermogen`, "in kW") lives in `RegisteredVehicleFuels` (`8ys7-d773`), and this app's whole query pipeline is pinned to `RegisteredVehicles` (`m9d7-ebf2`).
3. **Even reaching that field, Socrata stores it as `text`** — every numeric column in `8ys7-d773` (`dataTypeName: text`: power, CO2, fuel consumption, noise, particulates). So `nettomaximumvermogen > 150`, even unquoted, is a lexicographic compare. The decimal fix in (1) is not enough on its own; the SoQL must wrap the column in `to_number(...)`.

This change adds **per-query dataset targeting** plus the `to_number` wrap so the kW question actually works. Cross-dataset percentages reuse the existing two-scalar-query + `percentage` derive (no SQL JOIN; Socrata doesn't do them across resources).

## Scope

**In (Phase 1)**: expose two datasets to the LLM — `RegisteredVehicles` and `RegisteredVehicleFuels`. Each query in a program picks its `dataset`. Cross-dataset comparisons go through a two-query program with a `percentage`/`ratio` derive.

**Out**:
- The other 8 sub-datasets the RDW package supports — additive, mechanical.
- A real cross-resource JOIN, or single-query filters mixing fields from both datasets (e.g. *"Toyotas over 150 kW"*). Refuse with `display: unsupported`. Lifting this later means extending `StepReference` to list-valued lookups + `whereIn`, not adding JOINs.

## Design

### 1. `TargetDataset` enum on each `Plan`

`app/Services/QueryPlan/TargetDataset.php` — backed enum, value = PascalCase string, with a `datasetId(): DatasetId` accessor:

```
RegisteredVehicles      → DatasetId::RegisteredVehicles      (m9d7-ebf2)
RegisteredVehicleFuels  → DatasetId::RegisteredVehicleFuels  (8ys7-d773)
```

Add `dataset: TargetDataset` to `app/Services/QueryPlan/Plan.php`. Default `RegisteredVehicles` (so refusal plans and serialised pre-change `QueryRun` rows keep working).

### 2. Dataset-aware field lookup

Replace `app/Services/QueryPlan/RegisteredVehicleFieldLookup.php` with `App\Services\QueryPlan\FieldLookup::tryGet(TargetDataset $dataset, string $name): ?BackedEnum` — a static facade with a per-dataset memoised map, internally dispatching to:

- `RegisteredVehicles` → `NiekNijland\RDW\Fields\RegisteredVehicleField`
- `RegisteredVehicleFuels` → `NiekNijland\RDW\Fields\RegisteredVehicleFuelField`

### 3. Sites that currently hardcode `DatasetId::RegisteredVehicles`

All become dataset-driven. Sweep covers:

- `app/Services/QueryPlan/PlanFactory.php` — lines 127, 163, 276 (each call `$this->schemas->get(DatasetId::RegisteredVehicles)`); `assertFieldExists` (line 318) uses `FieldLookup::for($plan_dataset)`. The error string `Unknown RegisteredVehicleField "%s"` becomes generic `Unknown field "%s" for dataset %s`.
- `app/Services/QueryPlan/PlanRunner.php` — `match($plan->dataset)` picks `registeredVehicles()` vs `registeredVehicleFuels()` on `Rdw` (both already exist). `castValue`, `schema()`, `buildBucketsByField`, `buildRequestUrl`, `cacheKey` all read `$plan->dataset`. `normalisedContainsExpression` signature widens from `RegisteredVehicleField` to `BackedEnum`. `castValue`'s plate short-circuit checks the *active dataset's* `LicensePlate` enum case.
- `app/Services/QueryPlan/QueryProgramFactory.php` — line 51's `assertReferencesPointBackward` takes an `(id → TargetDataset)` map of earlier queries; the reference's field is validated against the **referenced** query's dataset (line 102), not the current one. Today this passes by accident because `LicensePlate` exists in both enums.
- `app/Services/QueryPlan/PlanSchema.php` — each query gets a `dataset` enum property; `field` enums become the **union** across both datasets. Per-dataset validity is enforced server-side in `PlanFactory` (we already throw on unknown fields). Reason: `Illuminate\JsonSchema` exposes no `oneOf`/`anyOf`/`if`/`then`, so a discriminated union isn't expressible without bypassing the framework. The `fieldDescription` literal (currently "RegisteredVehicle dataset … Brand, CommercialName, PrimaryColor") is generalised.
- `app/Services/QueryPlan/PlanPresenter.php` — `toArray` includes `dataset`; `normalisePersisted` defaults a missing field to `RegisteredVehicles` for old `QueryRun` rows.

### 4. Numeric comparisons against `text` columns — `to_number()` wrap

Most fuel-dataset numeric columns are stored as Socrata `text` (verified for `nettomaximumvermogen`, `nominaal_continu_maximumvermogen`, `co2_uitstoot_*`, `brandstofverbruik_*`, `geluidsniveau_*`, `uitstoot_deeltjes_*`, `roetuitstoot`, `toerental_geluidsniveau`). Without intervention, `gt`/`gte`/`lt`/`lte`/`eq` on these performs lexicographic compare.

Approach: extend the local `FieldDescriptor`-equivalent (or piggyback on the schema metadata `dataTypeName`) so each field exposes its underlying Socrata column type. In `PlanRunner::applyWhere`, when the cast is `Decimal`/`Integer` and the underlying Socrata type is `text`, emit via `whereRaw("to_number(<col>) <op> <value>")` rather than `where(...)`. For columns already stored as `number` (all numeric m9d7-ebf2 fields), keep the existing path. The vendor package's metadata snapshots already carry `dataTypeName` per column — surface it through `SchemaRegistry`/`FieldDescriptor`; if that requires a vendor change, mirror the small "text-stored numeric" allowlist in-app for now and TODO the upstream PR.

### 5. `AggregateFn::CountDistinct`

`QueryBuilder::countDistinct` already exists. Add `AggregateFn::CountDistinct = 'count_distinct'`; wire it in `PlanRunner::applyAggregates` (one match arm calling `$builder->countDistinct(...)`); document it in the prompt. This replaces the brittle `SequenceNumber eq 1` workaround — `brandstof_volgnummer` is a *display order*, not a guarantee that row 1 carries the primary engine power (plug-in hybrid combustion engines can sit at sequence 2; BEVs may have a single row with no kW figure).

### 6. `PromptBuilder` updates

`app/Services/QueryPlan/PromptBuilder.php`:

- Header: a query program targets one **or more** RDW datasets. Each query has a `dataset` field; current values are `RegisteredVehicles` (main vehicle facts) and `RegisteredVehicleFuels` (fuel/emissions/**absolute engine power in kW**).
- Render **two** field catalogs in "Available fields", one per dataset, each via the existing `renderFieldCatalog` helper, prefaced by a one-liner about what the dataset covers. Fuel catalog is ~25 lines — acceptable.
- Dataset-selection section: pick `RegisteredVehicleFuels` for absolute engine power (kW/pk), CO2 emissions, fuel consumption, noise level, emission class. Otherwise `RegisteredVehicles`.
- `RegisteredVehicleFuels` granularity: one row per (vehicle, fuel sequence) — hybrids have multiple rows. **Use `count_distinct(LicensePlate)` for per-vehicle counts.**
- `RegisteredVehicleFuels` has **no date fields** → never pick `timeseries` against it.
- Worked example for the kW question:
  ```
  q1 (dataset: RegisteredVehicleFuels):
    where NetMaximumPower gt 150
    aggregates count_distinct(LicensePlate) as n; limit null; display count
  q2 (dataset: RegisteredVehicles):
    aggregates count(*) as n; limit null; display count
  presentation: resultRef "derived"; display count;
    derive percentage(numerator q1.n, denominator q2.n)
  ```
- Cross-dataset filters in one query (e.g. *"Toyotas over 150 kW"*): refuse (`display: unsupported`). One sentence on why: Phase 1 doesn't yet support list-valued step references.
- **Remove** the interim "Fields that look like something they aren't" note about `PowerToReadyMassRatio` added earlier. The positive guidance (kW → fuel dataset) replaces it.

### 7. Frontend

- `resources/js/pages/query/types.ts` — `Plan` gets `dataset: 'RegisteredVehicles' | 'RegisteredVehicleFuels'`.
- `resources/js/pages/query/examples.ts` — flip the "single RegisteredVehicles dataset" comment, add at least one kW/CO2 prompt to `SUGGESTIONS_NL`/`SUGGESTIONS_EN` so the new capability is discoverable.
- The per-step URL displayed in the UI comes from `RunnerResult::url`, which already encodes the dataset — no rendering change.

### 8. Tests

Bulk pattern: existing tests construct `Plan` literals inline; they pass `dataset: TargetDataset::RegisteredVehicles` explicitly (uniform edit across `PlanFactoryTest`, `PlanRunnerTest`, `DerivationTest`, and any `tests/Feature/...` plan literal).

New focused tests:

- `PlanFactoryTest`: parses `dataset: RegisteredVehicleFuels`, resolves `NetMaximumPower`; rejects `Brand` against a fuels plan with `Unknown field "Brand" for dataset RegisteredVehicleFuels`; `isDateField` uses the plan's dataset.
- `PlanRunnerTest`:
  - dispatches to the fuels builder; the resulting `RunnerResult::url` contains `/resource/8ys7-d773.json`.
  - `gt` against a text-stored Decimal column (`NetMaximumPower`) emits `$where = to_number(nettomaximumvermogen) > 150`.
  - `gt` against a number-stored Decimal column (`CatalogPrice`) still emits `catalogusprijs > 50000` (no `to_number`), so the existing lock-in test stays green.
  - cache key differs when two otherwise-identical plans target different datasets.
- `QueryProgramFactoryTest`: a fuels q1 selecting `NetMaximumPower` referenced by `{{q1.NetMaximumPower}}` in a vehicles q2 validates against the **fuels** lookup (accepts), not the vehicles one (which would falsely reject).
- `PromptBuilderTest`: prompt contains `RegisteredVehicleFuels`, `NetMaximumPower`, the `count_distinct(LicensePlate)` guidance, the "no timeseries on fuels" note, and the worked kW example.
- `PlanPresenterTest`: `toArray` carries `dataset`; `normalisePersisted` defaults missing `dataset` to `RegisteredVehicles` on pre-change documents.

### 9. Reused / unchanged

- `Derivation::percentage` and `RunNaturalLanguageQuery::computeDerived` already combine two scalar queries — no change.
- `QueryProgram`, `ProgramQuery`, `Presentation`, `StepReferenceResolver` — structurally unchanged; dataset lives inside each `Plan`.
- Cache TTL/store untouched. Old cache entries are orphaned by the new key prefix — acceptable, the keys also rotate daily.

## Verification

Manual:
- `vendor/bin/sail composer run dev`. Ask the original Dutch question. Program should show q1 → 8ys7-d773 with `to_number(nettomaximumvermogen) > 150` and `count_distinct(kenteken)`, q2 → m9d7-ebf2 with `count(*)`, and a believable non-zero percentage.
- *"hoeveel auto's hebben meer dan 200 g/km CO2 uitstoot?"* — routes to fuels with `to_number`.
- *"hoeveel Toyotas hebben meer dan 150 kW vermogen?"* — refuses with the cross-dataset explanation.
- *"hoeveel rode auto's zijn er?"* — RegisteredVehicles only; no regression.

Automated:
- `vendor/bin/sail artisan test --compact tests/Unit/Services/QueryPlan` then the full suite.
- `vendor/bin/sail bin pint --dirty --format agent`.
- `vendor/bin/sail composer phpstan` (or the equivalent script — `phpstan.neon.dist` is present).
