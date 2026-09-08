# CSF-1 — Semantic Registry Foundation Contract

**Document:** `158_CSF_1_SEMANTIC_REGISTRY_FOUNDATION_CONTRACT.md`
**Status:** IMPLEMENTATION CONTRACT — FROZEN FOR CSF-1
**Parent ADR:** `157_ADR_CATALOG_SEMANTIC_FOUNDATION.md`
**Program:** P13.B horizontal Catalog foundation
**Runtime Network activation:** NO
**Effective date:** 2026-09-08

---

## 1. Purpose

CSF-1 creates the minimum canonical registry required by Catalog Semantic
Foundation.

It introduces exactly three semantic concepts:

1. `ProductDefinition`;
2. `ProductSchemaVersion`;
3. `AttributeDefinition`.

This cut establishes identity, lifecycle and schema-version history only.

It intentionally does **not** assign a definition to `CatalogProduct` and does
not yet store semantic attribute values.

---

## 2. Exact scope

### New enums

```text
app/Enums/ProductDefinitionStatus.php
app/Enums/ProductSchemaStatus.php
app/Enums/AttributeDefinitionStatus.php
```

### New models

```text
app/Models/ProductDefinition.php
app/Models/ProductSchemaVersion.php
app/Models/AttributeDefinition.php
```

### New domain services

```text
app/Domain/Catalog/SemanticKey.php
app/Domain/Catalog/ProductDefinitionManager.php
app/Domain/Catalog/ProductSchemaVersionManager.php
app/Domain/Catalog/AttributeDefinitionManager.php
```

### New migrations

```text
database/migrations/<timestamp>_create_catalog_product_definitions_table.php
database/migrations/<timestamp>_create_catalog_product_schema_versions_table.php
database/migrations/<timestamp>_create_catalog_attribute_definitions_table.php
```

### New focused tests

```text
tests/Feature/Catalog/ProductDefinitionFoundationTest.php
tests/Feature/Catalog/ProductSchemaVersionFoundationTest.php
tests/Feature/Catalog/AttributeDefinitionFoundationTest.php
```

### Canonical documents

```text
docs/157_ADR_CATALOG_SEMANTIC_FOUNDATION.md
docs/158_CSF_1_SEMANTIC_REGISTRY_FOUNDATION_CONTRACT.md
```

The documents are published before the source implementation cut.

---

## 3. Explicitly out of scope

CSF-1 MUST NOT add:

```text
CatalogProduct → ProductDefinition assignment
AttributeBinding
attribute data_type
measurement_dimension
unit binding
allowed value scopes
dynamic attribute values
capability declarations
effective semantic resolver
organization overlays
variant model
reclassification runtime
requirement gates
contextual interaction resolver
Storefront semantic projection
Network semantic projection
AI extraction/classification
controllers
routes
Blade/UI
public API
seeded canonical product definitions
```

It also MUST NOT modify existing CatalogProduct/ProductCategory behavior.

---

# 4. ProductDefinition contract

## 4.1 Table

Canonical table:

```text
catalog_product_definitions
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

Forbidden in this table:

```text
organization_id
category_id
parent_definition_id
current_schema_version_id
attributes JSON
capabilities JSON
deleted_at
```

---

## 4.2 Semantic key

`key` is canonical semantic identity.

Rules:

- lowercase ASCII;
- dot-delimited segments;
- underscore permitted inside a segment;
- no spaces;
- maximum 160 characters;
- no silent normalization;
- globally unique;
- immutable after creation;
- never reused after retirement.

Examples:

```text
straleon.catalog.smartphone
straleon.catalog.tyre
straleon.catalog.fastener.bolt
```

The initial validator should require at least two dot-separated segments.

Canonical shape:

```regex
^[a-z0-9]+(?:_[a-z0-9]+)*(?:\.[a-z0-9]+(?:_[a-z0-9]+)*)+$
```

Invalid user input fails closed.

The manager MUST NOT silently lowercase, trim internal whitespace into another
meaning, replace separators or repair an invalid key.

---

## 4.3 Status enum

Persisted values:

```text
active
deprecated
retired
```

Laravel backed enum cases follow repository convention:

```text
Active
Deprecated
Retired
```

Allowed transition:

```text
ACTIVE → DEPRECATED → RETIRED
```

No reverse transitions.

`RETIRED` is terminal.

---

## 4.4 ProductDefinition rules

1. definitions are global/canonical, not organization-owned;
2. key is immutable;
3. key uniqueness is database-backed;
4. physical deletion is forbidden;
5. retirement does not free the key;
6. name/description are metadata, not identity;
7. metadata may be corrected while status is not `RETIRED`;
8. a retired definition is immutable;
9. a deprecated or retired definition cannot create a new schema draft;
10. only an active definition may publish a schema version;
11. ProductDefinition has no ProductCategory ownership relation in CSF-1;
12. ProductDefinition has no CatalogProduct relation in CSF-1;
13. ProductDefinition is not a Knowledge Object Master or TechnicalModel.

---

## 4.5 Domain manager

Required surface:

```text
ProductDefinitionManager::create(
    string $key,
    string $name,
    ?string $description = null
)

