# Straleon — Unified Architecture Convergence V1

**Status:** CANONICAL ARCHITECTURE BINDING
**Scope:** Cross-domain convergence for Core, Inventory, Commerce, Service, Storefront and Straleon Network
**Runtime authorization:** NONE — this document does not mutate runtime or open P13.C
**Roadmap authority:** additive to the master roadmap/vision; it does not replace them

---

## 1. Purpose

This document converges the architecture already implemented in Straleon with the Storefront, Straleon Network, consumer identity, progressive configuration, multirubro, service and transformation decisions developed during the latest design cycle.

The objective is not to make Straleon larger. The objective is to make every future capability land on the correct truth owner, reuse the foundations already published, avoid duplicate authorities and preserve a clean path from merchant-local operations to Storefront and, later, Network.

The governing principle is:

> **One canonical truth per domain fact; many projections, zero competing authorities.**

This is the **STRALEON SINGLE-TRUTH PRINCIPLE / PRINCIPIO DE UNA ÚNICA VERDAD**.

Merchant Core owns merchant truth. Storefront consumes merchant-safe channel contracts. Straleon Network consumes public-safe projections and events. Neither Storefront nor Network becomes the source of truth for stock, internal commercial history or private operations.

---

## 2. Binding inputs

This convergence is governed by the current published repository state and the following canonical sources:

### Repository masters

- `docs/06_ROADMAP.md`
- `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`
- `docs/README.md`

### Published roadmap / architecture amendments

- `docs/150_ROADMAP_AMENDMENT_STRALEON_STOREFRONT_MULTIRUBRO_PHYSICAL_COMMERCE_V1.md`
- `docs/151_ROADMAP_AMENDMENT_TALLER_MECANICO_AUTOPARTES_SERVICE_SCOPE_EVOLUTION_V1.md`
- `docs/152_ROADMAP_AMENDMENT_SERVICE_SCOPE_EVOLUTION_CROSS_VERTICAL_V1.md`
- `docs/153_STRALEON_ENGINEERING_EXECUTION_CONTRACT_V1.md`

### External architecture sources converged here

- `STRALEON_NETWORK_DOCUMENTO_MAESTRO_V2`
- `STRALEON_NETWORK_ADR_CONSUMER_IDENTITY_VS_MERCHANT_CUSTOMER_V1`
- `STRALEON_ADR_INVENTORY_POLICY_RESOLUTION_AND_PROGRESSIVE_CONFIGURATION_V1`

Those external documents remain useful design sources. This document and its companion compatibility contracts make their cross-domain bindings explicit inside the repository so future implementation does not depend on conversational memory.

---

## 3. Current published foundations that MUST be preserved

The convergence assumes and preserves the architecture already published, including:

- confirmed `InventoryMovement` as physical inventory source of truth;
- inventory balance projection/rebuild/verifier;
- Commercial Availability foundation;
- Inventory Reservation foundation;
- reservation quantity tolerance;
- Variable Quantity Fulfillment evidence;
- Fulfillment Preferences evidence;
- fractional containers;
- fractional opening/consumption enforcement;
- fractional preparation-location boundary;
- fractional expiration/provenance;
- open-container-first / FIFO / FEFO / manual fractional consumption policies;
- organization isolation;
- idempotency/fingerprint patterns;
- immutable confirmed/evidence records;
- exact correction/reversal patterns;
- progressive, capability-driven configuration;
- Storefront physical-commerce roadmap;
- cross-vertical Service evolution.

No new architecture may silently bypass or duplicate these boundaries.

---

## 4. Architectural laws

### UA-000 — SINGLE-TRUTH PRINCIPLE / PRINCIPIO DE UNA ÚNICA VERDAD

For **each domain fact**, Straleon has **exactly one canonical authority**.

Any other representation of that same fact MUST be one of the following:

- **related durable evidence**, immutable and scoped to its own fact/intent;
- a **derived projection/read model**, rebuildable from authoritative evidence/state;
- a **cache/materialized view**, disposable and reconstructable;
- an **explicit public/channel projection**, downstream and non-authoritative.

None of those representations may independently originate, overwrite, reconcile or compete with the canonical truth of the same fact.

This principle does **not** mean that Straleon has one table, one aggregate or one database record for every concern. Different facts legitimately have different authorities. For example:

