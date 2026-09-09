# CSF-6 — Semantic Values Contract V1

**Document:** `docs/163_CSF_6_SEMANTIC_VALUES_CONTRACT.md`
**Status:** FROZEN IMPLEMENTATION CONTRACT — RUNTIME NOT YET AUTHORIZED
**Product:** Straleon
**Program:** P13.B — Catalog Semantic Foundation
**Depends on:** ADR 157 + CSF-1/2/3/4/5 published foundations + CSF-5 continuity CI174 GREEN
**Baseline:** `feature/core-entity @ e943cf2962bc574cf5db8a579e22f446266878ff`
**Baseline tree:** `ef4bef012867622e6a38f51ed09a6c166f7c5d43`
**RECON evidence:** `RESULT_STRALEON_P13_B_CSF6_SEMANTIC_VALUES_RECON_V1.txt @ sha256:935be7afebb88c2fcdaf98b0a21b88bd98f82a2ccbbf44252b8489cd7e5c9adc`
**RECON reconciliation:** `RESULT_STRALEON_P13_B_CSF6_RECON_EVIDENCE_RECONCILIATION_V1.txt @ sha256:1c921a5ca2afc45e5faf9c21e1f482dc568f061187d2846334277039746e1280`
**Effective date:** 2026-09-09

---

## 1. Purpose

CSF-6 defines the first runtime authority for semantic attribute values attached to
a `CatalogProduct`.

It answers:

> **What semantic values are currently asserted by this CatalogProduct under its
> current published semantic profile?**

CSF-6 V1 does not create a generic EAV engine, a generic polymorphic semantic
subject table, value inference, schema migration automation, variant runtime,
inventory-unit runtime, lot/batch runtime, SupplierOffer semantic-value runtime,
Storefront publication semantics, organization-specific value overrides, or
cross-domain historical reconstruction.

The purpose is deliberately narrower:

```text
CatalogProduct
    ↓ current product_definition_id
current EffectiveSemanticProfile
    ↓ exact effective AttributeBinding provenance
typed product-scoped semantic values
```

---

## 2. Evidence frozen by RECON

The CSF-6 RECON establishes the following current facts:

```text
AttributeValueType:
    text
    boolean
    integer
    exact_decimal
    measurement
    date
    datetime

AttributeValueScope:
    product
    variant
    inventory_unit
    lot_or_batch
    supplier_offer
```

There is currently no CSF semantic-value table authority and no CSF
semantic-value model authority.

`Assertion::value_json` belongs to the separate Knowledge `Entity / Assertion`
authority and is not semantic-value storage for CSF.

Existing `AttributeValueType` / `AttributeValueScope` references in tests exercise
binding contracts; they are not persisted semantic values.

A `measurement` binding already requires:

1. a measurement unit;
2. an active measurement unit at binding/publication validation time;
3. an active measurement dimension;
4. no measurement unit on a non-measurement binding.

These findings are binding constraints for CSF-6 implementation.

---

## 3. V1 runtime scope authorization

The enum contains five value scopes, but enum existence is not runtime
authorization.

CSF-6 V1 authorizes persistence only for:

```text
AttributeValueScope::Product
```

The subject is:

```text
CatalogProduct
```

The following remain outside V1 runtime:

```text
variant
inventory_unit
lot_or_batch
supplier_offer
```

`SupplierOffer` already exists as a runtime subject, but semantic-value persistence
for that subject requires a separate binding contract before implementation.

`variant`, `inventory_unit`, and `lot_or_batch` remain reserved semantic scopes.
CSF-6 must not invent those subject runtimes merely to satisfy the enum.

---

## 4. Current-truth storage authority

The V1 current-truth table is:

```text
catalog_product_semantic_values
```

One row means:

> this CatalogProduct currently has this typed value for this exact effective
> AttributeBinding.

Required columns:

```text
id
catalog_product_id
attribute_binding_id

value_text
value_boolean
value_integer
value_decimal
value_date
value_datetime

created_at
updated_at
```

Required identity:

```text
UNIQUE (catalog_product_id, attribute_binding_id)
```

Required foreign keys:

```text
catalog_product_id
    FK → catalog_products.id
    RESTRICT ON DELETE

attribute_binding_id
    FK → catalog_attribute_bindings.id
    RESTRICT ON DELETE
```

CSF-6 V1 must not add:

```text
organization_id
product_definition_id
product_schema_version_id
attribute_definition_id
value_type
value_scope
measurement_unit_id
subject_type
subject_id
value_json
payload_json
```

