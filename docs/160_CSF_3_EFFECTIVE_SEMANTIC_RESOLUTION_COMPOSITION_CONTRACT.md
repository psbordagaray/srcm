# Straleon — CSF-3 Effective Semantic Resolution / Composition Contract V1

**Roadmap boundary:** P13.B / Catalog Semantic Foundation / CSF-3
**Contract state:** BINDING WHEN PUBLISHED
**Runtime implementation:** NOT INCLUDED IN THIS DOCUMENT CUT
**P13.C:** NOT OPENED
**Storefront/Network semantic runtime:** NOT OPENED

---

## 1. Purpose

CSF-3 defines the deterministic, explainable and fail-closed rules by which
Straleon derives an `EffectiveSemanticProfile` from the semantic authorities
already published by CSF-1 and CSF-2.

This contract does **not** create another source of truth.

`EffectiveSemanticProfile` is a derived read model.

It answers:

> Given one semantic product definition and one valid schema selection,
> what exact structural semantic contract is effective, and why?

It does not answer:

- which `CatalogProduct` is assigned to a `ProductDefinition`;
- what semantic values a product currently stores;
- which capabilities are active at runtime;
- which operation a user may perform;
- how variants are materialized;
- how measurement values convert between units;
- what Storefront or Network should publish.

Those remain later boundaries.

---

## 2. Governing laws

### 2.1 One canonical fact, one authority

CSF-3 MUST preserve the authorities already established:

- `ProductDefinition` owns semantic-kind identity;
- `ProductSchemaVersion` owns the historical semantic schema contract;
- `AttributeDefinition` owns reusable attribute identity;
- `AttributeBinding` owns type/scope/unit configuration for one exact schema version;
- `MeasurementUnit -> MeasurementDimension` owns semantic measurement topology;
- `ExactDecimal` remains the generic exact-decimal authority;
- `InventoryQuantity`, `InventoryBaseUnit` and `ProductPresentation` remain
  operational inventory-unit authorities;
- Knowledge / `TechnicalModel` retain universal technical identity,
  identifiers and compatibility authority;
- `ProductCategory` remains commercial/navigation taxonomy.

No resolver may silently copy those truths into a competing persistent model.

### 2.2 Derived, not persisted

`EffectiveSemanticProfile` MUST NOT become a source-of-truth table.

V1 MUST be derived on read from authoritative semantic records.

A future cache MAY exist only as a disposable acceleration layer whose cache key
is sufficient to identify every authoritative input revision. Cache state can
never become semantic authority.

### 2.3 Fail closed

Ambiguity, impossible state, conflicting authority or structurally invalid
semantic evidence MUST fail closed.

No "best effort", last-write-wins or silent winner is allowed for structural
semantic truth.

### 2.4 Deterministic ordering

Equivalent authoritative data MUST produce equivalent profile content and order.

Resolved attributes MUST be ordered by:

1. `AttributeDefinition.key`;
2. `AttributeDefinition.id` as deterministic tie-breaker.

Database row order is never semantic order.

---

## 3. EffectiveSemanticProfile V1

V1 is an immutable in-memory projection.

It MUST identify at minimum:

- resolution mode;
- `ProductDefinition.id`;
- `ProductDefinition.key`;
- `ProductSchemaVersion.id`;
- schema integer `version`;
- schema lifecycle status;
- `published_at`;
- ordered resolved attributes;
- profile-level provenance explaining why that schema was selected.

The profile MUST NOT carry mutable registry labels as historical semantic facts.

Therefore V1 structural profile MUST NOT treat these as historical authority:

- `ProductDefinition.name`;
- `ProductDefinition.description`;
- `AttributeDefinition.name`;
- `AttributeDefinition.description`;
- `MeasurementUnit.name`;
- `MeasurementUnit.symbol`;
- `MeasurementUnit.description`;
- `MeasurementDimension.name`;
- `MeasurementDimension.description`.

Those fields may be joined later as current display metadata, but must remain
explicitly non-historical and non-authoritative.

---

## 4. Resolved attribute contract

Each effective attribute MUST identify at minimum:

- `AttributeBinding.id`;
- `AttributeDefinition.id`;
- immutable `AttributeDefinition.key`;
- `AttributeValueType`;
- `AttributeValueScope`;
- optional `MeasurementUnit.id`;
- optional immutable `MeasurementUnit.key`;
- optional `MeasurementDimension.id`;
- optional immutable `MeasurementDimension.key`;
- attribute-level provenance.