- confirmed `InventoryMovement` owns the fact **physical stock changed**;
- Commercial Availability owns the fact **what can currently be promised commercially**;
- `InventoryReservation` owns the fact **quantity is commercially held under this reservation/tolerance**;
- `FulfillmentPreference` owns the fact **what substitution/contact/fallback intent was recorded**;
- `VariableQuantityFulfillment` owns the fact **what quantity was actually measured/accepted for that fulfillment**;
- future `InventoryTransformation` owns the fact **which confirmed physical facts form a transformation lineage**;
- a public availability projection owns **no merchant operational fact**; it only represents a downstream view.

Therefore:

> **Different domain facts may have different canonical authorities, but the same domain fact may never have two competing truths.**

If a future implementation cannot identify the single canonical owner of the fact it wants to store, the cut MUST remain in RECON/design and MUST NOT create another mutable authority.

### UA-001 — Merchant Core remains authoritative

Merchant-local operational data is authoritative inside the merchant organization. Public or channel projections never become upstream truth.

### UA-002 — Confirmed movement ledger owns physical stock

Physical stock changes only through the inventory movement ledger and its confirmed semantics. No Storefront action, Network event, transformation record, loyalty action or read model may mutate physical stock independently.

### UA-003 — Commercial availability is not physical stock

The commercial promise to a customer/channel is a derived decision that may account for reservations, commitments, blocks, protected quantities, channel policy and other valid constraints. It is not a second stock ledger.

### UA-004 — Evidence, command and projection are different things

- A **command** requests a change.
- **Durable evidence** records an intent or fact that must survive.
- A **projection/read model** answers a query efficiently.
- A **public projection** exposes an explicitly permitted subset.

A projection can be rebuilt. Durable evidence cannot be silently rewritten to make a projection convenient.

### UA-005 — Storefront is a channel boundary, not a second backend

Storefront orchestrates consumer commerce using stable merchant contracts. It does not read/write internal tables directly and does not own catalog, stock, reservation or payment truth.

### UA-006 — Network is downstream and asynchronous by default

Straleon Network consumes public-safe projections/events. Merchant Core and Storefront must remain operational if Network is delayed or unavailable, except for an explicitly optional feature whose dependency is transparent to the user.

### UA-007 — Global identity does not create global customer history

`ConsumerIdentity` is a global authentication/identity surface. The merchant `Customer` relationship remains organization-scoped and private. A stable global identity never implies cross-merchant access to addresses, orders, preferences, loyalty history or commercial behavior.

### UA-008 — Capability-driven complexity

Capabilities activate behavior and UI only where relevant. Simple merchants/products must not be forced to configure advanced inventory, service, transformation or Network features.

### UA-009 — Internal numeric IDs do not cross public boundaries

Cross-channel/public contracts use stable opaque public identifiers. Internal primary keys remain implementation details.

### UA-010 — Public exposure is explicit

Nothing becomes public merely because it exists internally. Publication is an explicit, revocable projection decision with privacy classification.

---

## 5. Truth ownership matrix

| Concern | Canonical truth owner | Derived / consumers |
|---|---|---|
| Product/catalog master data | Merchant Core / Catalog | Storefront, public offer projection, Network |
| Physical stock | Confirmed `InventoryMovement` ledger | Inventory balance, availability |
| Commercial promise | Commercial Availability | POS, Storefront, seller channels, Network projection |
| Quantity hold / tolerance | `InventoryReservation` | Checkout, picking, fulfillment |
| Substitution/contact/fallback intent | `FulfillmentPreference` | Fulfillment orchestration |
| Measured/accepted actual quantity | `VariableQuantityFulfillment` | Finalization, pricing/fiscal boundary later |
| Transformation lineage | future `InventoryTransformation` evidence linked to confirmed movement lines | yield/read models, passport projector |
| Inventory policy definition | hierarchical capability-aware policy configuration | resolver/explainability |
| Merchant customer relationship | merchant-private Customer / link | merchant channels only |
| Global login identity | `ConsumerIdentity` | Storefront / Network authentication |
| Money/fiscal truth | existing Commerce/Fiscal boundaries | Storefront result/projections |
| Service episode | Service domain / Work Order evolution | after-sales projections later |
| Loyalty points | append-only loyalty ledger | derived balances/benefits |
| Public offer | explicit `PublicOfferProjection` | Storefront / Discovery |
| Public availability | explicit `PublicAvailabilityProjection` | Storefront / Discovery |
| Product Passport | explicit public-safe claims projection | consumer/after-sales Network |
| Trust signals | derived, explainable public-safe signals | Network ranking/trust |
| Demand intelligence | aggregate downstream evidence | merchant decision support |

