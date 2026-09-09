# CSF-5 — CatalogProduct Assignment + Controlled Reclassification Contract V1

**Document:** `docs/162_CSF_5_CATALOGPRODUCT_ASSIGNMENT_CONTROLLED_RECLASSIFICATION_CONTRACT.md`
**Status:** FROZEN IMPLEMENTATION CONTRACT — RUNTIME NOT YET AUTHORIZED
**Product:** Straleon
**Program:** P13.B — Catalog Semantic Foundation
**Depends on:** ADR 157 + CSF-1/2/3/4 published foundations
**Baseline:** `feature/core-entity @ 7cb8e851ed30d5fc8c63483e4cd6b21e61e9f9b4`
**Effective date:** 2026-09-09

---

## 1. Purpose

CSF-5 connects the existing merchant-facing `CatalogProduct` to the canonical
semantic kind represented by `ProductDefinition`.

It answers:

> **Which semantic ProductDefinition currently classifies this CatalogProduct?**

and defines the only safe V1 path for changing that classification.

CSF-5 does **not** create semantic values, variants, Storefront publication,
organization-specific semantic classification, lot/serial migration, or a second
product-history authority.

---

## 2. Binding authority boundaries

```text
CatalogProduct       = current merchant-facing catalog product identity
ProductDefinition    = canonical semantic kind
ProductSchemaVersion = versioned semantic contract for one ProductDefinition
ProductCategory      = commercial/navigation taxonomy
Entity / TechnicalModel / Identifier = Knowledge authority
Inventory / Commerce / Service records = operational truth in those domains
```

Therefore:

```text
CatalogProduct ≠ ProductDefinition
ProductCategory ≠ ProductDefinition
ProductDefinition ≠ ProductSchemaVersion
ProductDefinition ≠ Entity
ProductDefinition ≠ TechnicalModel
```

A category edit never changes semantic classification.

---

## 3. Current assignment authority

The V1 current-truth authority is a nullable FK on `catalog_products`:

```text
catalog_products.product_definition_id
    nullable
    FK → catalog_product_definitions.id
```

This field is the **only current semantic assignment truth** for a CatalogProduct.

CSF-5 must not create a second table that independently claims which
ProductDefinition is current.

No organization-specific assignment table is introduced.

---

## 4. UNCLASSIFIED and GENERIC_GOOD

```text
product_definition_id = NULL
    → UNCLASSIFIED
```

UNCLASSIFIED means semantic classification has not yet been established.

It does not mean generic good, fallback definition, inferred definition, or invalid
product.

Existing CatalogProducts migrate with `NULL`.

There is no automatic backfill from category, name, SKU, brand, manufacturer,
Knowledge Entity, TechnicalModel, identifiers, inventory behavior, or similar
products.

An intentionally generic product must instead be assigned explicitly to a real
`ProductDefinition` representing generic-good semantics.

CSF-5 does not seed or require a specific generic-good key.

---

## 5. Assignment binds to ProductDefinition, never schema version

`CatalogProduct` stores only:

```text
product_definition_id
```

It must not store:

```text
product_schema_version_id
current_schema_version_id
semantic_profile_id
```

Current runtime resolution is:

```text
CatalogProduct
    ↓ product_definition_id
ProductDefinition
    ↓
EffectiveSemanticProfileResolver::currentPublished(...)
    ↓
current published ProductSchemaVersion
```

A ProductDefinition may evolve through schema versions without reclassifying every
CatalogProduct.

Historical facts that need an exact schema version must preserve that provenance
in their own future contracts.

---

## 6. Valid target for assignment/reclassification

A ProductDefinition may receive a new assignment only when:

1. it exists;
2. its semantic key is valid under the existing SemanticKey law;
3. status is exactly `ACTIVE`;
4. it resolves to exactly one current `PUBLISHED` ProductSchemaVersion.

A `DEPRECATED` definition may remain assigned to existing products but cannot
receive new assignments or be a reclassification target.

A `RETIRED` definition cannot receive or retain normal current assignments.

---