ProductDefinitionManager::updateMetadata(...)

ProductDefinitionManager::deprecate(...)

ProductDefinitionManager::retire(...)
```

Forbidden surface:

```text
renameKey(...)
delete(...)
forceDelete(...)
restore(...)
reactivate(...)
```

The manager is the normal lifecycle authority.

The model/database remain defensive against direct invalid mutation.

---

# 5. ProductSchemaVersion contract

## 5.1 Table

Canonical table:

```text
catalog_product_schema_versions
```

Fields:

```text
id BIGINT PK
product_definition_id BIGINT NOT NULL
version UNSIGNED INTEGER NOT NULL
status VARCHAR(32) NOT NULL
change_summary TEXT NULL
published_at TIMESTAMP NULL
deprecated_at TIMESTAMP NULL
retired_at TIMESTAMP NULL
created_at
updated_at
```

Foreign key:

```text
product_definition_id
→ catalog_product_definitions.id
RESTRICT ON DELETE
```

Constraints:

```text
UNIQUE(product_definition_id, version)
INDEX(product_definition_id, status)
```

Forbidden in CSF-1:

```text
attributes JSON
compiled_schema
capability_profile
checksum
parent_schema_version_id
organization_id
deleted_at
```

---

## 5.2 Schema status enum

Persisted values:

```text
draft
published
deprecated
retired
```

Enum cases:

```text
Draft
Published
Deprecated
Retired
```

Allowed transitions:

```text
DRAFT → PUBLISHED
DRAFT → RETIRED
PUBLISHED → DEPRECATED
DEPRECATED → RETIRED
```

No reverse transition.

`RETIRED` is terminal.

---

## 5.3 Version numbering

Per ProductDefinition:

- first version is `1`;
- later versions are monotonically increasing;
- next draft uses `max(version) + 1`;
- abandoned/retired draft numbers are never recycled;
- database uniqueness is a backstop;
- allocation must be concurrency-safe.

Version is immutable once created.

ProductDefinition link is immutable once created.

---

## 5.4 Draft rules

1. only an ACTIVE ProductDefinition can create a draft;
2. at most one DRAFT may exist per definition through domain coordination;
3. `change_summary` may change while DRAFT;
4. DRAFT may be published;
5. DRAFT may be abandoned by transitioning directly to RETIRED;
6. draft rows are never physically deleted;
7. a draft number is never reused.

CSF-1 may technically publish an empty semantic schema because AttributeBinding
belongs to CSF-2. This is intentional.

No production canonical schemas are seeded in CSF-1.

---

## 5.5 Published rules

A published schema version is historical semantic truth.

After publication:

- `product_definition_id` is immutable;
- `version` is immutable;
- `change_summary` is frozen;
- `published_at` is fixed;
- semantic content is immutable;
- status may only move to DEPRECATED.

Publishing a new draft must atomically deprecate the current published version,
if one exists.

At most one current PUBLISHED version may exist per definition through the domain
contract.

---

## 5.6 Deprecated and retired rules

DEPRECATED:

- remains readable as historical contract;
- may only transition to RETIRED;
- cannot become current again.

RETIRED:

- terminal;
- immutable;
- not physically deletable.

Lifecycle timestamps must correspond to the transition being recorded.

---

## 5.7 Concurrency contract — createDraft

Required shape:

```text
transaction
    ↓
lock ProductDefinition FOR UPDATE
    ↓
require ACTIVE
    ↓
ensure no DRAFT
    ↓
read max(version)
    ↓
allocate max + 1
    ↓
insert draft
```

The unique `(product_definition_id, version)` constraint remains the database
backstop.

The manager should use established Straleon transaction/locking patterns.

---

## 5.8 Concurrency contract — publish

Required shape:

```text
transaction
    ↓
lock ProductDefinition
    ↓
require ACTIVE
    ↓
lock target schema version
    ↓
require DRAFT
    ↓
find + lock current PUBLISHED version
    ↓
fail closed if inconsistent multiple-current state is detected
    ↓
deprecate prior current PUBLISHED, if any
    ↓
publish target with published_at
    ↓
commit
```

The replacement is atomic.

No intermediate externally visible state may contain two valid current published
versions due to normal domain execution.

---

## 5.9 Domain manager

Required surface:

```text
ProductSchemaVersionManager::createDraft(
    ProductDefinition $definition,
    ?string $changeSummary = null
)

