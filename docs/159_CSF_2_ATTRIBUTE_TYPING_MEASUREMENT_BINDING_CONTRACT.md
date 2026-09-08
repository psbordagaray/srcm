# CSF-2 — Attribute Typing / Measurement / Binding Contract

**Status:** ACCEPTED — BINDING FOR CSF-2 IMPLEMENTATION
**Architecture parent:** `docs/157_ADR_CATALOG_SEMANTIC_FOUNDATION.md`
**CSF-1 parent:** `docs/158_CSF_1_SEMANTIC_REGISTRY_FOUNDATION_CONTRACT.md`
**Baseline:** `feature/core-entity @ 8171d5a48eb8824efb9b694d65582bd0414c0f5c`
**CI baseline:** CI162 `completed / success`

---

## 1. Purpose

CSF-2 adds the first executable semantic typing layer on top of the CSF-1
registries without turning `CatalogProduct` into a universal attribute table and
without creating a second quantity/unit system.

CSF-2 answers three questions:

```text
1. What value family does this attribute use in this schema?
2. Where does that semantic value belong?
3. If it is a physical measurement, what exact semantic unit qualifies it?
```

The canonical flow becomes:

```text
AttributeDefinition
        │
        ▼
AttributeBinding
        │
        ├── value_type
        ├── value_scope
        └── measurement_unit? ──► MeasurementUnit ──► MeasurementDimension
```

`AttributeDefinition` remains reusable global identity.

`AttributeBinding` defines how that identity is used by one exact
`ProductSchemaVersion`.

No dynamic attribute values are authorized by CSF-2.

---

## 2. Binding architectural laws

### 2.1 AttributeDefinition remains identity-only

CSF-2 MUST NOT add typing, scope, measurement, requirement or channel behavior
columns to `catalog_attribute_definitions`.

The CSF-1 law remains binding:

> AttributeDefinition answers what semantic attribute this is.
>
> AttributeBinding answers how that attribute behaves in one schema version.

### 2.2 ProductSchemaVersion remains the historical schema authority

Bindings belong to an exact `ProductSchemaVersion`.

Published schema semantics are immutable.

Changing a binding after publication requires a new schema version.

### 2.3 One canonical fact, one authority

CSF-2 MUST follow the Straleon Single-Truth Principle.

In particular:

```text
MeasurementUnit.measurement_dimension_id
```

is the one authority for the physical dimension of that measurement unit.

`AttributeBinding` MUST NOT duplicate `measurement_dimension_id`.

A measurement binding stores only:

```text
measurement_unit_id
```

and derives its dimension through `MeasurementUnit`.

This prevents two columns from becoming competing authorities.

---

## 3. Critical boundary: semantic measurement vs operational inventory unit

Straleon already has operational quantity/unit authority:

```text
InventoryBaseUnit
InventoryQuantity
CatalogProduct.base_unit_code
CatalogProduct.quantity_scale
ProductPresentation
ProductPresentationManager
```

Those concepts answer questions such as:

```text
How is this product stocked?
What is its stock base unit?
What presentation was entered?
What factor converts that presentation to the product's base quantity?
```

CSF-2 measurement semantics answer a different question:

```text
What physical magnitude does this descriptive attribute represent?
In what exact semantic unit is that attribute expressed?
```

Example:

```text
Dumbbell sold as 1 inventory unit
    inventory base unit = unit

Descriptive attribute:
    weight = 12.5 kg
    measurement dimension = mass
    measurement unit = kilogram
```

The two facts are related in ordinary language but are not the same domain fact.

Therefore CSF-2 MUST NOT:

- replace `InventoryBaseUnit`;
- reuse `ProductPresentation` as a measurement registry;
- foreign-key a measurement unit to an inventory base unit;
- assume identical symbols imply identical domain semantics;
- introduce automatic conversion between semantic measurement units and
  operational stock units.

A future explicit cross-domain mapping may be designed only if a real use case
requires it.

---

## 4. Numeric authority

