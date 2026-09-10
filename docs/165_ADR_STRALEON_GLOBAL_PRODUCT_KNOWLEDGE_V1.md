# ADR 165 — Straleon Global Product Knowledge V1

**Status:** ACCEPTED — CANONICAL ARCHITECTURE BINDING
**Date:** 2026-09-10
**Scope:** Global product/object identity, technical knowledge, provenance, rights, community evolution, relations, ingestion, migration resolution and scale
**Runtime authorization:** NONE — every productive slice still requires RECON, implementation contract, tests and normal checkpoint/CI
**Companion ADR:** `docs/166_ADR_STRALEON_ACTING_CONTEXT_COMMERCIAL_RELATIONSHIP_CONTINUITY_V1.md`
**Roadmap binding:** `docs/167_ROADMAP_AMENDMENT_GLOBAL_KNOWLEDGE_ACTING_CONTEXT_OMNICHANNEL_COMMERCE_V1.md`

---

## 1. Context

Straleon is not only a system where each merchant creates a private list of products.

The long-term product vision requires a global, non-tenant knowledge plane that answers:

> **What things exist in the world, how are they identified, what do we know about them, how do we know it, and how are they related?**

The merchant catalog answers a different question:

> **What does this merchant sell or operate, under which commercial and operational conditions?**

Those truths must never be collapsed.

Canonical real-world design cases include:

- SULU: an existing merchant must migrate hundreds of products without manually rebuilding technical fiches;
- JB Repuestos: weekly supplier catalogs must resolve against reusable technical identity while JB preserves its own stock, prices, customers and account-current history;
- a large hardware/autoparts merchant: thousands or tens of thousands of references must be importable and resolvable progressively;
- POCO M6 / screen protector: Straleon must eventually resolve true compatibility even when seller text does not;
- recursive composition: vehicle → system → assembly → component and component → every model/product that uses or accepts it;
- generic/unbranded product: a merchant can operate immediately without manufacturer, GTIN or TechnicalModel and may later contribute a reusable community concept.

---

## 2. Decision

Straleon SHALL maintain a **Global Product Knowledge** plane with these laws:

1. it is global and non-tenant;
2. no Organization owns a global knowledge entity merely because it first supplied data about it;
3. the library may exist and grow before any merchant sells or references an entity;
4. `CatalogProduct` may operate without a global identity;
5. linking a `CatalogProduct` to global knowledge never transfers merchant-private truth into the global plane;
6. global knowledge centers **the thing**, not a seller listing or publication;
7. knowledge is fact/evidence based, not a mutable monolithic “product row” whose last writer wins;
8. every reusable fact or relation preserves provenance, evidence, lifecycle and rights sufficient for its intended use;
9. community contributions never overwrite global truth directly;
10. imported “authoritative” data is still sourced evidence, not metaphysical truth;
11. derived knowledge must identify that it was inferred and retain an explainable evidence chain;
12. ingestion/search work must not block merchant operational hot paths.

---

## 3. Global Knowledge Entity is broader than TechnicalModel

`TechnicalModel` remains an important global role, but it is not the whole ontology.

Conceptual knowledge subjects may include:

```text
GlobalKnowledgeEntity
├── TechnicalModel
├── TradeItem / Configuration
├── Standard / Generic Technical Object
├── Component / Part
├── Accessory / Consumable
└── Community Product Concept
```

These are **conceptual roles/classes**, not frozen table or class names. Runtime names require formal RECON.

A `ProductDefinition` from Catalog Semantic Foundation is different:

```text
ProductDefinition
    = "what semantic kind is this merchant product?"

GlobalKnowledgeEntity
    = "what real-world thing/concept is this?"
```

Therefore:

```text
ProductDefinition ≠ GlobalKnowledgeEntity
ProductDefinition ≠ TechnicalModel
GlobalKnowledgeEntity ≠ CatalogProduct
TechnicalModel ≠ CatalogProduct
```

CSF and Global Product Knowledge may reference each other through explicit contracts but may not become competing identity authorities.

---

## 4. Identifier model

No identifier type is assumed to equal technical identity.

A knowledge identifier conceptually requires:

```text
identifier_type
namespace / issuer
value
normalization rules
validity / lifecycle
provenance where relevant
```

Examples include GTIN, MPN, OEM part number, regulatory identifier, VIN-derived model identity, vendor-specific code and community alias.

Rules:

- one TechnicalModel may have multiple GTINs for color, storage, region, pack or configuration;
- one supplier code may identify only a supplier offer, not a universal object;
- identifier collisions fail closed into resolution/review;
- aliases never silently become canonical identity;
- an identifier may be historical/superseded without deleting the entity history.

---

## 5. Knowledge is composed of assertions and relations

A global entity is an identity anchor. Facts about it are modeled as evidence-bearing assertions and relations.

Conceptually:

```text
KnowledgeEntity
   │
   ├── KnowledgeAssertion
   │      ├── attribute / predicate
   │      ├── value
   │      ├── source
   │      ├── evidence
   │      ├── origin
   │      ├── status
   │      ├── confidence
   │      ├── observed/effective time
   │      └── rights
   │
   └── KnowledgeRelation
          ├── relation type
          ├── source entity
          ├── target entity
          ├── context/constraints
          ├── source/evidence
          ├── origin/status/confidence
          └── rights
```

A single entity may legitimately contain facts from many sources and many licenses.

---

## 6. Three knowledge-origin modes

Origin, status and confidence are independent dimensions.

### 6.1 Authoritative Imported Knowledge

Knowledge imported from an identifiable source with authority or expertise over the particular fact, such as:

- manufacturer;
- official registry;
- regulator;
- technical standard;
- authorized/licensed technical catalog;
- permitted open dataset.

Policy:

- learn once and preserve source/version;
- refresh by delta, release, correction or explicit revalidation when possible;
- never erase provenance;
- source authority is fact-specific;
- a later correction may supersede an earlier official assertion without deleting history.

### 6.2 Community-Evolved Knowledge

Knowledge contributed or corroborated through:

- merchants;
- workshops;
- local manufacturers;
- professionals;
- consumers/users;
- operational observations;
- evidence supplied by the ecosystem.

Community knowledge is not automatically lower quality. Regional, generic, artisanal or long-tail objects may never exist in a large authoritative API.

A contribution is evidence/candidate knowledge until the applicable lifecycle says otherwise.

### 6.3 Derived Knowledge

A fact or relation inferred by Straleon from other knowledge.

Example:

```text
Screen A COMPONENT_OF Phone B
Protector C FITS Screen A
rule/constraints satisfied
        ↓
derived candidate:
Protector C may fit Phone B
```

Derived knowledge must preserve:

- inference rule/version;
- input assertions/relations;
- provenance chain;
- confidence;
- status;
- time;
- rights compatibility.

Derived output must never be presented as if a manufacturer directly asserted it.

---

## 7. Source authority is not truth status

Forbidden shortcut:

```text
authoritative = true
community = false
```

Required conceptual separation:

```text
ORIGIN
- authoritative_imported
- community_evolved
- derived
- internally_curated, if later needed

STATUS
- candidate
- active / corroborated / verified
- disputed
- superseded
- rejected

CONFIDENCE
- explicit or derived confidence according to domain rules

PROVENANCE
- who/what said it, when, from which version and evidence

RIGHTS
- what Straleon may store, derive, expose or redistribute
```

Exact enum names are deferred to implementation RECON; these semantic dimensions are binding.

---

## 8. Source, evidence and Knowledge Rights Ledger

Rights attach at least at Source and Assertion/Relation level, not only at Entity level.

A provisional `RightsProfile` must be able to express:

```text
storage_permitted
derivation_permitted
redistribution_permitted
commercial_redistribution_permitted
attribution_required
share_alike_required
source_terms_version
```

Additional implementation fields may be required for territory, expiration, purpose, retention or revocation.

Rules:

1. “free to access” never means “free to copy or commercially redistribute”;
2. raw source payload, normalized fact and public/exportable projection are separate artifacts;
3. a derived fact cannot obtain broader rights merely because Straleon computed it;
4. export/API products must filter by rights at fact/relation level;
5. source terms/version must be retained so later audits can explain why a datum was stored or exposed;
6. uncertainty about rights fails closed for redistribution, not for merchant operations that do not require that redistribution.

