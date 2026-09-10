# Roadmap Amendment 167 — Global Knowledge, Acting Context & Omnichannel Commerce V1

**Status:** ACCEPTED — CANONICAL ROADMAP BINDING
**Date:** 2026-09-10
**Runtime authorization:** NONE
**Current code checkpoint preserved:** `9d30a9c736e07b86ed62f35570ad74e17564eb2d` / CSF-7 continuity published
**Immediate next code boundary preserved:** `P13_B_CSF8_VARIANT_SEMANTIC_BOUNDARY_RECON_V1`

This amendment integrates:

- `docs/165_ADR_STRALEON_GLOBAL_PRODUCT_KNOWLEDGE_V1.md`;
- `docs/166_ADR_STRALEON_ACTING_CONTEXT_COMMERCIAL_RELATIONSHIP_CONTINUITY_V1.md`;
- existing Catalog Semantic Foundation;
- Storefront / Network Compatibility Pack;
- P15 Omnichannel;
- P16 Advanced Commercial Engine;
- P18 Suppliers/Planning;
- P19 CRM;
- Operational Attention Center;
- Business Network / Merchant Exchange;
- Need Engine / Demand-to-Supply.

It does not invalidate already GREEN source/test evidence and does not open P13.C.

---

## 1. Why this amendment is required

Several previously planned capabilities were correct individually but risked being implemented as parallel silos:

- CSF-10 could create a provenance mechanism separate from global Knowledge;
- P15 could create WhatsApp/Instagram-specific conversation backends;
- P19 could create a CRM customer truth separate from Commerce/Customer;
- Merchant Exchange could share documents without a robust acting-principal/counterparty boundary;
- bulk catalog ingestion could begin before source rights and identity governance exist;
- Consumer Identity could be mistaken for the owner of all personal and business history.

The two new ADRs establish the shared contracts that prevent those duplications.

---

## 2. Superordinate dependency laws

### R167-001 — CSF and Global Product Knowledge remain separate

```text
CSF
what semantic kind/capabilities apply?

GPK
what real-world thing is this and what do we know about it?
```

Neither becomes the other's object master.

### R167-002 — CSF-10 consumes GPK provenance

AI-assisted semantic ingestion must not implement a second provenance/source/rights subsystem.

### R167-003 — Storefront is the first native consumer, not a special backend

Storefront can use ActingContext, Global Knowledge and CommercialIntentCase but all transactional truths remain merchant-owned.

### R167-004 — Omnichannel adapters come after channel-neutral intent

WhatsApp/Instagram/marketplace adapters must feed the same `CommercialIntentCase`/interaction contract.

### R167-005 — CRM consumes relationship truth

P19 customer 360 derives from merchant Customer/relationships, commerce/service history and normalized interaction cases. It cannot become another customer master.

### R167-006 — B2B uses explicit counterparties

Merchant Exchange requires acting principal, delegated authority, bilateral correlation and `COUNTERPARTY_SHARED` projections.

### R167-007 — External bulk ingestion waits for rights

No meaningful external source pilot may ingest redistributable/global facts before Source/Provenance/Rights enforcement is present.

### R167-008 — Operational hot paths win

Global ingestion/search degradation must not block POS, checkout, inventory confirmation, payments or fiscal operations.

---

## 3. Current execution boundary remains unchanged

The architecture work in ADR 165/166 is docs-only.

After this amendment is published and natural CI is GREEN, the next code activity remains:

```text
P13_B_CSF8_VARIANT_SEMANTIC_BOUNDARY_RECON_V1
```

CSF-8 begins read-only.

No Variant runtime is authorized by this amendment.

---

## 4. Execution model: dependency lanes, not one giant serial project

Not every future capability should wait for every other one.

Straleon SHALL use parallel lanes with explicit hard dependencies.

```text
                    CURRENT: CSF-7 GREEN
                             │
                             ▼
                         CSF-8 RECON
                             │
          ┌──────────────────┼───────────────────┐
          ▼                  ▼                   ▼
   KNOWLEDGE LANE      IDENTITY/CONTEXT      CSF PUBLIC LANE
       GPK-R0              AC-R0                 CSF-9
          │                  │                   │
          ▼                  ▼                   │
 GPK-F1 rights/source    AC-F1 ActingContext     │
          │                  │                   │
          ▼                  ▼                   │
 GPK-F2 identity        AC-F2 continuity         │
          │                  │                   │
      ┌───┴────┐             ▼                   │
      ▼        ▼        AC-F3 self-service       │
 GPK-F3     GPK-F4             │                 │
 lifecycle  relations           └────────┬────────┘
      │        │                         ▼
      └───┬────┘                  STOREFRONT BASELINE
          ▼                              │
 GPK-F5 ingestion pilots                 ▼
          │                    INT-R0 / Intent Case
          ▼                              │
 GPK-F6 search scale                     ▼
                                  P15 OMNICHANNEL
```