Binary floating point is forbidden as semantic numeric authority.

CSF-2 reuses:

```text
App\Domain\Numerics\ExactDecimal
```

for generic exact decimal semantics.

`ExactDecimal` currently establishes:

- canonical dot-decimal syntax;
- no exponent notation;
- no grouping;
- no leading plus sign;
- no negative zero;
- maximum scale of 18;
- fail-closed scale overflow.

CSF-2 MUST NOT reuse the inventory-specific scale of
`InventoryQuantity::SCALE` for descriptive attributes.

`InventoryQuantity` remains the operational inventory specialization with its
own scale and conversion-factor contracts.

No new generic decimal engine is authorized.

---

# 5. Exact CSF-2 scope

CSF-2 adds exactly these semantic concepts:

```text
AttributeValueType
AttributeValueScope
MeasurementDimension
MeasurementUnit
AttributeBinding
```

and extends schema-version coordination so draft schema versions can inherit and
mutate bindings safely.

CSF-2 does not add attribute values.

---

# 6. AttributeValueType

Canonical enum:

```text
App\Enums\AttributeValueType
```

Initial values:

```text
text
boolean
integer
exact_decimal
measurement
date
datetime
```

Semantics:

### `text`

Opaque semantic text.

Length constraints, patterns and normalization rules are future constraint
contracts.

### `boolean`

True/false semantic value.

### `integer`

Exact integer semantic value.

It is not a binary float and must ultimately validate as an exact integer.

### `exact_decimal`

Generic exact decimal semantic value.

Future value storage must use `ExactDecimal` semantics.

### `measurement`

Exact decimal magnitude qualified by one `MeasurementUnit`.

A measurement binding MUST reference a measurement unit.

### `date`

Calendar date semantic family.

Parsing/storage is not implemented by CSF-2.

### `datetime`

Date-time semantic family.

Timezone/input/storage rules are not implemented by CSF-2.

The following families are intentionally deferred:

```text
enum
value_set
reference
identifier_reference
money
json
structured_object
```

They require additional authorities and MUST NOT be approximated as ad-hoc
strings by CSF-2 runtime.

---

# 7. AttributeValueScope

Canonical enum:

```text
App\Enums\AttributeValueScope
```

Initial values:

```text
product
variant
inventory_unit
lot_or_batch
supplier_offer
```

A binding has exactly one value scope.

The scope answers:

> Which domain subject owns the eventual value of this binding?

Interpretation:

```text
product        → merchant CatalogProduct semantic value
variant        → future Variant subject
inventory_unit → individually tracked physical unit
lot_or_batch   → lot/batch subject
supplier_offer → SupplierOffer subject
```

Declaring a scope does not authorize or create the corresponding value storage.

In particular:

```text
variant
```

does not authorize a Variant runtime in CSF-2.

CSF-2 MUST NOT use JSON arrays such as `allowed_scopes`.

One schema binding chooses one canonical value owner.

Different product definitions may bind the same `AttributeDefinition` at
different scopes in their respective schema versions.

---

# 8. MeasurementDimension registry

Canonical table:

```text
catalog_measurement_dimensions
```

Fields:

```text
id BIGINT PK
key VARCHAR(160) NOT NULL
name VARCHAR(160) NOT NULL
description TEXT NULL
status VARCHAR(32) NOT NULL
created_at
updated_at
```

Constraints:

```text
UNIQUE(key)
INDEX(status)
```

Example semantic keys:

```text
straleon.measurement.dimension.length
straleon.measurement.dimension.mass
straleon.measurement.dimension.volume
straleon.measurement.dimension.temperature
```

These examples are illustrative only.

CSF-2 ships no seeded canonical measurement catalog.

Keys use the existing `SemanticKey` validator and are:

- global;
- lowercase ASCII dot-delimited;
- unique;
- immutable;
- never reused after retirement.

Measurement dimensions are not organization-owned.

---

## 8.1 MeasurementDimension lifecycle

Canonical statuses:

```text
ACTIVE
DEPRECATED
RETIRED
```

Allowed transitions:

```text
ACTIVE → DEPRECATED → RETIRED
```

Forbidden:

```text
RETIRED → *
DEPRECATED → ACTIVE
physical delete
key replacement
key reuse
```

A dimension may be deprecated only after it has no ACTIVE units.

A dimension may be retired only after all of its units are RETIRED.

This prevents an inactive parent from silently leaving reusable active units
under it.

---

# 9. MeasurementUnit registry

Canonical table:

```text
catalog_measurement_units
```

Fields:

```text
id BIGINT PK
measurement_dimension_id BIGINT NOT NULL
key VARCHAR(160) NOT NULL
name VARCHAR(160) NOT NULL
symbol VARCHAR(32) NULL
description TEXT NULL
status VARCHAR(32) NOT NULL
created_at
updated_at
```

Constraints:

```text
FK measurement_dimension_id
    → catalog_measurement_dimensions.id
    ON DELETE RESTRICT

UNIQUE(key)

INDEX(measurement_dimension_id, status)
INDEX(status)
```

Example semantic keys:

```text
straleon.measurement.unit.millimeter
straleon.measurement.unit.inch
straleon.measurement.unit.kilogram
straleon.measurement.unit.liter
```

Examples are illustrative only.

A unit belongs to exactly one dimension.

That dimension relationship is immutable.

Unit keys are global, unique, immutable and never reused.

Symbols are display metadata and are not globally unique.

A unit may be created only under an ACTIVE dimension.

---

## 9.1 No conversion engine in CSF-2

`catalog_measurement_units` MUST NOT contain:

```text
conversion_factor
factor_to_base
factor_to_canonical
offset
formula
canonical_unit_id
inventory_base_unit_code
```

CSF-2 preserves exact unit identity but does not perform physical unit
conversion.

This is deliberate.

A correct universal conversion model must handle more than multiplicative
factors, including affine units such as temperature and potentially other
domain-specific transforms.

CSF-2 does not fake that problem with a premature factor column.

---

## 9.2 MeasurementUnit lifecycle

Canonical statuses:

```text
ACTIVE
DEPRECATED
RETIRED
```

Allowed transitions:

```text
ACTIVE → DEPRECATED → RETIRED
```

Forbidden:

```text
reactivation
physical delete
key mutation
dimension reassignment
key reuse
```

Published historical schema bindings remain resolvable when a unit is later
deprecated or retired because the row is preserved.

A deprecated or retired unit cannot be selected for a new or reconfigured draft
binding.

---

# 10. AttributeBinding

Canonical table:

```text
catalog_attribute_bindings
```

Fields:

```text
id BIGINT PK
product_schema_version_id BIGINT NOT NULL
attribute_definition_id BIGINT NOT NULL
value_type VARCHAR(32) NOT NULL
value_scope VARCHAR(32) NOT NULL
measurement_unit_id BIGINT NULL
created_at
updated_at
```

Constraints:

```text
FK product_schema_version_id
    → catalog_product_schema_versions.id
    ON DELETE RESTRICT

FK attribute_definition_id
    → catalog_attribute_definitions.id
    ON DELETE RESTRICT

FK measurement_unit_id
    → catalog_measurement_units.id
    ON DELETE RESTRICT

UNIQUE(product_schema_version_id, attribute_definition_id)

INDEX(attribute_definition_id)
INDEX(product_schema_version_id, value_scope)
INDEX(product_schema_version_id, value_type)
```

The binding is global schema metadata.

It has no `organization_id`.

---

## 10.1 Measurement invariant

For:

```text
value_type = measurement
```

the binding MUST have:

```text
measurement_unit_id NOT NULL
```

and that unit must be ACTIVE when the draft binding is created or reconfigured.

The dimension is derived from:

```text
binding
  → measurement_unit
  → measurement_dimension
```

For all non-measurement value types:

```text
measurement_unit_id MUST be NULL
```