This foundation protects future B2B commercialization without depending on illegal resale of third-party datasets.

---

## 9. Community lifecycle and governance

A merchant contribution never writes canonical truth directly.

A typical lifecycle may be:

```text
PRIVATE / UNRESOLVED
        ↓ explicit contribution
CANDIDATE
        ↓ corroboration / evidence
COMMUNITY CORROBORATED
        ↓ valid authority / review / policy
VERIFIED / ACTIVE
```

Side paths include:

```text
DISPUTED
REJECTED
SUPERSEDED
```

Exact runtime states require RECON.

Binding rules:

- similarity alone never auto-merges identities;
- contributor reputation may influence review/confidence but never grants unilateral global-write authority;
- contradictory evidence is preserved and resolved explicitly;
- a dispute never deletes the prior evidence trail;
- promotion is additive/stateful, not destructive;
- community facts can reach very high confidence while retaining community provenance forever.

---

## 10. Merge, split and deduplication

Identity resolution is reversible and auditable.

### Merge

A merge decision must preserve:

- every former stable identifier;
- source entities/aliases;
- evidence that justified the merge;
- actor/process;
- timestamp/version;
- redirect/supersession semantics.

A merge must not rewrite historical merchant records merely to make current identity neat.

### Split

When one entity is later shown to represent multiple real things:

- the split creates explicit successor identities;
- historical evidence is reassigned only through auditable rules;
- ambiguous evidence may remain attached to the predecessor/context until reviewed;
- public and merchant references must remain resolvable.

### Deduplication law

> **No global identity may be merged solely because names, descriptions, images or embeddings are similar.**

Automated systems may propose; a valid domain rule/authority decides.

---

## 11. General relation graph

Current `Compatibility` remains valuable but is too narrow to represent the universal graph.

A conceptual `RelationType` registry must eventually support relations such as:

```text
HAS_COMPONENT / COMPONENT_OF
FITS
COMPATIBLE_WITH
ACCESSORY_FOR
REPLACEMENT_FOR
EQUIVALENT_TO
VARIANT_OF
SUCCESSOR_OF
USES_CONSUMABLE
```

Each relation type may define:

- direction;
- inverse relation;
- symmetry where applicable;
- transitivity only where explicitly valid;
- allowed source/target classes;
- contextual constraints;
- evidence/confidence/status requirements.

Compatibility is never assumed transitive unless a specific relation rule proves it.

The graph must support recursive navigation in both directions:

```text
complex product → subsystems → assemblies → parts
part → every product/model that contains/uses/fits it
```

---

## 12. Supplier catalogs are not Global Product Knowledge

`SupplierOffer` is commercial sourcing truth.

It may contain:

- supplier code;
- supplier description;
- offered brand/MPN/GTIN;
- price;
- availability;
- lead time;
- commercial conditions;
- update timestamp.

Those facts do not become global truth merely because a supplier sent them.

A supplier catalog may feed identity resolution:

```text
SupplierOffer record
        ↓
identifier / text / attributes
        ↓
Knowledge resolution candidates
        ↓
confirmed mapping
```

A supplier’s technical claim may become a sourced knowledge assertion only through the knowledge-ingestion pipeline with provenance and rights.

Supplier price, private terms and supplier availability remain outside Global Product Knowledge.

---

## 13. Merchant catalog remains operationally independent

A merchant must be able to create and sell:

```text
"Cable USB-C 1 m, braided, reinforced heads, black box"
```

even when Straleon cannot resolve:

- manufacturer;
- GTIN;
- MPN;
- TechnicalModel;
- global identity.

Therefore:

> **Global Product Knowledge enriches merchant operation; it is not a mandatory precondition for CatalogProduct.**

The global substrate itself is platform-wide and persistent. What remains optional is the **merchant-visible advanced Knowledge surface/capabilities**, not the existence of the global knowledge plane.

This refines older roadmap wording that called “Knowledge Universe” an optional capability.

---

## 14. Legacy import and Source Preservation Envelope