For non-`measurement` bindings:

- measurement unit MUST be absent;
- measurement dimension MUST be absent.

For `measurement` bindings:

- measurement unit MUST exist;
- measurement dimension MUST exist;
- the dimension MUST be reached through the selected unit;
- the binding MUST NOT duplicate a separate dimension authority.

The resolver reports `value_scope` as semantic metadata only.

It MUST NOT activate storage/runtime for:

- `variant`;
- `inventory_unit`;
- `lot_or_batch`;
- `supplier_offer`.

Those scopes remain declarations until their own runtime boundaries are opened.

---

## 5. Resolution modes

V1 defines exactly two runtime-safe semantic resolution modes.

### 5.1 CURRENT_PUBLISHED

Input authority:

```text
ProductDefinition
```

Selection law:

1. reload the definition from authoritative persistence;
2. reject a RETIRED definition;
3. ACTIVE and DEPRECATED definitions may resolve an existing current profile;
4. query all PUBLISHED schemas for that exact definition in one selection step;
5. require exactly one PUBLISHED schema;
6. zero PUBLISHED schemas => fail closed;
7. more than one PUBLISHED schema => fail closed;
8. resolve only the exact selected schema's own bindings.

DEPRECATED remains resolvable because existing products and historical commercial
records may still depend on its current published semantic contract.

Whether a DEPRECATED definition may receive **new** product assignments is a
later CSF-5 assignment policy and is not decided here.

### 5.2 EXACT_HISTORICAL

Input authority:

```text
ProductSchemaVersion
```

Selection law:

1. reload the exact schema version from authoritative persistence;
2. reload its exact `ProductDefinition`;
3. reject DRAFT;
4. accept PUBLISHED;
5. accept DEPRECATED when `published_at` is present;
6. accept RETIRED only when `published_at` is present;
7. a RETIRED abandoned draft with no `published_at` is not historical published
   semantic authority and MUST fail closed;
8. resolve only that exact version's own bindings.

A RETIRED `ProductDefinition` may still participate in `EXACT_HISTORICAL`
resolution because retirement cannot erase published history.

### 5.3 No draft preview in V1

DRAFT preview is intentionally outside CSF-3 V1.

If a later administration UI needs preview, it must receive an explicit
`DRAFT_PREVIEW` contract rather than silently reusing published/historical
resolution semantics.

---

## 6. Composition semantics

### 6.1 Published schema is self-contained

CSF-2 already establishes copy-on-write version evolution:

```text
current PUBLISHED schema
        ↓ createDraft()
atomic clone of bindings
        ↓
new DRAFT
        ↓ mutate draft only
        ↓
new PUBLISHED schema
```

Therefore:

> A published schema is a self-contained semantic snapshot.

CSF-3 MUST NOT dynamically inherit bindings from previous schema versions.

No runtime chain traversal is allowed:

```text
v3 -> v2 -> v1
```

for semantic completion.

The exact selected schema alone owns its binding set.

### 6.2 No implicit cross-definition composition

V1 MUST NOT merge multiple `ProductDefinition` schemas.

No inheritance tree, mixin, trait, parent-definition or category-derived
composition is introduced by CSF-3.

If cross-definition composition is needed later, it requires an explicit
contract with conflict and provenance rules.

### 6.3 Empty profile is valid

A published schema with zero bindings resolves to a valid profile with an empty
attribute list.

Absence of bindings is not ambiguity.

---

## 7. Historical stability

Published semantic meaning must remain resolvable after registry lifecycle
changes.

Publication already validates that referenced attribute definitions,
measurement units and dimensions are valid at publication time.

After publication:

- binding identity/configuration is immutable;
- attribute semantic key is immutable;
- measurement-unit semantic key is immutable;
- measurement-unit -> dimension relation is immutable;
- measurement-dimension semantic key is immutable;
- semantic registry records are not physically deleted.

Therefore later lifecycle transitions such as ACTIVE -> DEPRECATED -> RETIRED
MUST NOT retroactively make a previously published schema structurally
unresolvable.

Current registry status may influence **future authoring/publication** rules, but
it is not allowed to rewrite historical profile truth.

If an immutable structural reference is missing or inconsistent despite database
constraints, the resolver MUST treat it as corruption and fail closed.

---

## 8. Provenance and explainability

Every profile must be explainable without reading application internals.

### 8.1 Profile provenance

Profile provenance MUST identify:

- resolution mode;
- requested semantic authority;
- selected `ProductDefinition`;
- selected `ProductSchemaVersion`;
- selection rule:
  - `exactly_one_current_published_schema`, or
  - `exact_historical_published_schema`.

### 8.2 Attribute provenance

Each resolved attribute MUST identify:

- exact schema version id;
- exact binding id;
- exact attribute-definition id/key;
- measurement-unit id/key when applicable;
- measurement-dimension id/key when applicable;
- resolution rule:
  - `declared_by_exact_schema_binding`.

Provenance is derived evidence, not duplicated persistent truth.

### 8.3 No opaque winner

If future composition introduces more than one candidate source for the same
fact, the result must be able to explain:

```text
candidate sources
→ applicable authority rule
→ rejected candidates
→ selected candidate
```

V1 has one structural layer, so no hidden precedence winner exists.

---

## 9. Conflict and ambiguity handling

The resolver MUST fail closed when any of the following occurs:

1. CURRENT_PUBLISHED finds zero published schemas;
2. CURRENT_PUBLISHED finds more than one published schema;
3. EXACT_HISTORICAL receives DRAFT;
4. EXACT_HISTORICAL receives an abandoned RETIRED draft with no `published_at`;
5. a binding references a missing `AttributeDefinition`;
6. duplicate effective attribute identity is observed;
7. binding `value_type` is outside `AttributeValueType`;
8. binding `value_scope` is outside `AttributeValueScope`;
9. non-measurement binding has a measurement unit;
10. measurement binding lacks a measurement unit;
11. referenced measurement unit is missing;
12. referenced measurement dimension is missing;
13. unit-to-dimension topology is inconsistent;
14. selected schema does not belong to the expected definition;
15. any future source attempts to override a structural fact without an explicit
    override contract.

No conflict may be resolved by creation/update timestamp, row order, highest id,
lexical accident or "most specific" guess.

---

## 10. Authority and precedence law

CSF-3 distinguishes two categories.

### 10.1 Structural semantic facts

Structural facts are not overrideable by generic precedence.

They include:

- semantic product-definition identity;
- exact schema version identity;
- attribute semantic identity;
- attribute value type;
- attribute value scope;
- measurement unit identity;
- measurement dimension topology.

For these facts:

> authority beats precedence.

There is exactly one declared authority.

A conflict is an error, not an override opportunity.

### 10.2 Future policy/presentation facts

Later CSF boundaries may introduce facts that are intentionally overrideable,
for example organization policy, product-specific policy or presentation
preferences.

Their law will be:

1. a field must explicitly declare that it is overrideable;
2. allowed source layers must be explicit;
3. precedence must be field-specific and contract-defined;
4. every winning value must retain provenance;
5. `unset` means inherit only when the field contract explicitly says so;
6. unknown/unsupported override source fails closed;
7. no global "last write wins";
8. no global "most specific always wins".

Potential future layering such as:

```text
base schema
→ organization overlay
→ product override
```

is RESERVED, not implemented by CSF-3 V1.

Its existence must not be inferred from generic organization/product data.

---

## 11. Category, Knowledge and Inventory boundaries

### 11.1 ProductCategory

`ProductCategory` MUST NOT participate in semantic inheritance or precedence.

Moving a product between categories must never alter semantic kind or effective
schema implicitly.

### 11.2 Knowledge / TechnicalModel

Knowledge may answer:

- concrete technical identity;
- identifiers;
- compatibility;
- universal entity relationships.

CSF-3 MUST NOT import those facts as semantic overrides.

A future semantic consumer may coordinate both authorities, but neither replaces
the other.

### 11.3 Inventory

CSF-3 may expose that an attribute has `inventory_unit` or `lot_or_batch` scope,
but MUST NOT become inventory quantity/unit authority.

It MUST NOT replace or reinterpret:

- `InventoryQuantity`;
- `InventoryBaseUnit`;
- `ProductPresentation`;
- inventory selection/rotation policies.

---

## 12. Storefront / Network boundary

Storefront and Network are consumers/projections of authorized domain truth.

They are not semantic composition sources.

CSF-3 MUST NOT:

- create Storefront schema runtime;
- create Network semantic runtime;
- publish public payloads;
- duplicate price/stock/availability;
- let a public projection override Catalog semantics.

Those remain later boundaries.

---

## 13. CatalogProduct assignment boundary

CSF-3 still does not create:

```text
CatalogProduct -> ProductDefinition
```

assignment.

The resolver operates on explicit semantic authorities:

```text
ProductDefinition
or
ProductSchemaVersion
```