No silent unit inference is allowed.

---

## 10.2 AttributeDefinition invariant

Creating or reconfiguring a draft binding requires an ACTIVE
`AttributeDefinition`.

Published historical bindings remain valid if their attribute definition is
later deprecated or retired.

A new schema publication may not publish bindings whose attribute definition is
not ACTIVE.

This forces explicit semantic migration instead of silently perpetuating
deprecated vocabulary.

---

## 10.3 Binding identity

Within one schema version:

```text
(product_schema_version_id, attribute_definition_id)
```

is unique.

A binding's:

```text
product_schema_version_id
attribute_definition_id
```

are immutable after creation.

To replace the attribute identity inside a DRAFT schema, remove the draft
binding and create another one.

Configuration fields may be changed only while the parent schema is DRAFT:

```text
value_type
value_scope
measurement_unit_id
```

---

# 11. Draft-only mutation

Only a DRAFT `ProductSchemaVersion` may add, change or remove bindings.

For:

```text
PUBLISHED
DEPRECATED
RETIRED
```

binding creation, update and deletion MUST fail closed.

This rule must be enforced at the domain/model boundary and not depend only on
controllers or future UI.

Physical removal of a binding is allowed only while the parent schema is DRAFT,
because a draft is an unpublished workspace.

Once published, binding rows are historical semantic evidence and are never
physically deleted.

---

# 12. Draft inheritance from the current published schema

CSF-1 creates a new draft version number but has no bindings to copy.

CSF-2 changes that behavior.

When `ProductSchemaVersionManager::createDraft(...)` creates a draft and a
current PUBLISHED schema exists for that `ProductDefinition`, the new draft MUST
clone all current published bindings atomically.

The clone copies exactly:

```text
attribute_definition_id
value_type
value_scope
measurement_unit_id
```

Each cloned row receives a new binding id and belongs to the new draft version.

The source published bindings remain untouched.

If there is no current published schema, the new draft starts with zero
bindings.

If more than one current published schema exists, draft creation fails closed.

---

## 12.1 Clone despite later registry deprecation

Draft inheritance is a faithful historical copy.

Therefore cloning does not silently drop a binding merely because, after the
source schema was published, its:

```text
AttributeDefinition
MeasurementUnit
MeasurementDimension
```

was deprecated or retired.

The binding is copied.

However publication of the new draft MUST fail until every binding references
currently ACTIVE semantic dependencies or has been explicitly replaced/removed.

This preserves history while preventing stale vocabulary from being silently
republished.

---

# 13. Publication validation

Before changing a DRAFT schema to PUBLISHED,
`ProductSchemaVersionManager::publish(...)` MUST validate its complete binding
set inside the same transaction.

For every binding:

1. `AttributeDefinition` must be ACTIVE.
2. `value_type` must be a valid `AttributeValueType`.
3. `value_scope` must be a valid `AttributeValueScope`.
4. `measurement` requires an ACTIVE `MeasurementUnit`.
5. that unit's `MeasurementDimension` must be ACTIVE.
6. non-measurement bindings must not contain `measurement_unit_id`.

Any failure aborts the publication.

The previously PUBLISHED schema must not be deprecated unless the new draft
passes validation.

The publish operation remains atomic.

An empty schema is still legal in CSF-2.

---

# 14. Concurrency law

Existing schema lifecycle locking remains authoritative.

Draft creation:

```text
transaction
→ lock ProductDefinition
→ verify ACTIVE
→ verify no DRAFT
→ resolve current PUBLISHED
→ lock current PUBLISHED when present
→ allocate max(version) + 1
→ create DRAFT
→ lock/read source bindings
→ clone bindings
→ commit
```

Binding mutation:

```text
transaction
→ lock ProductSchemaVersion
→ require DRAFT
→ lock referenced semantic registries as needed
→ validate
→ mutate
→ commit
```

Publication:

```text
transaction
→ lock ProductDefinition
→ lock target DRAFT
→ lock target bindings
→ validate all semantic dependencies
→ lock current PUBLISHED
→ deprecate prior published schema
→ publish target
→ commit
```