Existing merchants must not rebuild their business manually.

For any legacy source, Straleon must distinguish:

```text
SOURCE PRESERVATION
        +
CANONICAL MIGRATION / MAPPING
```

The source-preservation envelope must retain enough information to prove what was received:

- source/batch identity;
- original record identity;
- original field/value representation when legally permitted;
- source hash/file metadata where applicable;
- importer/mapping version;
- imported timestamp;
- mapping outcome;
- unresolved/conflict state.

An uncertain mapping is not data loss.

Example acceptance accounting:

```text
source records       8,437
preserved            8,437
mapped                8,125
requires resolution    312
lost                      0
```

Rules:

- unresolved records remain operable when safe;
- no fake historical purchase is created merely to explain opening stock;
- when reliable legacy history exists, retain it as `legacy imported history` provenance;
- when only an opening balance exists, create an explicit opening fact rather than fabricated history;
- global resolution may improve later without rewriting the original imported source.

---

## 15. Import/ingestion architecture

Global ingestion uses adapters and a staged pipeline:

```text
source
  ↓
raw/source-preserved evidence
  ↓
normalization
  ↓
identifier/entity resolution candidates
  ↓
assertions / relations
  ↓
conflict + rights + validation gates
  ↓
usable Global Product Knowledge
  ↓
search projections
```

Requirements:

- adapters are source-specific; domain contracts are source-neutral;
- ingestion is idempotent/replay-safe;
- release/delta identity is retained;
- raw large payloads, manuals and media do not become BLOBs in the primary relational database by default;
- ingestion workers cannot hold merchant operational hot paths hostage;
- source failures do not corrupt already accepted knowledge;
- source removal/rights change can suppress future exposure without destroying mandatory audit history.

---

## 16. Knowledge, Search, Ingestion and Operational planes

Logical separation:

```text
Operational Plane
Commerce · Inventory · Money · Reservations · Customers

Knowledge Plane
entities · identifiers · assertions · relations · provenance · rights

Search Plane
derived indexes / resolution candidates

Ingestion Plane
adapters · workers · normalization · dedup · deltas
```

Initial deployment may share PostgreSQL/server infrastructure for economy.

Physical separation, dedicated search engines, queues or graph stores are introduced only when measured load justifies them.

Binding invariant:

> **A mass global import must never block a sale in SULU, JB Repuestos or another merchant.**

---

## 17. Scale gates

Long-term scale is aspirational and budget/evidence gated.

Planning orders of magnitude:

```text
K0 development / pilot      100k–1M
K1 pre-V1                   1M–10M
K2 V1 / growth              10M–50M+
K3 regional                 50M–200M+
K4 global                   200M–800M+
```

These are planning bands, not delivery promises.

Search indexes are derived/rebuildable.

Large raw evidence belongs in compressed/deduplicated object storage where appropriate.

Backup strategy for a large read-mostly knowledge plane must use database-native incremental/base-backup/WAL/PITR style mechanisms as applicable, not full logical dumps of the entire universe every day.

---

## 18. Search and resolution contract

The current `KnowledgeEngine::resolve(...)` contract can remain a useful application boundary.

Its current exact/SQL/LIKE implementation is a bootstrap strategy, not a permanent hundred-million-object search architecture.

Future evolution may change the backend while preserving caller semantics:

```text
KnowledgeEngine contract
        ↓
resolver/search implementation
        ↓
SQL initially
search/index infrastructure when metrics justify it
```

No Neo4j/OpenSearch/Kafka-like dependency is authorized by this ADR.

---

## 19. Knowledge commercialization

A future Straleon B2B knowledge API/dataset may expose:

- identity resolution;
- normalized identifiers;
- technical models;
- compatibilities;
- equivalences;
- regional products;
- relation/fitment knowledge;
- permitted derived knowledge.

Commercialization is allowed only for facts/relations whose rights permit it.

The value of Straleon is not “copying a large third-party catalog”; it is the lawful accumulated identity, normalization, regional coverage, corrections, community evidence, relationships and derived knowledge that Straleon is entitled to expose.

This is not a V1 runtime requirement.

---

## 20. Integration with Catalog Semantic Foundation