to this table as duplicate current-truth columns.

Their semantic meaning is already reachable through the exact binding and its
published schema provenance.

---

## 5. Exact binding is the semantic identity of a stored value

A stored value must reference the exact `AttributeBinding` that authorized it.

The binding supplies:

```text
product_schema_version_id
attribute_definition_id
value_type
value_scope
measurement_unit_id
```

and effective resolution preserves:

```text
attribute_binding_id
product_schema_version_id
attribute_definition_id
attribute_definition_key
resolution rule / provenance
```

Therefore this is forbidden:

```text
CatalogProduct + AttributeDefinition + raw value
```

as the complete semantic identity.

`attribute_definition_id` alone is insufficient because a definition may appear
under different schema versions, types, scopes, measurement units, or future
composition provenance.

The binding is not merely metadata. It is part of the meaning of the value.

---

## 6. Current profile membership is mandatory for writes

A value may be set only when all of the following are true inside the write
transaction:

1. the `CatalogProduct` exists and is row-locked;
2. `product_definition_id` is non-null and structurally valid;
3. the product resolves through `CatalogProductSemanticProfileResolver::current(...)`;
4. the requested semantic attribute resolves to exactly one effective attribute;
5. the resolved effective attribute contains an exact `attribute_binding_id`;
6. that binding exists and is row-locked;
7. its `value_scope` is exactly `product`;
8. its `value_type` is one of the seven published CSF types;
9. the submitted value validates and canonicalizes under that exact type;
10. any existing row for `(catalog_product_id, attribute_binding_id)` is
    row-locked before mutation.

No caller may write a value against:

- a binding from a different product definition;
- a binding from an old or non-current semantic profile;
- a draft-only binding;
- a binding not present in the product's effective current profile;
- a non-product scope.

---

## 7. Public mutation API resolves by semantic attribute key

Ordinary callers must not need internal schema IDs or binding IDs.

The conceptual domain API is:

```text
CatalogProductSemanticValueManager

set(
    CatalogProduct product,
    string attributeDefinitionKey,
    mixed value
)

clear(
    CatalogProduct product,
    string attributeDefinitionKey
)
```

The manager resolves the key through the product's current effective semantic
profile and derives the exact binding internally.

A lower-level mutation method accepting arbitrary binding IDs must not become the
ordinary application API.

This keeps the operator-facing and application-facing surface aligned with the
canonical UX principle:

> powerful semantics behind the scenes; minimal internal machinery exposed.

---

## 8. Absence and NULL semantics

CSF-6 V1 has no explicit-null semantic value.

The law is:

```text
no row
    = value absent / unknown / not supplied

row
    = exactly one valid typed payload
```

A row whose typed payload is entirely `NULL` is invalid.

`NULL` must not mean:

- explicit zero;
- false;
- empty string;
- unknown-but-present;
- not applicable;
- inherited default.

Those meanings are distinct future contracts if ever required.

To remove a value, use `clear(...)`.

---

## 9. Typed payload mapping

The payload columns are mutually exclusive under the binding type.

Canonical mapping:

```text
text
    → value_text

boolean
    → value_boolean

integer
    → value_integer

exact_decimal
    → value_decimal

measurement
    → value_decimal

date
    → value_date

datetime
    → value_datetime
```

Exactly one payload column must be non-null for a stored row.

`exact_decimal` and `measurement` share the decimal magnitude column because the
binding distinguishes their semantics.

No JSON fallback is allowed for unsupported input.

An unsupported or malformed value fails closed.

---

## 10. Text contract

`text` values are stored as UTF-8 text.

V1 rules:

- leading/trailing transport whitespace may be trimmed by the domain canonicalizer;
- an empty result is not a semantic value and must be rejected;
- internal content must not be silently uppercased, lowercased, translated,
  transliterated, or otherwise semantically normalized;
- no hidden conversion to identifiers, numbers, dates, or JSON is allowed.

Attribute-specific constraints such as regex, enumerations, max length, or lexical
normalization are not introduced by CSF-6 V1.

---

## 11. Boolean contract

`boolean` accepts only an explicit boolean semantic input.

Canonical persisted meaning is:

```text
true
false
```

The value `false` is a real value and must not collapse into absence.

Ambiguous transport strings such as arbitrary non-empty text must not be silently
coerced to true.

---

## 12. Integer contract

`integer` is a signed integral value representable by the implementation's agreed
64-bit domain range.

Fractional values are rejected.

Scientific notation, locale thousands separators, floating-point rounding, and
silent truncation are not canonicalization rules.