CSF-10 attaches to the Knowledge lane after GPK source/provenance/rights and identity contracts exist.

---

## 5. Knowledge lane

### GPK-R0 — Runtime RECON, read-only

Inspect exact current:

- `Entity`;
- `TechnicalModel`;
- `Identifier`;
- `Assertion`;
- `Compatibility`;
- `KnowledgeEngine`;
- `TechnicalModelKnowledgeManager`;
- `CatalogProductKnowledgeManager`;
- migrations/constraints/indexes;
- existing tests;
- Catalog/CSF references.

Mandatory questions:

- which current structures can be retained;
- which names/scopes are too narrow;
- how to stop local SKU adoption from becoming global truth;
- what can be extended without breaking existing merchant CatalogProduct links;
- how identifiers are namespaced;
- how source/evidence migration can be additive;
- how Compatibility can coexist with a general relation graph.

No code writes in GPK-R0.

### GPK-F1 — Source / Provenance / Rights Foundation

First productive Knowledge prerequisite for external ingestion.

Deliver:

- normalized Source identity/version;
- source terms/rights profile;
- evidence references;
- assertion/relation provenance links;
- rights evaluation;
- supersession/correction baseline.

Must remain usable without any external provider configured.

### GPK-F2 — Global identity / identifier / ontology foundation

Deliver:

- exact GlobalKnowledgeEntity identity boundary;
- TechnicalModel role relationship;
- identifier type/namespace/issuer/value;
- global stable IDs;
- class/role constraints sufficient for later graph and ingestion.

Must not require `CatalogProduct` to resolve globally.

### GPK-F3 — Candidate / community / merge-split-dedup

Deliver:

- candidate lifecycle;
- corroboration/dispute/supersession;
- explicit merge/split evidence;
- no similarity-only automatic merge;
- reversible/auditable identity decisions.

### GPK-F4 — General Relation Graph

Deliver:

- RelationType registry;
- inverse/direction/symmetry/transitivity metadata;
- evidence/confidence/status/rights;
- compatibility coexistence/migration strategy;
- recursive component/fitment navigation.

### GPK-F5 — Ingestion adapters + K0/K1 pilots

Only after GPK-F1/F2.

Start with legally/operationally suitable sources and small bounded pilots.

Measure:

- resolution precision;
- unresolved/collision rates;
- storage;
- ingestion throughput;
- query latency;
- rights coverage;
- update/delta behavior.

The library should begin growing before 1.0, but growth is gated.

### GPK-F6 — Search backend evolution

Keep the application resolver contract stable.

Replace SQL/LIKE only when measured scale/latency requires it.

No infrastructure brand is selected by roadmap alone.

---

## 6. Catalog Semantic Foundation changes

### CSF-8

Remains next and preserves Product vs Variant scope.

It must not use Variant as a substitute for:

- TechnicalModel;
- TradeItem/configuration identity;
- supplier pack;
- lot/serial;
- global knowledge entity.

### CSF-9

Public semantic projection remains a Catalog concern.

Before Storefront runtime it must define:

- sanitized public semantic values;
- Variant selectors where applicable;
- stable public references;
- optional GPK reference/technical highlights when global identity exists;
- explicit behavior when `CatalogProduct` remains unresolved globally.

### CSF-10 — dependency changed

CSF-10 remains AI-assisted semantic ingestion, but its implementation is now gated by at least GPK-F1 and the relevant GPK-F2 identity contracts.

Required direction:

```text
AI extraction/proposal
      ↓
GPK Source + Provenance + Rights
      ↓
Catalog/GPK domain validation
      ↓
valid authority / lifecycle
```

CSF-10 may not create another source/evidence/confidence truth.

---

## 7. Migration/onboarding lane

### MIG-R0 — Legacy source RECON

Study real backups/CSV/Excel/database shapes without assuming one vendor.

### MIG-F1 — Source Preservation Envelope

High priority before onboarding mature merchants.

Guarantee:

- received source is preserved/auditable;
- original record identity survives;
- mapping version is known;
- unresolved records are explicit;
- loss accounting is measurable.

### MIG-F2 — Canonical business migration

Map, as available:

- products;
- customers;
- suppliers;
- historical purchases/sales;
- account-current balances/movements;
- stock/opening balances;
- price conditions;
- documents/references.

Reliable history remains legacy-provenance history.

Missing history is never fabricated.

### MIG-F3 — Knowledge resolution during migration

After GPK-F2, imported product records can be resolved progressively.

Unresolved products remain valid merchant CatalogProducts.

### SUP-F1 — Supplier catalog ingestion