---

## 6. Layered system shape

```text
┌───────────────────────────────────────────────────────────────┐
│ MERCHANT CORE                                                 │
│ Catalog · Inventory · Commerce · Service · Fiscal · Customer  │
│                                                               │
│ Source of operational truth                                   │
└───────────────────────────────┬───────────────────────────────┘
                                │
                                │ durable evidence + domain events
                                ▼
┌───────────────────────────────────────────────────────────────┐
│ COMPATIBILITY / PROJECTION LAYER                              │
│ Commercial Availability · Public Offer · Public Availability  │
│ public IDs · event envelope · privacy projection              │
└───────────────────────┬───────────────────────┬───────────────┘
                        │                       │
                        ▼                       ▼
             ┌────────────────────┐  ┌──────────────────────────┐
             │ STOREFRONT         │  │ STRALEON NETWORK         │
             │ consumer channel   │  │ public/network layer     │
             │ commerce orches.   │  │ downstream projections   │
             └────────────────────┘  └──────────────────────────┘
```

The Compatibility Layer is a contract boundary, not a second database authority. Implementations may share infrastructure, but ownership rules remain explicit.

---

## 7. Identity and identifier architecture

### 7.1 Internal identity

Internal integer keys remain valid for local persistence and joins.

### 7.2 Stable external identity

Entities that cross Storefront/API/Network boundaries require stable, opaque public IDs, normally UUID-like identifiers.

At minimum, architecture must support stable external references for:

- organization/merchant;
- catalog product;
- public offer;
- consumer identity;
- public order/fulfillment reference when exposed externally;
- product passport or credential;
- public event.

Location identity is exposed only when the merchant explicitly publishes a location.

### 7.3 Consumer vs merchant customer

```text
ConsumerIdentity (global)
        │
        │ explicit merchant relationship
        ▼
MerchantCustomerLink
        │
        ▼
Customer (private to Organization)
```

Rules:

1. anonymous public browsing is allowed where merchant policy permits;
2. purchasing may require a registered `ConsumerIdentity`;
3. guest checkout can remain a merchant policy;
4. Google/federated and native email/password are identity concerns;
5. verification/recovery/account linking are identity concerns;
6. duplicate-account prevention must be controlled;
7. marketing consent is explicit and separable from registration/purchase;
8. a merchant sees only data legitimately available in its own relationship.

---

## 8. Evidence / command / projection lifecycle

The canonical cross-domain flow is:

```text
COMMAND
   │
   ▼
DOMAIN VALIDATION + LOCKS
   │
   ▼
DURABLE EVIDENCE / AUTHORITATIVE STATE
   │
   ├────────► PRIVATE READ MODELS
   │
   └────────► EXPLICIT PROJECTOR
                  │
                  ├────► STOREFRONT-SAFE
                  └────► NETWORK/PUBLIC-SAFE
```

Projectors must be:

- deterministic where practical;
- idempotent;
- replay-safe;
- privacy-aware;
- versioned at external boundaries;
- able to represent freshness/staleness.

---

## 9. Idempotency and concurrency

Existing Straleon patterns are binding:

- organization-scoped idempotency keys for merchant-side commands/evidence;
- deterministic fingerprints for exact replay;
- same key + different intent fails closed;
- transaction and row/aggregate locks at the established authority boundary;
- confirmed/evidence records become immutable;
- correction is additive through reversal/supersession, not historical rewrite.

External/public events add:

- globally stable `event_id`;
- schema version;
- aggregate/public subject identity;
- correlation/causation identifiers when available;
- at-least-once delivery compatibility;
- idempotent consumers;
- replay-safe projection rebuilding.

---

## 10. Progressive capability and policy architecture

Configuration is resolved **by capability and by exception**, not by showing every merchant every option.

Canonical policy resolution:

```text
system/default
    ↓
organization
    ↓
category/family
    ↓
product
    ↓
product-location
```

`unset` means **inherit**, not “missing configuration.”

Inventory selection/rotation policies include:

- FIFO;
- FEFO;
- LIFO;
- manual/specific selection;
- fractional-container-specific policies.