---

## 13. Exact decimal contract

`exact_decimal` must never use binary floating-point as semantic authority.

The V1 storage shape is:

```text
DECIMAL(38,18)
```

The domain layer must parse and validate exact decimal input before persistence.

Values outside the supported precision/scale fail closed.

Locale display formatting is a presentation concern and is never stored as the
semantic number.

---

## 14. Measurement contract

A measurement value is:

```text
exact decimal magnitude
+
measurement unit defined by the exact AttributeBinding
```

V1 stores only the magnitude in:

```text
value_decimal DECIMAL(38,18)
```

It does not duplicate `measurement_unit_id` on the value row.

The authoritative unit is:

```text
catalog_attribute_bindings.measurement_unit_id
```

and the authoritative dimension is derived through that unit.

V1 does not perform unit conversion.

Therefore:

- input must already be expressed in the binding's unit;
- a binary float is not semantic authority;
- no per-value alternative unit is persisted;
- no automatic conversion from another unit is introduced;
- no organization-preferred measurement unit changes stored meaning.

Future conversion/UI layers may accept alternate display/input units only under a
separate exact-conversion contract.

---

## 15. Date contract

`date` represents a calendar date without a time-of-day or timezone.

Canonical semantic shape:

```text
YYYY-MM-DD
```

Invalid calendar dates fail closed.

No timezone conversion may change the stored date.

---

## 16. DateTime contract

`datetime` represents an instant.

V1 canonical persistence is UTC with microsecond-capable storage:

```text
DATETIME(6)
```

Timezone-aware input must be normalized to UTC before persistence.

Naive ambiguous local datetime input must not silently acquire a server timezone.

The original presentation timezone is not part of CSF-6 current truth.

---

## 17. Idempotency

Exact retries are safe.

If canonicalization of the submitted value produces exactly the value already
stored for the same product and exact binding:

```text
no row rewrite
no updated_at churn
no duplicate audit event
```

Changing a value is a real mutation and must be audited.

---

## 18. Clear / explicit discard

`clear(...)` is the only V1 operation that removes a current semantic value.

It must:

1. resolve the current attribute key to the exact current binding;
2. lock the product and matching value row;
3. be idempotent when no row exists;
4. audit the old canonical value when a row exists;
5. delete that current-truth row atomically with the audit evidence.

Physical row removal here means:

```text
current semantic value no longer exists
```

It does not erase the immutable audit evidence of the prior value.

There is no V1 soft-delete column and no second active/current flag.

---

## 19. Current reads

The conceptual read boundary is:

```text
CatalogProductSemanticValueResolver::current(CatalogProduct)
```

Current resolution must:

1. resolve the product's current effective semantic profile;
2. collect its exact effective `attribute_binding_id` values;
3. load only rows for that CatalogProduct whose binding IDs are in that current
   profile;
4. hydrate typed values according to the binding type;
5. expose semantic attribute key and exact provenance without making callers
   reconstruct joins manually.

A semantic-value row outside the current effective profile is never silently
treated as current.

---

## 20. Reclassification integration — mandatory blocker

CSF-5 already requires semantic values to be addressed before controlled
reclassification.

CSF-6 V1 chooses the conservative safe law:

```text
CatalogProduct has any semantic-value row
    → reclassification FAILS CLOSED
```

This guard must be added to
`CatalogProductDefinitionAssignmentManager::assertReclassificationAllowed(...)`
before semantic-value persistence is enabled.

V1 does not automatically map values from ProductDefinition A to ProductDefinition
B.

The currently supported explicit discard path is:

```text
review values
→ clear values explicitly
→ reclassify
→ set values under the new current profile
```

A future compatible migration workflow may relax this blocker only under a new
contract that makes migration explicit and auditable.

---

## 21. Schema publication integration — mandatory blocker

Publishing a successor schema can change the exact binding identities used by
stored values.

`ProductSchemaVersionManager::publish(...)` currently deprecates the existing
published schema and promotes the draft.

Before CSF-6 persistence is enabled, publication must gain a semantic-value guard.

V1 law:

```text
ProductDefinition has any assigned CatalogProduct
with any semantic-value row
    → publishing a successor schema FAILS CLOSED
```

Creating and editing a draft remains allowed.

Initial publication when there is no prior published schema is not blocked by this
rule.

V1 does not automatically clone, rebind, reinterpret, migrate, or discard stored
values when a successor schema is published.

The explicit V1 path is:

```text
review / clear affected product values
→ publish successor schema
→ set values under the new exact bindings
```

Future schema-value migration may introduce compatibility analysis, but it must
not be retrofitted as silent behavior.

---

## 22. Why automatic rebind is forbidden

A copied draft may contain bindings that look structurally identical to the
previous schema.

That does not authorize automatic value migration.

Even if these fields appear equal:

```text
attribute_definition_id
value_type
value_scope
measurement_unit_id
```

the new binding belongs to a different schema version and therefore has different
provenance.

Future compatibility logic may prove a safe migration, but V1 does not infer it.

No semantic value is reinterpreted merely because two bindings look similar.

---

## 23. ProductDefinition lifecycle

A `DEPRECATED` ProductDefinition may still be assigned to existing products under
the CSF-5 migration-window law.

CSF-6 does not make deprecation itself a value deletion event.

Existing values remain current only while the product still resolves a valid
current semantic profile.

`RETIRED` remains incompatible with normal current CatalogProduct assignment under
CSF-5.

CSF-6 must not weaken that lifecycle.

---

## 24. Attribute / binding / schema lifecycle

Published binding identity is provenance and must remain referentially intact while
semantic values reference it.

Therefore semantic-value foreign keys use restrictive deletion behavior.

CSF-6 must not cascade-delete semantic values because an attribute, binding, schema,
or product lifecycle operation attempted a destructive delete.

Lifecycle managers must fail closed or require explicit value clearing according to
their existing domain laws.

Audit evidence is not a substitute for referential integrity of current truth.

---

## 25. Knowledge Assertion is separate

Knowledge `Entity / Assertion` remains a separate authority.

Specifically:

```text
assertions.value_json
assertions.value_text
assertions.value_type
```

do not become CSF-6 value storage.

No automatic synchronization is introduced between:

```text
Assertion
↔
CatalogProductSemanticValue
```

If future Knowledge enrichment proposes semantic values, it must enter through an
explicit review/application boundary and the CSF value manager.

Knowledge confidence/evidence/source fields are not copied into the CSF-6
current-truth table.

---

## 26. Audit authority

Current truth is the semantic-value row.

Historical mutation evidence is `AuditLog`.

Canonical V1 events:

```text
catalog_product.semantic_value_set
catalog_product.semantic_value_updated
catalog_product.semantic_value_cleared
```

Audit subject:

```text
CatalogProduct
```

Audit payload must preserve enough semantic context to understand the event without
consulting mutable current state alone:

```text
attribute_binding_id
product_schema_version_id
attribute_definition_id
attribute_definition_key
value_type
value_scope
canonical old value
canonical new value
measurement_unit_id / key when applicable
```

AuditLog is evidence, not the query source for current values.

Exact retry idempotency must not create duplicate audit evidence.

---

## 27. Transaction and locking law

`set(...)` and `clear(...)` are transactional.

At minimum, mutation must coordinate locks for:

```text
CatalogProduct
current ProductDefinition / schema resolution needed for consistency
exact AttributeBinding
existing CatalogProductSemanticValue row when present
```

The operation must revalidate current-profile membership inside the transaction.

The write and its audit evidence are atomic.

A concurrent reclassification or schema publication must not race past semantic
value validation.

Lock order must be deterministic across CSF managers to avoid avoidable
deadlocks.

---

## 28. Generic Catalog CRUD does not own semantic values

Ordinary `CatalogProduct` create/update endpoints do not directly mutate the
semantic-value table.

Category edits, brand/manufacturer edits, Knowledge synchronization, SKU/name
changes, Inventory operations, Commerce operations, or generic imports must not
silently create or reinterpret semantic values.

An import may only write semantic values in a future/explicit integration that
resolves a product's current profile and invokes the semantic-value domain
contract.

No `fillable` mass-assignment surface becomes semantic authority.

---

## 29. No defaults, requiredness, constraints, or computed values in V1

CSF-6 V1 stores explicit scalar values only.

It does not introduce:

```text
required attributes
default values
enumerated option sets
min/max constraints
regex constraints
conditional visibility rules
computed/formula values
derived values
multi-value arrays
localized values
confidence scores
source provenance
AI-generated values
```

Absence of a row does not violate a requiredness law because no such law exists yet.

These capabilities require later semantic contracts.

---

## 30. No generic polymorphic subject engine

CSF-6 V1 deliberately does not create:

```text
semantic_values
    subject_type
    subject_id
```

or any equivalent generic polymorphic value table.

The current runtime subject is known and concrete:

```text
CatalogProduct
```