Supplier catalogs become `SupplierOffer`/sourcing data first.

They may be mapped to GPK identities and merchant products without auto-publishing every supplier item into the merchant Catalog.

This directly serves JB Repuestos.

---

## 8. Identity / Acting Context lane

### AC-R0 — Existing identity/party/customer RECON

Read-only inspection of:

- current `User`;
- Organization membership;
- `BusinessParty`;
- `Customer`;
- account-current/credit foundations;
- authorization/capability system;
- Storefront Consumer Identity contracts.

Decide exact runtime mapping without prematurely merging identity tables.

### AC-F1 — Acting Context resolver

Deliver:

```text
authenticated actor
+ selected personal/organization context
+ commercial principal
+ membership/delegation
+ operation/counterparty
      ↓
validated ActingContext
```

No new permission grant.

### AC-F2 — Commercial relationship continuity + Counterparty Shared

Deliver:

- existing Customer ↔ ConsumerIdentity binding;
- explicit business relationship claim;
- counterparty verification where required;
- historical non-rewrite;
- `COUNTERPARTY_SHARED` projection policy;
- CommercialRelationshipThread/correlation baseline;
- delegated business buyer semantics.

### AC-F3 — Consumer / counterparty self-service

Merchant-safe projections for:

- orders/purchases;
- account current;
- payments;
- documents;
- warranties/after-sales;
- relationship-specific benefits/conditions.

No second merchant ledger.

---

## 9. Storefront critical path

A useful Storefront should not wait for the entire future Network.

Minimum dependency family:

```text
Public Offer / Commercial Availability
CSF-9 public semantics
Consumer Identity baseline
AC-F1 Acting Context
merchant relationship binding
cart/reservation/checkout contracts
```

GPK full global scale is not a Storefront blocker.

When GPK identity/relations exist, Storefront gains compatibility/fitment resolution progressively.

A merchant can publish unresolved local products safely under merchant-owned semantics.

---

## 10. Commercial Intent / Omnichannel lane

### INT-R0 — Interaction RECON

Before channel adapters, inspect:

- Operational Attention Center;
- current communication/integration patterns;
- Storefront consultation requirements;
- Customer/CRM surfaces;
- audit/idempotency/event contracts.

### INT-F1 — CommercialIntentCase

Implement one channel-neutral business case that can:

- start anonymous;
- attach ConsumerIdentity later;
- bind ActingContext where known;
- store structured need/context;
- reference Knowledge resolutions;
- reference offers/availability;
- resolve automatically or require human attention;
- correlate to cart/order/service work without owning those truths.

### INT-F2 — Automated resolution orchestration

Deterministic/authorized resolution first:

- ask missing structured questions;
- Knowledge compatibility;
- commercial availability;
- applicable price/relationship rules;
- confidence gate;
- safe answer or escalation.

AI may assist later but never invent authority.

### INT-F3 — Operational Attention projector

A case requiring human intervention appears in the existing attention surface.

No second case state.

### P15 — Omnichannel adapters

Only after INT-F1 contract exists.

Adapters:

- WhatsApp Business;
- Instagram;
- marketplace messaging;
- email/SMS or other future channels where justified.

Each adapter maps transport events into the same interaction/case model.

Changing channel does not create a new business truth.

---

## 11. P16 — Advanced Commercial Engine

P16 remains the owner of merchant pricing/discount policies such as:

```text
public price
mechanic price list
wholesale price list
quantity tier
promotion
channel rule
```

ActingContext selects which commercial principal/relationship is evaluated.

A professional 10% discount is not automatically Loyalty.

---

## 12. P18 — Suppliers and planning

P18 consumes:

- normalized SupplierOffers;
- confirmed GPK mappings when available;
- actual merchant purchase/receipt history;
- lead time/pricing/quality evidence;
- Demand-to-Supply signals later.

P18 must not create a second supplier catalog truth disconnected from SupplierOffer.

---

## 13. P19 — CRM

P19 is delayed from creating an independent “CRM customer.”

It must consume:

```text
merchant Customer / commercial relationship
+ orders/sales/service
+ CommercialIntentCases
+ consent/preferences
+ authorized analytics
```

Customer 360 is a projection/read model.

---

## 14. Operational Attention Center

The existing transversal attention design becomes the destination for:

- unresolved CommercialIntentCases;
- relationship-continuity approvals;
- counterparty discrepancies;
- existing operational approvals/exceptions.

It remains a routing projection over authoritative domain facts.

---

## 15. Merchant Exchange / Business Network prerequisites

Before runtime Merchant Exchange:

- AC-F2 complete enough for B2B principal/relationship identity;
- stable public/counterparty IDs;
- `COUNTERPARTY_SHARED` policy;
- structured order/document correlation;
- independent source-of-truth ownership;
- reconciliation/difference semantics;
- explicit publication/discovery rules.

