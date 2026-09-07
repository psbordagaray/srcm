# Straleon — Transformation / Yield / Traceability Architecture V1

**Status:** CANONICAL DOMAIN DESIGN
**Current functional lane:** P13.B
**Immediate next runtime cut after architecture publication:** `P13_B_TRANSFORMATION_YIELD_TRACEABILITY_FOUNDATION_V1`
**Runtime authorization in this document:** NONE

---

## 1. Evidence baseline

The read-only RECON executed against:

- branch: `feature/core-entity`
- HEAD: `1c46f4cc6327952477a678e957da4a7b4ae051a5`
- worktree: clean
- staging: empty
- remote: exact HEAD

RECON evidence:

```text
SCAN_TRACKED_FILES=1640
MOVEMENT_FILES=17
LOT_SERIAL_FILES=0
FRACTIONAL_FILES=15
COMMERCIAL_INTENT_FILES=10
TRANSFORMATION_NAMED_FILES=0
TRANSFORMATION_YIELD_RUNTIME_PRESENT=NO
RESULT_SHA256=8d4fb76b9b180ab727b21022e3b1b1a327b32c63c5a481ef8f48a7aaf3095e5a
```

Decision:

`P13_B_TRANSFORMATION_YIELD_TRACEABILITY_FOUNDATION_V1` may be designed without risk of duplicating existing Transformation/Yield runtime.

---

## 2. Why this domain exists

Many physical businesses do more than buy and resell unchanged units.

Examples:

- bakery: raw ingredients → baked products;
- butcher: larger piece → multiple sellable cuts + trim/loss;
- food preparation: ingredients → prepared output;
- recovery/reconditioning: material → recovered output + discard;
- light manufacturing: components/material → finished output + by-products.

Straleon must represent what material became what **without creating another stock authority**.

The foundation is therefore a **lineage/evidence aggregate coordinated with the inventory movement ledger**.

---

## 3. Domain boundary

### Single-truth interpretation

Transformation/Yield/Traceability is governed by the **STRALEON SINGLE-TRUTH PRINCIPLE**.

There is no conflict in having both `InventoryMovement` and `InventoryTransformation`, because they own **different facts**:

- confirmed `InventoryMovement` is the canonical authority for the fact that physical inventory changed;
- `InventoryTransformation` is the canonical authority for the lineage fact that specific confirmed physical inputs became/produced specific confirmed physical outputs;
- yield/read models are derived from that lineage and never become a mutable competing authority for either stock or lineage.

If a future Transformation implementation starts storing an independently mutable stock balance, independently mutable movement quantity, or independently mutable yield as if it were physical truth, it violates this architecture.

### TYT-001 — Transformation is not stock

A transformation record does not add/subtract inventory.

All stock effects remain represented by confirmed `InventoryMovement` lines.

### TYT-002 — Transformation links exact stock facts

Transformation lineage binds exact confirmed movement lines as inputs/outputs.

### TYT-003 — Yield is derived evidence

Yield is calculated from compatible linked quantities. A mutable `yield_percentage` is not authoritative truth.

### TYT-004 — Strongest available provenance

Transformation preserves the strongest traceability identity available today and remains extensible to future lot/serial capabilities.

### TYT-005 — Completed evidence first

Foundation V1 records completed transformation evidence. It is not a production planning/MRP/work-order engine.

---

## 4. Transformation vs neighboring concepts

Use Transformation only when product/material identity or composition meaningfully changes.

### Transformation examples

```text
10 kg flour + ingredients
    → 14 kg bread output
```

```text
20 kg primal cut
    → 12 kg retail cut A
    → 6 kg retail cut B
    → 1 kg recoverable trim
    → residual loss
```

### NOT Transformation by default

#### Variable quantity fulfillment

Customer requested approximately 1 kg and actual prepared quantity is 1.086 kg of the same product.

Use `VariableQuantityFulfillment`.