A product-specific table gives explicit referential integrity and avoids forcing
future subject lifecycles into a premature abstraction.

Future scopes may reuse concepts, but their storage authority must be designed at
their own natural runtime boundaries.

---

## 31. Storefront / Network boundary

CSF-6 values are internal Catalog semantic truth.

They are not automatically public.

CSF-6 does not decide:

- which values are Storefront-visible;
- which values are searchable publicly;
- which values contribute to Discovery ranking;
- which values may be exported to Straleon Network;
- which values are customer-editable;
- which values are merchant-private.

Publication/visibility remains a later explicit projection policy.

---

## 32. UX law

Operators must not see or choose internal IDs such as:

```text
product_schema_version_id
attribute_binding_id
attribute_definition_id
measurement_dimension_id
```

during ordinary value editing.

The interaction layer should present human semantic labels, appropriate typed
controls, and only attributes relevant to the current product profile.

Examples:

```text
boolean      → simple toggle
date         → date control
measurement  → numeric field + fixed binding unit label
text         → text input
```

Advanced semantic machinery remains behind the interaction boundary.

---

## 33. Implementation surface authorized only after contract publication

After this contract is committed, pushed, and its natural CI is GREEN, CSF-6
implementation may introduce the minimum runtime surface needed for the V1 laws.

Expected implementation surface includes, subject to exact implementation review:

```text
CatalogProductSemanticValue model
catalog_product_semantic_values migration
CatalogProductSemanticValueManager
CatalogProductSemanticValueResolver
typed canonicalization/value DTO support as needed

CatalogProduct relation for semantic values

CatalogProductDefinitionAssignmentManager semantic-value reclassification guard
ProductSchemaVersionManager successor-publication semantic-value guard
```

Expected tests include:

```text
product semantic value foundation
all seven type contracts
absence / clear / idempotency
measurement binding-unit semantics
foreign-binding / stale-binding / non-product-scope rejection
current resolver behavior
reclassification blocker
successor-schema publication blocker
AuditLog evidence
concurrency-relevant lock/atomicity invariants where practical
```

No implementation file is authorized by the act of writing this contract alone.

---

## 34. Explicit V1 exclusions

CSF-6 V1 excludes:

```text
SupplierOffer semantic-value persistence
Variant runtime or values
InventoryUnit runtime or values
Lot/Batch runtime or values
serial/IMEI semantic values
multi-value attributes
nested/object values
JSON semantic payloads
value inheritance
organization-specific value overrides
category-derived values
Knowledge Assertion synchronization
automatic schema-value migration
automatic reclassification-value migration
unit conversion
Storefront publication policy
Network projection
AI enrichment
attribute required/default/constraint engine
P13.C
```

These exclusions are intentional architectural boundaries, not missing
implementation.

---

## 35. Acceptance invariants

CSF-6 V1 implementation is acceptable only if all of the following remain true:

1. Current semantic-value truth has one authority:
   `catalog_product_semantic_values`.
2. V1 persistence accepts only `value_scope=product`.
3. Every stored row references one exact `AttributeBinding`.
4. A value cannot be written outside the product's current effective profile.
5. `attribute_definition_id` alone never identifies stored meaning.
6. No generic JSON fallback exists.
7. Absence is represented by no row, not an all-null row.
8. Exactly one typed payload is populated.
9. Decimal and measurement semantics do not use binary float as authority.
10. Measurement unit comes from the binding, not the value row.
11. Exact retries are idempotent.
12. Clear is explicit and audited.
13. Reclassification fails closed while any product semantic values exist.
14. Successor schema publication fails closed while affected product semantic
    values exist.
15. No automatic value reinterpretation occurs across definitions or schemas.
16. Knowledge Assertion remains a separate authority.
17. Generic Catalog CRUD does not mutate semantic values directly.
18. AuditLog is evidence, never current truth.
19. Internal schema/binding IDs remain hidden from ordinary operator UX.
20. P13.C remains unopened.

---

## 36. Authorization boundary

This document freezes the CSF-6 V1 implementation contract.

Writing this document does not authorize runtime implementation.

Required sequence:

```text
CSF-6 RECON V1
    GREEN

CSF-6 RECON evidence reconciliation
    GREEN

this CSF-6 contract
    review
    docs-only checkpoint
    natural CI GREEN

only then:
    CSF-6 implementation V1
```

Until the contract checkpoint and its natural CI are GREEN:

```text
RUNTIME_AUTHORIZATION = NO
P13_C_STATUS = NOT_OPENED
```