ProductSchemaVersionManager::updateDraftMetadata(...)

ProductSchemaVersionManager::publish(...)

ProductSchemaVersionManager::abandonDraft(...)

ProductSchemaVersionManager::retireDeprecated(...)
```

Forbidden:

```text
renumber(...)
editPublished(...)
reactivate(...)
delete(...)
forceDelete(...)
```

---

# 6. AttributeDefinition contract

## 6.1 Table

Canonical table:

```text
catalog_attribute_definitions
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

---

## 6.2 Deliberately forbidden fields

CSF-1 MUST NOT add any of the following to AttributeDefinition:

```text
data_type
measurement_dimension
unit
allowed_scopes
required
filterable
searchable
comparable
variant_axis
storefront_visible
network_visible
```

Those are binding/use semantics, not global reusable attribute identity.

Adding only `data_type` in CSF-1 is also forbidden because it would create a
partial type system before CSF-2 reconciles typing, measurements and value scope
with Straleon's numeric/unit foundations.

---

## 6.3 Attribute semantic key

Attribute key follows the same canonical validator as ProductDefinition.

Examples:

```text
straleon.attribute.screen_size
straleon.attribute.ram_capacity
straleon.attribute.tyre_width
```

It is:

- global;
- unique;
- immutable;
- never silently normalized;
- never reused.

---

## 6.4 Status

Persisted values:

```text
active
deprecated
retired
```

Enum cases:

```text
Active
Deprecated
Retired
```

Allowed transition:

```text
ACTIVE → DEPRECATED → RETIRED
```

No reverse transition.

Physical deletion is forbidden.

Metadata may be corrected until retirement.

Future binding rules:

- ACTIVE may be newly bound;
- DEPRECATED is retained for history and must not be selected for new bindings;
- RETIRED is terminal.

Actual binding does not exist in CSF-1.

---

## 6.5 Domain manager

Required surface:

```text
AttributeDefinitionManager::create(...)
AttributeDefinitionManager::updateMetadata(...)
AttributeDefinitionManager::deprecate(...)
AttributeDefinitionManager::retire(...)
```

Forbidden:

```text
renameKey(...)
delete(...)
restore(...)
reactivate(...)
```

---

# 7. Model defensive rules

CSF-1 does not use SoftDeletes.

Models should use defensive lifecycle guards consistent with existing Straleon
patterns.

At minimum:

### ProductDefinition

Reject:

- key mutation;
- invalid reverse status transition;
- mutation after Retired;
- physical delete.

### ProductSchemaVersion

Reject:

- definition mutation;
- version mutation;
- published semantic metadata mutation;
- invalid lifecycle transition;
- mutation after Retired;
- physical delete.

### AttributeDefinition

Reject:

- key mutation;
- invalid reverse status transition;
- mutation after Retired;
- physical delete.

Manager logic remains the intended mutation surface; model guards protect
against accidental bypass.

---

# 8. Migration rules

1. no SoftDeletes;
2. FK delete behavior on schema → definition is RESTRICT;
3. indexes and unique constraints are explicit;
4. no organization ownership is introduced;
5. no JSON semantic bag is introduced;
6. migrations follow repository naming/style;
7. SQLite test compatibility must be preserved;
8. no existing migration is rewritten.

---

# 9. Existing-domain non-mutation contract

CSF-1 MUST leave the following existing concepts byte-identical unless a focused
failure demonstrates an unavoidable compatibility change and a new explicit cut
is authorized:

```text
app/Models/CatalogProduct.php
app/Models/ProductCategory.php
existing Knowledge models/managers
existing Inventory managers
existing Commerce managers
```

In the planned CSF-1 cut, CatalogProduct and ProductCategory are expected to
remain unchanged.

---

# 10. Knowledge boundary acceptance rule

Tests/docs must preserve:

```text
ProductDefinition = semantic kind
TechnicalModel    = concrete technical-model identity
CatalogProduct    = merchant-facing catalog record
ProductCategory   = taxonomy/navigation
Knowledge Entity  = universal identity/relations
```

CSF-1 must not add:

- ProductDefinition identifiers;
- ProductDefinition compatibility;
- ProductDefinition technical-model ownership;
- CatalogProduct semantic assignment.

Those belong to later explicit integration cuts.

---

# 11. Test contract

Three focused test files are required.

The test suite should validate behavior, not assertion-count gaming.

Minimum behavioral coverage includes:

### ProductDefinition

- valid create;
- invalid key rejection;
- exact key uniqueness;
- key immutability;
- metadata correction;
- Active → Deprecated;
- Deprecated → Retired;
- reverse transition rejection;
- retired immutability;
- physical delete rejection;
- no organization/category ownership fields.