#### Fractional container consumption

A 20 L container is opened/consumed as the same product.

Use fractional-container provenance/consumption.

#### Transfer

Same material moved from warehouse to shelf.

Use `InventoryMovement::Transfer`.

#### Ordinary picking

Selecting exact stock for an order does not create a transformation.

This separation avoids using one domain as a universal “something happened to stock” bucket.

---

## 5. Canonical stock semantics

The target ledger should express transformation stock effects explicitly.

### Preferred target movement semantics

Introduce two explicit movement meanings at the implementation boundary after auditing every enum consumer:

- `TransformationInput` — source-only inventory consumption;
- `TransformationOutput` — destination-only inventory production.

They should follow the same directional stock semantics already understood by the projector for source-only and destination-only movement types.

Why explicit types are preferred over generic `Issue` / `Receipt`:

- better ledger auditability;
- clearer correction/rebuild semantics;
- easier traceability queries;
- no ambiguity between sale/service/general issue and transformation consumption;
- no ambiguity between supplier/adjustment/general receipt and transformation production.

### Implementation safety requirement

Before adding enum cases, Foundation V1 MUST inspect exhaustive `match`/switch consumers of `InventoryMovementType`.

If adding the explicit cases is not local/safe, implementation must FAIL_CLOSED and perform a targeted compatibility adjustment. It must not silently overload unrelated movement meaning without an ADR.

---

## 6. Aggregate model

The recommended Foundation V1 model is deliberately small.

### `InventoryTransformation`

Completed immutable evidence.

Conceptual fields:

```text
id
organization_id
public_id
effective_at
created_by_user_id
idempotency_key
fingerprint
supersedes_transformation_id?   // future/additive correction semantics
metadata?                       // minimal, controlled
created_at
updated_at
```

Foundation V1 may defer `supersedes_transformation_id` if correction semantics are tested through a later additive cut, but the architecture reserves the concept.

### `InventoryTransformationLineage`

Exact link to immutable movement lines.

Conceptual fields:

```text
id
organization_id
inventory_transformation_id
inventory_movement_line_id
direction             // input | output
output_role?          // null for input; primary/byproduct/recovered/etc for output
sequence
created_at
```

The linked `InventoryMovementLine` already preserves:

- product;
- condition;
- source/destination location;
- entered quantity/unit;
- conversion factor;
- base quantity/unit;
- parent movement;
- organization.

The lineage table should not copy authoritative stock quantities merely for convenience.

---

## 7. Output roles

A stock-producing output may be classified as:

- `primary`
- `co_product`
- `byproduct`
- `recovered`
- `scrap` when scrap itself remains tracked inventory

Roles describe lineage/business meaning. They do not change ledger direction.

### Waste / discard

Two cases must stay distinct:

#### Tracked waste/scrap

If waste remains a tracked material with inventory value/quantity, it is an output movement and may use `scrap`/appropriate role.

#### Non-stock physical loss

If material is destroyed, evaporated, trimmed away or otherwise not entering inventory, Foundation V1 should initially derive comparable residual loss where valid rather than invent a fake product.

A later immutable measurement evidence type may record non-stock waste explicitly when operational demand justifies it.

This avoids fake “waste products” and avoids a second stock ledger.

---

## 8. Foundation V1 invariants

A completed transformation is valid only when:

1. the actor has an active organization;
2. actor capability/role is authorized;
3. all records belong to the same organization;
4. there is at least one input movement line;
5. there is at least one output movement line;
6. every linked movement is confirmed;
7. every input line belongs to a source-only transformation-input movement;
8. every output line belongs to a destination-only transformation-output movement;
9. no movement line is duplicated inside the same transformation;
10. the same line cannot be both input and output;
11. output roles are valid;
12. idempotency key is valid and organization-scoped;
13. deterministic fingerprint covers the exact ordered lineage intent;
14. same key + same fingerprint returns the same evidence;
15. same key + different fingerprint fails;
16. completed evidence is immutable;
17. physical deletion of completed evidence is forbidden;
18. recording evidence does not mutate inventory balances;
19. base quantities/units are read from linked confirmed movement lines;
20. public/network publication is not automatic.