Database unique constraints remain concurrency backstops.

---

# 15. Explicitly forbidden AttributeBinding fields in CSF-2

CSF-2 MUST NOT add:

```text
required
optional
requirement_timing
requirement_gate
default_value
min_value
max_value
pattern
constraints_json
allowed_values_json
filterable
searchable
comparable
variant_axis
storefront_visible
network_visible
display_order
ui_component
organization_id
catalog_product_id
dynamic_value
value_text
value_number
value_json
measurement_dimension_id
```

Reasons:

- requirement semantics belong to future readiness/contextual-gate work;
- validation constraints need a coherent constraint model;
- filter/search/compare are projection/use concerns;
- variant-axis semantics belong with Variant capability;
- Storefront/Network are projections, not schema truth;
- dynamic value storage is a later phase;
- `measurement_dimension_id` would duplicate the unit's dimension authority.

---

# 16. Why `required` is deliberately deferred

A global `required=true` flag is not enough to answer:

```text
Required for what?
Required at which operation?
Required for which actor?
Required before publishing?
Required before receiving stock?
Required before Storefront publication?
Can it be learned later from a serial, lot or supplier document?
```

CSF architecture requires Straleon to ask for information at the moment it is
naturally known.

Therefore CSF-2 establishes typing and ownership without introducing a premature
universal mandatory-field flag.

Requirement gates are a separate future contract.

---

# 17. No CatalogProduct assignment in CSF-2

CSF-2 still MUST NOT add:

```text
CatalogProduct.product_definition_id
CatalogProduct.product_schema_version_id
```

or any equivalent assignment runtime.

The semantic registry and schema contract can exist before a merchant product is
classified against it.

CatalogProduct assignment/reclassification requires its own cut because it
changes runtime product semantics and historical reclassification rules.

---

# 18. No attribute values in CSF-2

CSF-2 MUST NOT create tables such as:

```text
catalog_product_attribute_values
attribute_values
variant_attribute_values
inventory_unit_attribute_values
lot_attribute_values
supplier_offer_attribute_values
```

No EAV value store is authorized.

No JSON attribute bag is authorized.

No polymorphic value table is authorized.

Value persistence requires a later contract that can preserve:

- schema-version provenance;
- binding provenance;
- exact type;
- exact measurement unit;
- owner scope;
- effective/historical semantics;
- tenant boundaries where applicable.

---

# 19. No capabilities, Variant, Storefront or Network runtime

CSF-2 does not authorize:

```text
capability declarations
EffectiveSemanticProfile runtime
Variant model/runtime
requirement gates
ContextualInteractionProfile
Storefront semantic projection
Network semantic projection
AI classification/extraction
```

The architecture remains:

```text
semantic identity
    ↓
schema typing/binding
    ↓
future product assignment/value storage
    ↓
future capability/effective resolution
    ↓
future contextual interaction/projections
```

---

# 20. Planned Laravel implementation surface

## 20.1 New enums

```text
app/Enums/AttributeValueType.php
app/Enums/AttributeValueScope.php
app/Enums/MeasurementDimensionStatus.php
app/Enums/MeasurementUnitStatus.php
```

## 20.2 New models

```text
app/Models/MeasurementDimension.php
app/Models/MeasurementUnit.php
app/Models/AttributeBinding.php
```

## 20.3 New domain managers

```text
app/Domain/Catalog/MeasurementDimensionManager.php
app/Domain/Catalog/MeasurementUnitManager.php
app/Domain/Catalog/AttributeBindingManager.php
```

## 20.4 Modified CSF-1 runtime

```text
app/Models/ProductSchemaVersion.php
app/Models/AttributeDefinition.php
app/Domain/Catalog/ProductSchemaVersionManager.php
```

Expected changes are relationships, draft binding inheritance and publication
validation.

`SemanticKey` is reused unchanged.

`ExactDecimal` is reused unchanged.