## 7. Lifecycle integration

Deprecating a ProductDefinition with assigned products is allowed.

This creates a migration window:

```text
existing assignments remain readable
new assignments are forbidden
```

Retirement must fail closed while any CatalogProduct still references that
ProductDefinition.

Therefore CSF-5 implementation must extend
`ProductDefinitionManager::retire(...)` with a current-assignment guard.

---

## 8. Initial classification

Initial classification is:

```text
UNCLASSIFIED → ACTIVE ProductDefinition
```

It is not reclassification because no prior ProductDefinition truth existed.

The operation must be explicit, transactional, row-locked, target-validated,
audited and independent of ProductCategory.

Initial classification may be applied to an existing CatalogProduct even when
Inventory, Commerce, Service, purchase, price or Knowledge history already exists.

Those downstream rows remain untouched. Classification establishes previously
missing semantic identity and does not rewrite historical operational truth.

---

## 9. Generic product CRUD does not own semantic assignment

The ordinary Catalog product create/update path must not silently set or change
`product_definition_id`.

Specifically:

- `CatalogProductKnowledgeManager` remains Knowledge synchronization authority;
- category edits remain category edits;
- generic Store/Update CatalogProduct requests do not become semantic
  reclassification transports;
- imports do not infer ProductDefinition in CSF-5 V1.

Semantic classification is performed only through a dedicated Catalog domain
assignment manager.

---

## 10. Dedicated assignment manager

CSF-5 should introduce:

```text
CatalogProductDefinitionAssignmentManager
```

Conceptual operations:

```text
classify(CatalogProduct, ProductDefinition)
reclassify(CatalogProduct, ProductDefinition, reason)
```

There is no V1 `unclassify(...)`.

Once a semantic definition is present, correction occurs by controlled
reclassification to another valid definition, preserving audit evidence.

---

## 11. Idempotency

Exact retries are safe.

If the target ProductDefinition is already current:

- return the unchanged CatalogProduct;
- do not write again;
- do not create duplicate audit evidence.

If `classify(...)` is called while a different definition is current, it fails and
requires explicit `reclassify(...)`.

---

## 12. Controlled reclassification

Reclassification is:

```text
ProductDefinition A → ProductDefinition B
```

V1 requires:

1. an existing current assignment;
2. a different target;
3. target status `ACTIVE`;
4. exactly one current `PUBLISHED` target schema;
5. a nonblank reason;
6. transactional row locks;
7. all V1 guards GREEN;
8. immutable audit evidence;
9. no downstream data rewrite.

A category edit is never a reclassification request.

---

## 13. Domain history is never rewritten

Reclassification must not mutate or relabel:

- InventoryMovementLine;
- InventoryBalance;
- InventoryReservation;
- CommerceSaleLine;
- ServicePartRequirement;
- Purchase records;
- prices;
- supplier offers;
- Knowledge Entity;
- identifiers;
- TechnicalModel;
- ProductPresentation;
- historical audit records.

Those facts remain owned by their original domains.

---

## 14. V1 operational-commitment guards

Generic historical references to a CatalogProduct do not become a second semantic
classification authority.

However V1 fails closed when current or immutable **capability-specific evidence**
would require migration or compatibility analysis that CSF-5 does not implement.

At minimum, reclassification is blocked by:

1. any effective `InventoryReservation` for the product;
2. any `FractionalContainer` for the product;
3. any `VariableQuantityFulfillment` for the product;
4. any `FulfillmentPreference` linked through a reservation for the product.

These are conservative blockers. CSF-5 does not transform those records.

Ordinary historical InventoryMovement, CommerceSale or ServicePartRequirement
references are not rewritten and are not by themselves treated as a semantic
assignment source.

---

## 15. Inventory, lot and serial boundary

Physical inventory truth remains Inventory-owned.

CSF-5 does not recompute balances or mutate movement history.

Lot, batch, serial/IMEI and future inventory-unit semantic evidence must become
reclassification guards when those runtime structures exist.