---

## 9. Transaction / locking boundary

The manager should follow established Straleon patterns:

```text
derive actor organization
    ↓
normalize + validate idempotency
    ↓
build deterministic intent fingerprint
    ↓
DB transaction
    ↓
lock active organization
    ↓
guard actor/membership
    ↓
exact idempotency lookup
    ↓
lock referenced movements/lines in deterministic order
    ↓
validate confirmed + organization + direction
    ↓
create immutable transformation + lineage
```

Locks should be acquired in deterministic identifier order to reduce deadlock risk.

The manager does NOT confirm inventory movements itself in Foundation V1 unless a later coordinated command is explicitly designed. It records completed evidence against already-confirmed ledger facts.

---

## 10. Fingerprint design

The fingerprint should include a canonical ordered representation of:

- organization;
- effective timestamp if semantically part of intent;
- ordered input line IDs;
- ordered output line IDs + output roles;
- any transformation classification that affects meaning.

It should NOT include mutable display labels.

Canonicalization rules:

- sort identifiers deterministically unless user-provided sequence is meaningful and persisted;
- normalize enum/token values;
- use stable delimiter/JSON canonical form;
- SHA-256 consistent with current Straleon evidence patterns.

---

## 11. Yield semantics

### TYT-006 — There is no universal yield percentage

It is invalid to sum unrelated dimensions.

Examples of invalid aggregation:

```text
5 kg input + 3 units input
```

or:

```text
10 L input → 8 kg output
```

without an explicit conversion/basis model.

### Comparable quantity yield

When all relevant linked input/output quantities share a compatible base-unit basis:

```text
input_total  = Σ input base_quantity
output_total = Σ qualifying output base_quantity

quantity_yield_ratio = output_total / input_total
```

Residual comparable loss:

```text
residual_loss = input_total - output_total
```

These are derived read-model values, not persisted stock truth.

### Output-role-aware views

Different analytics may report:

- primary yield;
- useful yield (`primary + co_product + byproduct + recovered`);
- scrap quantity;
- residual loss.

Foundation V1 only needs the lineage necessary to derive these later.

### Unsupported basis

When units are not comparable:

```text
yield_supported = false
yield_ratio = null
```

Lineage remains completely valid.

“Unknown/not comparable” is better than mathematically meaningless yield.

---

## 12. Future recipe / target yield boundary

Recipe/BOM/standard formula is a separate future capability.

It may later define:

- expected inputs;
- expected outputs;
- acceptable variance;
- standard yield;
- versioned formula;
- production instructions.

Actual transformation evidence must remain distinct from expected recipe definitions.

No Foundation V1 recipe engine is authorized.

---

## 13. Provenance architecture

Current RECON found:

```text
LOT_SERIAL_FILES=0
FRACTIONAL_FILES=15
```

Therefore generic lot/serial is not a mandatory Transformation dependency today.

### Current strongest identity

Lineage can already preserve, through the linked inventory facts and existing capabilities:

- organization;
- product;
- condition;
- location;
- confirmed movement;
- exact movement line;
- quantity/unit;
- fractional-container provenance where current inventory mechanisms carry/enforce it.

### Future capabilities

Later additive bindings may include:

- lot/batch;
- expiry;
- serial/IMEI;
- container;
- supplier/source evidence;
- manufacturing batch/passport identifiers.

Transformation must not redesign these identity systems. It references the strongest authoritative provenance exposed by them.

---

## 14. Fractional-container interaction

Fractional containers already have dedicated opening/consumption/provenance semantics.

Rules:

1. do not duplicate fractional consumption inside Transformation;
2. if a transformation consumes material from a fractional container, the inventory input movement must satisfy existing fractional traceability enforcement;
3. Transformation links the resulting confirmed movement line/evidence;
4. transformation provenance may later project the container lineage through the existing authoritative relation.

---

## 15. Lot / serial future interaction

When lot/serial foundations exist:

- a transformation input may identify exact source lots/serials through inventory allocation evidence;
- outputs may create or attach new lot/batch/serial identities as appropriate;
- transformation lineage connects input provenance to output provenance;
- serialization rules remain capability-specific;
- a simple bakery product does not inherit electronics UI complexity;
- a serialized electronic transformation/reconditioning does not inherit FEFO UI unnecessarily.

---

## 16. Correction and reversal

Completed transformation history is never edited to “fix” reality.

### Stock correction

Underlying physical stock is corrected through established inventory reversal/replacement semantics.

### Transformation correction

A later correction record should:

- reference/supersede the original transformation;
- link corrected confirmed movement evidence;
- preserve original immutable evidence;
- allow read models to distinguish historical vs currently effective interpretation.

Physical deletion is not correction.

---

## 17. Storefront integration

Storefront does not call the internal Transformation aggregate as a stock API.

Normal consumer path:

```text
Transformation
    ↓ confirmed movement stock truth
Commercial Availability
    ↓
Public/Storefront-safe availability
    ↓
Storefront
```

### Approved transformation-derived claims

A merchant may later choose to surface claims such as:

- prepared/produced date;
- selected origin/provenance;
- made-from / batch claim;
- freshness/production attribute.

These require an explicit Storefront/public projector.

Internal production details remain private.

---

## 18. Variable quantity interaction

Variable quantity and transformation solve different problems.

### Variable quantity

```text
requested ~1.000 kg
measured 1.086 kg
accepted 1.086 kg
```

Same product identity; fulfillment measurement.

### Transformation

```text
10 kg raw product A
→ 6 kg product B
→ 3 kg product C
→ residual loss
```

Material/product lineage changed.

A flow may use both:

```text
transform material
    ↓
finished product becomes inventory
    ↓
customer requests approximate amount
    ↓
VariableQuantityFulfillment measures actual picked amount
```

No duplicated quantity authority is needed.

---

## 19. Fulfillment Preference interaction

Fulfillment Preference records customer intent for substitution/consultation/fallback.

It does not control production transformation.

A future Storefront workflow may allow a merchant to fulfill demand through a transformed/prepared product, but that requires:

- commercial eligibility;
- substitution/offer semantics;
- availability revalidation;
- consumer preference/consent where applicable.

Transformation evidence alone is not authorization to substitute an order.

---

## 20. Service domain interaction

Service and transformation may intersect but remain distinct.

Examples:

- replacing a part in a repair: Service work + Inventory Issue;
- rebuilding a component into a trackable sellable unit: may later involve Service + Transformation;
- consuming lubricant: fractional inventory/service consumption;
- reconditioning a product: may create transformation/condition lineage in a future capability.

Foundation V1 remains material/inventory transformation, not a generic service lifecycle engine.

---

## 21. Product Passport projection

Transformation is highly valuable for Product Passport, but the internal lineage must stay private by default.

Future projector:

```text
private InventoryTransformation
        ↓ explicit claim policy
PassportClaimProjection
        ↓
Product Passport
```

Potential public-safe claims:

- “produced from batch X” when allowed;
- production date;
- selected origin;
- transformation stage/category;
- verified after-sales/reconditioning fact.

Forbidden default exposure:

- internal operator;
- private warehouse location;
- supplier cost;
- proprietary formula;
- complete input inventory history;
- internal loss/margin analytics.

---

## 22. Trust Passport projection

Trust may later derive aggregate signals such as:

- traceability completeness;
- evidence freshness;
- correction discipline;
- verified fulfillment/production consistency.

Trust consumes derived signal projections, not raw Transformation tables.