`InventoryQuantity`, `InventoryBaseUnit`, `CatalogProduct` inventory quantity
fields, `ProductPresentation` and `ProductPresentationManager` are reused
unchanged.

## 20.5 New migrations

```text
database/migrations/2026_09_08_201000_create_catalog_measurement_dimensions_table.php
database/migrations/2026_09_08_201001_create_catalog_measurement_units_table.php
database/migrations/2026_09_08_201002_create_catalog_attribute_bindings_table.php
```

## 20.6 Tests

Minimum focused contract suite:

```text
tests/Feature/Catalog/MeasurementDimensionFoundationTest.php
tests/Feature/Catalog/MeasurementUnitFoundationTest.php
tests/Feature/Catalog/AttributeBindingFoundationTest.php
tests/Feature/Catalog/ProductSchemaBindingVersioningTest.php
```

---

# 21. Minimum test obligations

## MeasurementDimension

Must prove:

- valid semantic-key creation;
- global uniqueness;
- no silent key normalization;
- key immutability;
- ACTIVE → DEPRECATED → RETIRED;
- no reactivation;
- no physical delete;
- cannot deprecate while ACTIVE units remain;
- cannot retire until all units are RETIRED.

## MeasurementUnit

Must prove:

- creation under ACTIVE dimension;
- global unique semantic key;
- unit dimension is immutable;
- symbol is not identity;
- lifecycle is one-way;
- no physical delete;
- no creation under deprecated/retired dimension;
- no conversion-factor fields exist.

## AttributeBinding

Must prove:

- binding only against DRAFT schema;
- unique attribute per schema version;
- active AttributeDefinition required for new/reconfigured binding;
- valid value type and value scope;
- measurement requires active unit;
- non-measurement forbids measurement unit;
- dimension is derived from unit;
- no redundant measurement dimension column;
- schema/attribute binding identity is immutable;
- draft reconfiguration works;
- draft removal works;
- published/deprecated/retired binding mutation fails closed.

## Schema versioning

Must prove:

- first draft without a published predecessor starts empty;
- next draft clones the current published bindings;
- clone creates new binding ids;
- source published bindings remain byte/semantically unchanged;
- deprecated dependencies are cloned faithfully;
- new publication rejects inactive dependencies;
- failed publication does not deprecate the prior published schema;
- successful publication atomically deprecates the prior schema.

---

# 22. Implementation acceptance gate

CSF-2 source implementation may be accepted only when:

```text
exact implementation scope verified
PHP lint GREEN
focused CSF-2 tests GREEN
existing CSF-1 tests GREEN
relevant numeric/inventory quantity/presentation tests GREEN
full suite GREEN once
git diff --check GREEN
worktree/staging integrity proven
```

After a GREEN source result, checkpoint/push is a separate cut and MUST NOT
rerun the already-proven full suite unless source changes.

Natural CI is consumed after push.

---

# 23. Resulting boundary after CSF-2

After CSF-2:

```text
ProductDefinition
    │
    └── ProductSchemaVersion
            │
            └── AttributeBinding
                    ├── AttributeDefinition
                    ├── AttributeValueType
                    ├── AttributeValueScope
                    └── MeasurementUnit?
                            └── MeasurementDimension
```

Straleon will know:

- what semantic attributes exist;
- which attributes belong to each schema version;
- what value family each binding expects;
- where a future value belongs;
- and, for measurements, the exact semantic unit/dimension.

Straleon will still intentionally **not** know through CSF-2:

- which merchant CatalogProduct uses which ProductDefinition;
- actual dynamic attribute values;
- requirement timing;
- variants;
- capabilities;
- Storefront/Network semantic projections.

That separation is deliberate.

---

# 24. Canonical next boundary

After this contract is published and its natural CI is GREEN, the next allowed
source cut is:

```text
CSF-2 ATTRIBUTE TYPING / MEASUREMENT / BINDING FOUNDATION
```

No later CSF phase is authorized by this document.