Catalog Semantic Foundation and Global Product Knowledge remain complementary.

CSF answers:

```text
What semantic information/capabilities make sense for this merchant product?
```

Global Product Knowledge answers:

```text
What real-world thing is this, what do we know about it, and what relates to it?
```

Consequences:

- CSF-8 Variant work must preserve this distinction;
- CSF-9 public semantic projections may include global knowledge references only through explicit public-safe contracts;
- CSF-10 AI-assisted semantic ingestion MUST consume the Global Product Knowledge provenance/source/rights contracts rather than inventing a second provenance subsystem;
- AI suggestions never directly create global truth.

---

## 21. Integration with Storefront, Network and Need Engine

Storefront may use Global Product Knowledge to reduce human questions:

```text
customer need / vehicle / device
        ↓
identity + compatibility resolution
        ↓
merchant PublicOffer + Commercial Availability
        ↓
answer / cart / structured unresolved case
```

Network consumes public-safe projections; it does not obtain merchant private history.

Need Engine may consume public or privacy-safe aggregate demand signals and use knowledge relations to compose solutions.

Knowledge itself never mutates merchant stock, pricing, account-current or fiscal truth.

---

## 22. Canonical design cases

Every significant future knowledge decision must be tested against at least:

1. **SULU existing catalog migration** — hundreds of items, low manual tolerance;
2. **JB Repuestos** — supplier catalogs, OEM/aftermarket codes, fitment, Storefront;
3. **large autoparts/hardware catalog** — tens of thousands of references;
4. **POCO M6 protector compatibility** — difficult relation resolution;
5. **generic/unbranded product** — no forced manufacturer/model identity;
6. **recursive vehicle/device composition** — whole ↔ subsystem ↔ part;
7. **regional/community-only product** — no authoritative global source.

If the architecture only works for highly standardized GTIN-bearing retail products, it fails this ADR.

---

## 23. Runtime sequencing constraints

This ADR accepts the architecture, not implementation.

Before productive changes:

1. formal read-only RECON of current `Entity`, `TechnicalModel`, `Identifier`, `Assertion`, `Compatibility`, `KnowledgeEngine`, `CatalogProductKnowledgeManager`, migrations, constraints and tests;
2. define exact runtime ontology/namespaces;
3. define merge/split/dedup authority;
4. define community promotion/dispute authority;
5. define general relation graph compatibility with existing `Compatibility`;
6. implement Source/Provenance/Rights before any bulk external ingestion;
7. preserve compatibility with Catalog/Storefront contracts;
8. start small K0/K1 ingestion pilots and measure;
9. scale search/storage only from evidence.

No current CSF tests are invalidated by this ADR.

---

## 24. Forbidden designs

The following are architectural violations:

1. a merchant SKU silently becomes a global identity;
2. a merchant or supplier directly overwrites a global assertion;
3. Global Product Knowledge stores merchant stock, cost, account-current or private sales history as global truth;
4. `ProductDefinition` becomes a duplicate global object master;
5. `TechnicalModel` is required for every `CatalogProduct`;
6. similarity alone auto-merges entities;
7. current truth is repaired by destructive historical rewrite;
8. a derived assertion hides its inference origin;
9. rights are recorded only once at entity level;
10. data with unknown redistribution rights is exported as if owned by Straleon;
11. `Compatibility` is overloaded with every possible relation;
12. a graph relation is assumed transitive without an explicit rule;
13. mass ingestion blocks operational commerce;
14. a search index becomes irreplaceable source of truth;
15. a new search/graph technology is introduced before metrics require it;
16. bulk source ingestion starts before provenance/rights enforcement exists.

---

## 25. Canonical outcome

Global Product Knowledge is successful when Straleon can learn a real-world identity or technical fact once, preserve exactly how it was learned and what may legally be done with it, improve it through community and derived evidence, relate it recursively to other things, and reuse it across unlimited merchants **without making any merchant surrender its private commercial truth or requiring a merchant to wait for perfect global knowledge before operating**.

> **Learn the thing once. Preserve the evidence forever. Reuse only what rights and confidence allow. Keep merchant truth private.**