---

## 23. Demand-to-Supply / capacity analytics

Aggregate transformation evidence can later inform:

- real yield distributions;
- production capacity estimates;
- expected availability;
- raw-material demand signals;
- waste reduction analysis.

These are analytics/advisory projections.

They MUST NOT automatically create purchase orders, change recipes, mutate stock or promise consumer availability.

---

## 24. Foundation V1 proposed exact source cut

The implementation should remain small and testable.

Likely source families:

```text
app/Enums/InventoryTransformationDirection.php
app/Enums/InventoryTransformationOutputRole.php
app/Models/InventoryTransformation.php
app/Models/InventoryTransformationLineage.php
app/Domain/Inventory/InventoryTransformationManager.php
database/migrations/<timestamp>_create_inventory_transformations_tables.php
tests/Feature/Inventory/InventoryTransformationFoundationTest.php
```

And, after exhaustive enum-consumer audit:

```text
app/Enums/InventoryMovementType.php
```

Potential tests may require no other production files unless the audit demonstrates a real compatibility boundary.

The exact implementation scope is determined by a guarded source-write runner and focal evidence, not by this conceptual filename list.

---

## 25. Foundation V1 focal acceptance cases

The focal test should prove at least:

1. completed transformation links confirmed input/output movement lines;
2. recording transformation evidence does not alter physical stock/balances;
3. exact idempotency returns same record;
4. same idempotency key with changed lineage fails;
5. cross-organization lineage fails;
6. draft/unconfirmed movement fails;
7. missing input fails;
8. missing output fails;
9. duplicated line fails;
10. input/output direction mismatch fails;
11. output roles persist correctly;
12. transformation evidence is immutable;
13. physical delete is rejected;
14. comparable yield can be derived correctly;
15. incompatible-unit yield is explicitly unsupported/null;
16. original evidence survives correction/supersession design.

The first Foundation may split yield-reader tests into a follow-up cut if doing so keeps the initial write coherent, but lineage/idempotency/immutability/no-stock-mutation are mandatory.

---

## 26. Foundation implementation audit before write

Before adding `TransformationInput` / `TransformationOutput`, the runner must inspect references to `InventoryMovementType` for:

- exhaustive PHP `match`;
- validation arrays;
- controller/form enum assumptions;
- test fixtures;
- projector direction assumptions;
- UI labels;
- seeders;
- serialization.

The cut must adapt every required exhaustive consumer or FAIL_CLOSED.

This is the only safe way to extend a PHP enum in a mature codebase.

---

## 27. Non-goals

Foundation V1 does NOT implement:

- MRP;
- production scheduling;
- recipes/BOM;
- standard costing;
- accounting valuation;
- labor costing;
- generic lot/serial foundation;
- cold-chain telemetry;
- warehouse IoT;
- Storefront transformation UI;
- Network runtime;
- Product Passport runtime;
- Trust Passport runtime;
- public transformation APIs;
- service work-order runtime;
- P13.C;
- production mutation.

---

## 28. Architecture acceptance

Transformation/Yield/Traceability is correctly integrated when:

- inventory still has exactly one physical truth;
- transformation has immutable exact lineage;
- yield is derived only on valid bases;
- fractional provenance is reused, not copied;
- lot/serial can be added without schema redesign of the core lineage concept;
- Storefront consumes availability/finalization, not internal transformation tables;
- Product Passport receives explicit sanitized claims;
- Network remains downstream;
- corrections preserve history;
- simple merchants never see transformation complexity unless the capability is enabled.

---

## 29. Canonical decision

The Straleon transformation architecture is:

> **confirmed inventory movements are the physical facts; transformation is the immutable semantic link explaining how those facts relate.**

That relationship keeps inventory mathematically authoritative while enabling yield analytics, deep traceability, Product Passport, Trust signals and future demand/supply intelligence without rebuilding the Core.