Important boundaries:

- LIFO is operational/logistics policy, never accounting valuation;
- simple unit products should hide rotation controls;
- serialized/IMEI products use specific-unit selection;
- lot + expiry can default FEFO;
- lot without expiry can default FIFO with LIFO available;
- fractional containers use relevant fractional policies;
- policy resolution should expose explainability internally (`resolved_policy`, `resolved_from`, applicable scope);
- Storefront/Network consume outcomes such as availability, not private internal policy details.

---

## 11. Unified commerce pipeline

```text
BROWSE / SEARCH
      ↓
COMMERCIAL AVAILABILITY
      ↓
CART / INTENT
      ↓
RESERVATION
(quantity + tolerance envelope)
      ↓
FULFILLMENT PREFERENCES
(substitution / consultation / fallback)
      ↓
PICKING / PREPARATION
      ↓
VARIABLE ACTUAL QUANTITY
(when applicable)
      ↓
TRANSFORMATION
(only when physical identity/composition really changes)
      ↓
FULFILLMENT FINALIZATION
      ↓
MONEY / FISCAL CLOSURE
```

These stages are deliberately distinct.

### Key anti-duplication rules

- tolerance lives with the reservation envelope;
- substitution intent lives in Fulfillment Preference;
- measured actual quantity lives in Variable Quantity Fulfillment;
- transformation is not used for simple weighing or picking;
- final price/fiscal amount is not prematurely stored as transformation evidence.

---

## 12. Storefront role

Storefront is the consumer-facing commerce channel and must be able to:

- browse public merchant/product/offer information;
- read commercially safe availability;
- authenticate a consumer when required;
- maintain addresses/contact data under proper scope;
- submit cart/order intent;
- create/use reservations through merchant contracts;
- capture tolerance and fulfillment preferences;
- support consultation/substitution workflows later;
- show preparation/fulfillment state;
- accept variable measured quantities where applicable;
- finalize commerce through merchant-owned money/fiscal contracts.

Storefront MUST NOT:

- update stock directly;
- read internal inventory tables;
- expose safety stock/reservation internals;
- expose internal margins/costs;
- rely on Network availability for merchant-local checkout;
- merge merchant-private customer histories.

---

## 13. Service / after-sales convergence

The Service roadmap remains additive and capability-driven.

Generic concepts include:

- `ServiceSubject` / customer-owned maintainable asset;
- Work Order / service episode;
- diagnosis;
- estimate/authorization;
- parts and labor;
- technician assignment;
- custody/condition/status;
- completion and after-sales evidence.

Inventory parts consumed by service continue to use the same `InventoryMovement` truth.

Future Product Passport / After-Sales Network may project selected service evidence, but private diagnoses, prices, customer details and internal notes remain merchant-private unless explicitly published/consented.

---

## 14. Transformation / Yield / Traceability role

Transformation is a private operational evidence layer for cases where physical inventory identity or composition changes.

Examples:

- flour → bread;
- primal cut → multiple retail cuts;
- raw material → finished product + by-product;
- recoverable material → output + discard.

Non-examples by default:

- measuring 1.086 kg instead of requested 1 kg;
- opening/consuming a fractional container while the product identity remains the same;
- moving stock between shelves;
- ordinary picking.

Transformation evidence:

1. links exact confirmed inventory movement lines;
2. never directly changes stock;
3. keeps input/output lineage immutable;
4. exposes yield only when a valid common basis exists;
5. preserves strongest available provenance;
6. permits future lot/serial integration without requiring generic lot/serial today;
7. feeds public Product Passport only through an explicit sanitized projector.

The companion `156_STRALEON_TRANSFORMATION_YIELD_TRACEABILITY_ARCHITECTURE_V1.md` is binding for this domain.

---

## 15. Storefront / Network Compatibility boundary

The compatibility pack is the intentional seam that lets Storefront ship early while leaving Network-ready contracts in place.

Its canonical contract families are:

- stable external/public IDs;
- `PublicOfferProjection`;
- `PublicAvailabilityProjection`;
- versioned public-safe event envelope;
- Consumer Identity ↔ Merchant Customer separation;
- fulfillment/finalization contracts;
- publication lifecycle;
- data-classification rules;
- freshness/failure semantics.