A future feature may not introduce such semantic-bearing evidence without
integrating it into controlled reclassification.

CSF-5 does not invent generic lot/serial migration machinery.

---

## 16. CSF-6 semantic values boundary

CSF-5 does not persist attribute values.

Before CSF-6 semantic values are enabled, controlled reclassification must be
extended so persisted semantic values cannot be silently reinterpreted.

Future minimum law:

```text
semantic values exist
    → compatible migration / review / discard decision required
```

CSF-5 V1 does not create semantic-value tables, migration tables, generic JSON
migration payloads or value coercion.

---

## 17. Variant boundary

Variant runtime is outside CSF-5.

When variants exist, variant structure becomes semantic-bearing evidence and must
integrate with controlled reclassification before shipping.

No placeholder Variant runtime is introduced here.

---

## 18. Storefront / publication boundary

The RECON found no current CatalogProduct-level Storefront publication authority
inside this CSF surface.

CSF-5 therefore does not invent one.

Future public publication must block or explicitly participate in
reclassification so public semantics cannot change silently.

`CatalogProduct.active` is not Storefront publication state.

---

## 19. Auditability without a second authority

Current semantic truth is:

```text
catalog_products.product_definition_id
```

History is recorded through existing immutable `AuditLog`.

No assignment-history table is created.

Mutating operations record through `AuditRecorder` inside the same transaction.

Canonical events:

```text
catalog_product.semantic_definition_assigned
catalog_product.semantic_definition_reclassified
```

Audit evidence should include:

```text
old product_definition_id
old product_definition_key
new product_definition_id
new product_definition_key
target current product_schema_version_id
reclassification reason when applicable
```

AuditLog is evidence only and is never queried to determine current assignment.

---

## 20. Transaction and concurrency law

Classification/reclassification executes in one database transaction.

The CatalogProduct is locked before current assignment is evaluated.

Relevant ProductDefinition rows are locked before final status validation.

The target current-published schema is validated inside that protected
transactional decision.

Assignment mutation and audit insert are atomic:

```text
assignment succeeds + audit succeeds
or
neither persists
```

---

## 21. CatalogProduct model boundary

CSF-5 adds a relationship such as:

```text
CatalogProduct::productDefinition()
```

`product_definition_id` must not become a casually mass-assignable generic form
field.

A reverse relation from ProductDefinition to CatalogProducts may be added for
lifecycle guards.

---

## 22. Product-level semantic resolver bridge

CSF-5 should add a thin bridge:

```text
CatalogProductSemanticProfileResolver::current(CatalogProduct)
```

Behavior:

```text
product_definition_id = NULL
    → explicit UNCLASSIFIED / no EffectiveSemanticProfile

assigned definition
    → delegate to EffectiveSemanticProfileResolver::currentPublished(...)
```

The bridge does not persist a profile, apply organization capability policy, pin a
schema version, infer a definition, or mutate the product.

Corrupt assigned state fails closed.

---

## 23. Organization policy boundary

ProductDefinition assignment is not organization policy.

CSF-4 organization capability policy may configure capabilities after semantic
resolution, but cannot choose or replace ProductDefinition.

CSF-5 introduces no organization semantic classification override, category
semantic override, or product-location semantic assignment.

---

## 24. ProductCategory remains non-semantic

Absolute invariant:

```text
CatalogProduct.product_category_id changes
    ≠
ProductDefinition changes
```

No observer, controller, import path, job, resolver or fallback may derive
ProductDefinition from ProductCategory.

A future suggestion may use category as a hint, but a hint is not assignment.

---

## 25. Knowledge boundary

CSF-5 does not alter CatalogProduct ↔ Knowledge synchronization.

`CatalogProductKnowledgeManager` continues to own Product/Entity/identifier link
integrity.

Semantic classification does not replace Knowledge Entity, rewrite identifiers,
infer TechnicalModel or derive ProductDefinition automatically from Knowledge.

---

## 26. UX law

Ordinary product management remains simple.

The normal edit form must not expose schema IDs, registry lifecycle, provenance,
precedence or migration machinery.