CSF-5 will later define assignment, reclassification, evidence and migration
rules.

No `CatalogProduct` model/migration/controller/request change is authorized by
CSF-3 V1.

---

## 14. Capability and contextual interaction boundary

CSF-3 does not yet resolve runtime capabilities.

It also does not produce `ContextualInteractionProfile`.

The canonical separation remains:

```text
semantic structure
        ↓
EffectiveSemanticProfile

EffectiveSemanticProfile
+ OperationContext
+ Permissions
+ CurrentProductState
        ↓
future ContextualInteractionProfile
```

CSF-4 may add capability declarations/policy composition.

Contextual interaction remains later.

---

## 15. V1 implementation surface

After this contract is published and its docs-only checkpoint/CI is GREEN, the
maximum initially authorized productive surface for CSF-3 V1 is:

```text
app/Enums/SemanticProfileResolutionMode.php

app/Domain/Catalog/EffectiveSemanticProfile.php
app/Domain/Catalog/EffectiveSemanticAttribute.php
app/Domain/Catalog/EffectiveSemanticProfileProvenance.php
app/Domain/Catalog/EffectiveSemanticAttributeProvenance.php
app/Domain/Catalog/EffectiveSemanticProfileResolver.php

tests/Feature/Catalog/EffectiveSemanticProfileResolverTest.php
```

This is an upper bound, not an obligation to modify every file.

### Explicitly not authorized

No database migration.

No new source-of-truth model/table.

No `CatalogProduct` changes.

No `ProductCategory` changes.

No Knowledge changes.

No Inventory changes.

No Storefront/Network changes.

No capability runtime.

No semantic value storage.

No variant runtime.

No measurement conversion engine.

No P13.C.

---

## 16. Required V1 tests

The implementation cut MUST prove at minimum:

1. CURRENT_PUBLISHED resolves exactly one published schema;
2. CURRENT_PUBLISHED rejects zero published schemas;
3. CURRENT_PUBLISHED rejects multiple published schemas;
4. CURRENT_PUBLISHED resolves an existing DEPRECATED definition;
5. CURRENT_PUBLISHED rejects a RETIRED definition;
6. EXACT_HISTORICAL resolves PUBLISHED;
7. EXACT_HISTORICAL resolves DEPRECATED published history;
8. EXACT_HISTORICAL resolves RETIRED published history;
9. EXACT_HISTORICAL rejects DRAFT;
10. EXACT_HISTORICAL rejects abandoned RETIRED draft without `published_at`;
11. resolver uses only the selected schema's own bindings;
12. resolver never traverses previous schema versions;
13. attribute order is deterministic;
14. measurement topology is resolved through `MeasurementUnit`;
15. non-measurement + unit fails closed;
16. measurement without unit fails closed;
17. invalid value type/scope fails closed;
18. missing/inconsistent structural authority fails closed;
19. provenance identifies exact schema/binding/registry sources;
20. later registry deprecation/retirement does not rewrite published historical
    structural truth;
21. profile carries no dynamic attribute values;
22. profile activates no variant/inventory/lot/supplier-offer runtime;
23. no repository/domain write occurs as part of resolution.

Tests may add tighter cases but may not weaken these.

---

## 17. Acceptance criteria

CSF-3 V1 is acceptable only if:

```text
same authoritative input
→ same selected schema
→ same ordered structural facts
→ same provenance
```

and:

```text
ambiguous/corrupt authority
→ explicit failure
```

and never:

```text
ambiguous/corrupt authority
→ silent winner
```

The result must remain a derived semantic profile, not a second source of truth.

---

## 18. Forward compatibility

This contract intentionally leaves room for CSF-4/5/6 without pre-implementing
them.

Future extensions may compose:

- capability declarations;
- organization overlays;
- product overrides;
- typed semantic values;
- contextual policies.

But each new source must enter through an explicit authority/precedence contract.

CSF-3 V1 establishes the invariant that future power may be layered without
making the effective result opaque.

---

## 19. Final boundary statement

After CSF-3:

```text
ProductDefinition
        ↓
exact ProductSchemaVersion
        ↓
exact AttributeBindings
        ↓
deterministic structural resolution
        ↓
EffectiveSemanticProfile
        ↓
explainable provenance
```

Nothing in this contract opens:

```text
CatalogProduct assignment
semantic value storage
capability runtime
variant runtime
requirement gates
measurement conversion
Storefront/Network semantic runtime
P13.C
```

Those boundaries remain closed until separately authorized.