### ProductSchemaVersion

- first draft version = 1;
- second historical number monotonic;
- one-draft contract;
- active-definition requirement;
- draft metadata update;
- publish;
- publish timestamps;
- replacement atomically deprecates prior published version;
- published metadata immutability;
- abandon draft → retired;
- deprecated → retired;
- invalid reverse transitions;
- no physical delete;
- FK/unique structural contract;
- no forbidden schema payload columns.

### AttributeDefinition

- valid create;
- invalid key rejection;
- uniqueness;
- key immutability;
- metadata correction;
- lifecycle;
- reverse transition rejection;
- retired immutability;
- physical delete rejection;
- explicit absence of type/binding/publication fields.

Target behavioral coverage is at least roughly 27 meaningful checks across the
three files, but correctness matters more than the raw number.

---

# 12. Test execution contract

After implementation source is written and linted:

```text
1. ProductDefinitionFoundationTest
2. ProductSchemaVersionFoundationTest
3. AttributeDefinitionFoundationTest
4. full suite once
```

If all source remains byte-identical afterward, do not repeat already-GREEN
tests during checkpoint.

Tooling failure that rolls back without changing source does not invalidate prior
GREEN source evidence.

---

# 13. No seed contract

CSF-1 adds no canonical productive definitions or attributes.

Reason:

- registry mechanics must first prove correct;
- canonical semantic vocabulary requires a separate reviewed content/domain cut;
- CSF-1 must not accidentally make sample definitions production truth.

Tests may create fixtures normally.

---

# 14. No HTTP/UI contract

CSF-1 adds no:

```text
controller
request
route
Blade view
navigation
admin screen
public endpoint
```

Registry administration UX is a later cut after domain semantics stabilize.

---

# 15. Expected implementation file count

Source implementation cut:

```text
3 enums
3 models
4 domain classes
3 migrations
3 focused tests
----------------
16 PHP files
```

With the two already-published canonical documents, the complete CSF-1
architecture + source surface is 18 files.

The implementation runner must not rewrite documents 157/158 unless a separate
documentation correction is explicitly authorized.

---

# 16. Implementation safety

The implementation cut is governed by:

`docs/153_STRALEON_ENGINEERING_EXECUTION_CONTRACT_V1.md`.

Required baseline controls include:

- exact branch;
- exact HEAD/tree;
- clean worktree;
- empty staging;
- `StraleonRunnerGuard` self-test GREEN;
- exact expected target non-existence;
- frozen sentinel hashes/blobs;
- embedded PHP lint before write;
- LF/UTF-8/final-newline validation;
- exact scope;
- `git diff --check`;
- rollback on pre-commit failure;
- no commit/push until implementation evidence is GREEN.

---

# 17. Acceptance result for CSF-1

A GREEN implementation must be able to state truthfully:

```text
CSF1_IMPLEMENTATION_GREEN
PRODUCT_DEFINITION_REGISTRY=GREEN
SCHEMA_VERSION_LIFECYCLE=GREEN
ATTRIBUTE_DEFINITION_REGISTRY=GREEN
SEMANTIC_KEYS=STRICT_NO_SILENT_NORMALIZATION
PUBLISHED_SCHEMA_IMMUTABILITY=GREEN
SCHEMA_CONCURRENCY_CONTRACT=GREEN
PHYSICAL_DELETE=FORBIDDEN
SOFT_DELETES=NO
CATALOGPRODUCT_ASSIGNMENT=NO
ATTRIBUTE_BINDING=NO
ATTRIBUTE_VALUES=NO
CAPABILITIES=NO
VARIANTS=NO
STOREFRONT_NETWORK_RUNTIME=NO
KNOWLEDGE_AUTHORITY_DUPLICATION=NO
CSF2_PLUS_SCOPE_LEAK=NO
FOCUSED_TESTS=GREEN
FULL_SUITE=GREEN
```

---

# 18. Next boundary after CSF-1

CSF-1 does not authorize CSF-2 automatically.

After CSF-1 publication and natural CI GREEN, perform a focused RECON before
typing/binding.

Expected next design questions:

- exact attribute type algebra;
- exact decimal and measurement integration;
- units/dimensions;
- value scopes;
- AttributeBinding constraints;
- migration strategy for future values;
- compatibility with Variant and Storefront filtering.

Until that RECON is consumed, those concerns remain out of runtime scope.

---

# 19. Final implementation rule

> **CSF-1 creates semantic registries and lifecycle history only.**

If an implementation needs `CatalogProduct` assignment, semantic values,
capabilities, variants, Storefront fields, Network payloads or Knowledge identity
to make CSF-1 work, the implementation has crossed the contract boundary and
must fail closed rather than expand scope silently.