UNCLASSIFIED is a legitimate temporary state.

When surfaced, initial classification should be an understandable **product type**
choice.

Controlled reclassification is an advanced/high-risk action and is not a casual
field in the ordinary edit form.

A future UI should show current type in human language, hide reclassification
unless authorized, require explicit reason, explain blockers in ordinary language
and never imply that category changes product type.

CSF-5 foundation does not require shipping that UI.

---

## 27. Authorization boundary

The Catalog manager validates semantic correctness.

Actor authorization remains an Authorization/transport concern.

SemanticCapabilityDefinition is not an actor permission.

A future HTTP/UI transport must enforce the appropriate existing authorization
authority before invoking reclassification.

---

## 28. Persistence V1

Required persistence:

```text
catalog_products
    + product_definition_id nullable indexed FK
```

Required constraints:

- FK to `catalog_product_definitions.id`;
- restrictive relationship;
- existing rows remain NULL;
- no category-derived migration.

V1 must not add:

```text
product_schema_version_id
semantic_profile_json
assignment_status
assignment_source polymorphism
generic reclassification payload JSON
organization_id for semantic assignment
product_definition_assignment history table
```

---

## 29. Expected implementation surface

The implementation-surface RECON should evaluate the smallest surface consistent
with this contract.

Expected candidates:

```text
database migration adding catalog_products.product_definition_id

app/Models/CatalogProduct.php
app/Models/ProductDefinition.php
app/Domain/Catalog/ProductDefinitionManager.php

app/Domain/Catalog/CatalogProductDefinitionAssignmentManager.php
app/Domain/Catalog/CatalogProductSemanticProfileResolver.php

focused Catalog feature tests
```

Existing `AuditRecorder` / `AuditLog` are reused, not duplicated.

`CatalogProductKnowledgeManager` should remain semantically unchanged unless a
demonstrated compatibility adjustment is required.

---

## 30. Required focal laws

Implementation tests must prove at minimum:

1. existing products migrate as UNCLASSIFIED with no inferred backfill;
2. explicit initial classification writes the direct FK only;
3. target must be ACTIVE and have exactly one current PUBLISHED schema;
4. DEPRECATED and RETIRED definitions reject new assignments;
5. classification does not change category or Knowledge links;
6. exact retry is idempotent;
7. `classify()` refuses changing one definition into another;
8. `reclassify()` requires a different target and nonblank reason;
9. reclassification writes current FK and immutable audit evidence atomically;
10. there is no normal unclassify operation;
11. effective InventoryReservation blocks reclassification;
12. FractionalContainer blocks reclassification;
13. VariableQuantityFulfillment blocks reclassification;
14. linked FulfillmentPreference blocks reclassification;
15. historical domain rows are not rewritten;
16. deprecation may retain existing assignments;
17. retirement fails while any CatalogProduct remains assigned;
18. category changes never alter ProductDefinition;
19. product-level resolver returns UNCLASSIFIED cleanly for NULL;
20. assigned product resolution delegates to CSF-3 and fails closed on corrupt
    semantic state;
21. organization policy cannot manufacture or replace assignment.

---

## 31. Explicitly out of scope

CSF-5 V1 does not implement:

- dynamic semantic values;
- value migration;
- Variant runtime;
- lot/serial migration;
- Storefront publication;
- Network semantic projection;
- AI classification;
- category-based automatic classification;
- product-location semantic assignment;
- organization-specific ProductDefinition assignment;
- schema-version pinning;
- generic override engines;
- P13.C.

---

## 32. Implementation authorization gate

This document must be published as a docs-only checkpoint and its natural CI must
complete GREEN before CSF-5 implementation source writes are authorized.

After publication, the next permitted step is:

```text
P13_B_CSF5_IMPLEMENTATION_SURFACE_RECON_V1_READ_ONLY
```

That RECON must confirm exact migration timestamp, existing file hashes,
collision-free class/test names, audit integration surface and minimal regression
set before any implementation write.

Production remains fail-closed.
P13.C remains unopened.