Business Network does not require global sharing of customer histories.

---

## 16. Need Engine / Demand-to-Supply prerequisites

Need Engine may consume:

- public search intent;
- unresolved CommercialIntentCases through allowed privacy-safe projection;
- unavailable demand;
- accepted substitutions;
- GPK relations;
- merchant public offers/availability.

Demand-to-Supply uses aggregation and remains advisory.

No individual cross-merchant customer history is exposed.

---

## 17. V1 onboarding priorities

For first real merchants such as SULU/JB-like cases, V1 usefulness depends more on reducing manual work than on maximal Network sophistication.

Priority gates:

1. import existing business without data loss;
2. preserve opening/history truth;
3. map products progressively to GPK;
4. import supplier catalogs as SupplierOffers;
5. publish selected merchant products through Storefront;
6. let customers self-serve;
7. automate repetitive structured questions;
8. route only unresolved cases to the merchant.

---

## 18. What is explicitly not required before V1

V1 does not require:

- 800M global objects;
- complete worldwide fitment;
- cross-merchant loyalty clearing;
- full Merchant Exchange;
- global search infrastructure split into microservices;
- dedicated graph database;
- AI autonomy;
- every social channel;
- full international shipping;
- every legacy system importer.

It requires contracts that allow those capabilities to arrive without rebuilding Core.

---

## 19. Documentation impact

The following repository documents remain binding and are **not rewritten** by this amendment:

- `docs/153_STRALEON_ENGINEERING_EXECUTION_CONTRACT_V1.md`;
- `docs/154_STRALEON_UNIFIED_ARCHITECTURE_CONVERGENCE_V1.md`;
- `docs/155_STRALEON_STOREFRONT_NETWORK_COMPATIBILITY_PACK_V1.md`;
- `docs/157_ADR_CATALOG_SEMANTIC_FOUNDATION.md`;
- CSF-1 through CSF-7 contracts.

ADR 165 and ADR 166 add/refine:

- Global Product Knowledge ontology/provenance/rights;
- ActingContext and business principal;
- relationship continuity;
- `COUNTERPARTY_SHARED`;
- CommercialRelationshipThread;
- CommercialIntentCase;
- dependency bindings for CSF-10/P15/P19/Merchant Exchange.

Where older wording says “Knowledge Universe is optional,” interpret it as:

> the merchant-visible Knowledge capability may be optional; the Global Product Knowledge substrate is platform-level and may grow independently.

---

## 20. Canonical validation cases for future cuts

Every relevant future cut should be challenged against:

### JB Repuestos

- solo operator;
- legacy DB/history/account-current;
- weekly supplier catalogs;
- professional/mechanic pricing;
- Storefront;
- compatibility questions;
- omnichannel without channel overload.

### Taller Carlos

- same human identity;
- personal/private purchases preserved;
- new Organization;
- old supplier relationships selectively continued;
- account-current history not reset;
- historical invoices not rewritten;
- later employee delegation;
- own decades-old workshop customers imported/invited.

### SULU

- low-friction migration;
- simple merchant UI;
- serial/high-value capabilities when relevant;
- no forced advanced Knowledge UI.

---

## 21. Next execution order

The immediate development order after publication of this docs-only package is:

```text
1. consume natural CI of this package
2. P13_B_CSF8_VARIANT_SEMANTIC_BOUNDARY_RECON_V1
3. close CSF-8 through normal contract/test/checkpoint gates
4. GPK-R0 read-only runtime RECON
5. AC-R0 read-only identity/party/relationship RECON
6. GPK-F1 Source/Provenance/Rights
7. GPK-F2 Global identity/identifier/ontology
8. CSF-9 public semantic projection boundary
9. MIG-F1 Source Preservation + legacy import foundations
10. SUP-F1 supplier-catalog/SupplierOffer mapping
11. GPK-F3 community/dedup + GPK-F4 relation graph
12. bounded GPK ingestion pilots (K0/K1)
13. CSF-10 AI-assisted ingestion bound to GPK provenance
14. AC-F1/AC-F2 ActingContext + relationship continuity
15. Consumer/counterparty self-service
16. Storefront runtime slices as their existing prerequisites close
17. INT-F1 CommercialIntentCase + attention routing
18. P15 channel adapters
19. P18/P19 and later Network modules consume these foundations
```

Steps may overlap only when their hard dependencies and independent scopes are explicit. No overlapping implementation may create a duplicate authority.

---

## 22. Final roadmap rule

> **Do not make merchants re-enter what Straleon can import, do not make customers repeat what Straleon can resolve, do not make channels create separate business truths, and do not make a global identity erase the boundary between personal life and business.**