The companion `155_STRALEON_STOREFRONT_NETWORK_COMPATIBILITY_PACK_V1.md` is binding for those contracts.

---

## 16. Straleon Network modules

### 16.1 Merchant Network

Opt-in public merchant presence and public projections.

### 16.2 Discovery Network

Search/discovery by product/service, proximity, availability, price, delivery, opening hours, promotions and later trust/loyalty signals.

### 16.3 Loyalty Network

Global Straleon identity plus merchant-scoped relationships, append-only points/benefits/campaign evidence and derived balances.

Initial loyalty architecture favors:

- merchant-local points;
- Straleon-sponsored campaigns where appropriate;
- exact reversal/expiration;
- no mutable `points_balance` as sole truth.

Cross-merchant redemption is a later phase and requires clearing/settlement semantics.

### 16.4 Need Engine

Captures unmet consumer needs/intents and can compose possible solutions. It does not mutate merchant inventory/catalog automatically.

### 16.5 Merchant Exchange

Future merchant-to-merchant supply/collaboration network, separate from consumer checkout and merchant autonomy.

### 16.6 Product Passport + After-Sales Network

Projects explicit, verifiable, privacy-safe product/service/transformation claims from merchant evidence.

### 16.7 Demand-to-Supply Loop

Aggregates demand signals and can return intelligence to merchants. It is advisory unless a merchant explicitly accepts an action.

### 16.8 Trust Passport

Derives explainable trust/reliability signals from verified operational evidence without publishing raw private data.

### 16.9 Straleon Connect/API Platform

Versioned external contracts, stable IDs, events/webhooks and integrations. Internal database structures are never the API.

---

## 17. Privacy classification

Every cross-boundary field/event should belong to one of these classifications:

| Classification | Meaning |
|---|---|
| `PRIVATE_INTERNAL` | merchant operational data; never public by default |
| `MERCHANT_CHANNEL` | safe for merchant-owned channels such as Storefront after authorization |
| `CONSUMER_ACCOUNT` | consumer-specific data visible to the authenticated consumer and authorized merchant relationship |
| `PUBLIC_NETWORK` | explicitly publishable public projection |
| `AGGREGATED_NETWORK` | de-identified/aggregated intelligence suitable for Network analysis |

Movement to a broader classification requires an explicit projector/policy. It is never implied by data existence.

---

## 18. Product Passport architecture

Product Passport is a projection, not the internal traceability ledger.

A future passport may carry:

- stable public subject ID;
- versioned claims;
- manufacturer/product identity claims;
- approved origin/provenance claims;
- selected transformation lineage claims;
- after-sales/service claims;
- ownership/credential references only when privacy/legal rules permit;
- verification timestamps / issuer identity.

It MUST NOT automatically expose:

- supplier costs;
- internal margins;
- staff identity;
- private warehouse locations;
- complete internal movement history;
- private customer/service history;
- proprietary production recipes.

---

## 19. Trust Passport architecture

Trust signals must be:

- derived from verifiable evidence;
- explainable at signal-category level;
- bounded by freshness;
- privacy-safe;
- resistant to duplicate event delivery;
- reversible/recomputable when underlying evidence is corrected.

Potential future signal families may include fulfillment reliability, after-sales resolution quality, availability freshness or verified operational consistency.

Trust Passport is not permission to publish raw merchant data.

---

## 20. Need Engine and Demand-to-Supply

The Need Engine represents **demand intent**, not inventory truth.

The Demand-to-Supply Loop may aggregate:

- unmet search demand;
- unavailable demand;
- accepted substitutions;
- lead-time patterns;
- aggregate category/location demand;
- fulfillment performance;
- later capacity/yield signals.

It MUST NOT automatically:

- create products;
- change merchant prices;
- change inventory;
- place supplier orders;
- expose individual consumer histories across merchants.

Merchant acceptance remains explicit.

---

## 21. Merchant Exchange

Merchant Exchange is a future B2B domain and MUST remain distinct from consumer commerce.

It may later support:

- discoverable supply capabilities;
- B2B offers;
- availability commitments;
- inter-merchant order intent;
- logistics coordination;
- settlement/clearing.

It must preserve merchant autonomy, organization isolation and explicit commercial agreements.

---

## 22. Multi-rubro validation matrix

Every major architecture addition should be challenged against several verticals, not only the business that inspired it.

| Vertical | Critical capabilities | Complexity that should stay hidden if unused |
|---|---|---|
| Electronics / SULU | serial/IMEI, service, specific-unit fulfillment, Storefront | FEFO/LIFO rotation |
| Toys / general retail | unit inventory, offers, reservations, promotions | transformation, lot expiry |
| Grocery / butcher | variable quantity, tolerance, substitutions, transformation where identity changes | serial tracking |
| Eggs / perishables | lot/expiry when enabled, FEFO, availability | service/work-order complexity |
| Bar / restaurant | fractional containers, preparation, possibly recipes later | serial/IMEI |
| Bakery | transformation, yield, variable quantity, perishability | electronics-specific tracking |
| Workshop / autoparts | ServiceSubject, work order, parts inventory, compatibility | food transformation |
| Lubricants / fluids | fractional/container policies, manual/specific choice | generic serial UI |
| Mixed multirubro | capability combinations by product/category/location | one-size-fits-all configuration |

No vertical is allowed to force irrelevant configuration into every other merchant.

---

## 23. Sequencing lanes

### Lane A — Core / Commerce / Storefront-critical compatibility

Build natural boundaries as the roadmap reaches them:

- stable external IDs;
- public projection contracts;
- Consumer Identity vs Merchant Customer;
- account/auth/profile/address;
- consent/communication preferences;
- event/idempotency contracts;
- current P13.B inventory/commerce foundations.

### Lane B — Storefront runtime

Activate consumer commerce against merchant-owned contracts without waiting for Network density.

### Lane C — Network runtime

Activate modules only after their prerequisites and evidence gates are met.

### Current execution rule

This convergence pack does **not** reorder the master roadmap, open P13.C or authorize production mutation.

At the current published boundary, after this architecture pack is checkpointed, the immediate functional next cut remains:

`P13_B_TRANSFORMATION_YIELD_TRACEABILITY_FOUNDATION_V1`

---

## 24. Network activation gates

Runtime Network activation is evidence-gated, not enthusiasm-gated.

Example prerequisite families:

- sufficiently stable public IDs/contracts;
- public projection correctness/freshness;
- Storefront/merchant-channel usage;
- consumer identity maturity;
- explicit merchant publication consent;
- privacy classification enforcement;
- idempotent event delivery/replay;
- enough merchant/offer density for discovery value;
- loyalty ledger maturity before interoperable rewards;
- clear operational metrics for Trust/Demand intelligence.

A Network module with weak prerequisites remains designed but disconnected.

---

## 25. Forbidden couplings

The following are architectural violations:

1. Network reading merchant internal tables as its normal integration.
2. Storefront mutating stock directly.
3. A second mutable stock quantity authority.
4. A second mutable points balance as loyalty truth.
5. Transformation directly editing inventory balances.
6. Variable fulfillment duplicating reservation tolerance authority.
7. ConsumerIdentity replacing merchant Customer records.
8. Global identity implying shared merchant history.
9. Public projections exposing costs/margins/internal holds without explicit design.
10. One global FIFO/FEFO/LIFO flag for all products.
11. LIFO being interpreted as accounting valuation.
12. Network availability being required for merchant-local checkout.
13. Product Passport exposing raw internal lineage by default.
14. Demand intelligence auto-mutating merchant decisions.
15. Opening P13.C or production mutation from this architecture document.

---

## 26. Acceptance conditions for future cuts

A future cross-boundary capability is architecture-compliant only if it can answer:

1. **Who owns the truth?**
2. **What durable evidence exists?**
3. **What is merely a projection?**
4. **What is the idempotency boundary?**
5. **What is the concurrency/locking boundary?**
6. **Which public/stable ID crosses boundaries?**
7. **What privacy classification applies?**
8. **What happens when the downstream system is unavailable?**
9. **How are corrections represented without rewriting history?**
10. **Which capabilities enable this, and who should never see its configuration?**

If those answers are unclear, implementation should remain in RECON/design rather than create another source of truth.

---

## 27. Canonical conclusion

Straleon is optimized by keeping the merchant operational core strict and boring, while making channel/network surfaces powerful through stable contracts and projections.

The target architecture is therefore:

> **Merchant truth → immutable evidence → deterministic projections → Storefront / Network**

not:

> **multiple channels → multiple partial truths → reconciliation later**

This convergence is binding for new architecture decisions unless a later explicit ADR supersedes a specific rule.
