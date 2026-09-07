# Straleon — Storefront / Network Compatibility Pack V1

**Status:** CANONICAL CONTRACT DESIGN
**Runtime Network activation:** NO
**Purpose:** make Storefront shippable now while preserving clean Network compatibility later

---

## 1. Why this pack exists

Storefront and Straleon Network share several boundary needs, but they have different activation timelines.

Storefront must be able to ship without waiting for Network density. Network must be able to consume stable, privacy-safe contracts later without forcing a rewrite of merchant Core.

Therefore this pack defines the compatibility seam now while explicitly keeping Network runtime disconnected until its activation gates are met.

The core rule is:

> **Design Network-compatible boundaries now; do not make Network a runtime dependency now.**

### Single-truth binding

This pack is governed by the **STRALEON SINGLE-TRUTH PRINCIPLE**.

The compatibility layer MUST NOT create a second catalog, stock, commercial-availability, pricing, customer-history or order truth.

Specifically:

- `PublicOfferProjection` is not a second catalog or pricing authority;
- `PublicAvailabilityProjection` is not a second stock or Commercial Availability authority;
- a public event is evidence/transport of a published fact, not an authority replacing its merchant source;
- Storefront state may orchestrate a workflow but cannot become a second merchant operational ledger;
- Network indexes/projections may be rebuilt and may be stale; they never reconcile merchant truth by writing a competing value back.

When a public/channel representation disagrees with its authoritative merchant source, **the merchant source wins** and the projection is stale/incorrect until rebuilt or republished.

---

## 2. Contract families

This pack binds eight contract families:

1. stable external identity;
2. public offer projection;
3. public availability projection;
4. versioned event envelope;
5. Consumer Identity / Merchant Customer binding;
6. fulfillment/finalization boundary;
7. publication/privacy lifecycle;
8. failure/freshness behavior.

---

## 3. Stable external identity contract

### SNC-001 — Public IDs are opaque and stable

Internal numeric IDs MUST NOT be exposed as long-lived cross-boundary identifiers.

Public identifiers should be opaque stable values, normally UUID-compatible.

### Required external identity surfaces

| Subject | Public identity requirement |
|---|---|
| Merchant/Organization | required for Storefront/Network |
| Catalog Product | required when public/channel exposed |
| Offer | required when independently publishable/versionable |
| Consumer Identity | required globally |
| Order/Fulfillment | required when consumer/API reference is exposed |
| Product Passport | required when passport exists |
| Event | always required |
| Location | only when merchant explicitly exposes it |

### Public ID rules

- immutable after publication;
- never encode internal database IDs;
- never reuse after deletion/withdrawal;
- safe for logging/correlation;
- stable across projection rebuilds;
- merchant ownership remains explicit.

---

## 4. Public Offer Projection

`PublicOfferProjection` is a channel/public-safe representation of what a merchant chooses to offer.

It is NOT the catalog source of truth and NOT the pricing ledger.

### Minimum conceptual contract

```text
PublicOfferProjection
- schema_version
- offer_public_id
- organization_public_id
- product_public_id
- title
- summary?
- publication_state
- channel_eligibility[]
- pricing
    - mode
    - currency
    - display_amount? / amount?
    - price_context?
- fulfillment_modes[]
- promotion_summary?
- media_refs[]
- revision
- observed_at
- published_at?
- withdrawn_at?
```

Exact transport serialization is deferred to the implementation boundary, but semantic ownership is fixed.

### Forbidden offer fields by default

A public offer projection MUST NOT leak:

- purchase cost;
- internal margin;
- supplier-private terms;
- internal stock threshold;
- internal reservation counts;
- private staff notes;
- unpublished metadata.

### Pricing authority

The merchant remains price authority. Storefront/Network may cache/display a versioned projection, but transactional finalization must revalidate through the merchant-owned commerce boundary when required.

---

## 5. Public Availability Projection

`PublicAvailabilityProjection` is derived from **Commercial Availability**, not raw physical stock.

### Minimum conceptual contract

```text
PublicAvailabilityProjection
- schema_version
- organization_public_id
- product_public_id
- offer_public_id?
- availability_status
- disclosed_quantity?
- disclosed_unit_code?
- fulfillment_modes[]
- service_area_or_location_ref?
- lead_time_hint?
- revision
- observed_at
- valid_until?
- freshness_status
```

### Recommended availability status semantics

The initial contract should be able to represent at least:

- `available`
- `limited`
- `unavailable`
- `preorder`
- `unknown`

`unknown` is preferable to inventing availability from stale/missing data.

### Quantity disclosure

A merchant may choose to expose:

- exact commercially available quantity;
- rounded/bucketed quantity;
- only a qualitative status;
- no quantity at all.

The internal commercial availability calculation remains private.

### MUST NOT expose by default

- physical stock if different from promiseable stock;
- safety/protected stock;
- active reservation totals;
- wholesale/internal commitments;
- blocked lot/container internals;
- inventory rotation policy;
- negative-stock authorization details.

---

## 6. Freshness semantics

Availability without freshness is unsafe.

Every public/channel availability representation requires:

- `observed_at`;
- `revision` or equivalent monotonic version;
- optional `valid_until`;
- explicit stale/unknown handling.

### SNC-002 — Never silently transform stale data into “available”

When freshness cannot be trusted, the safe public state is `unknown` or an explicit stale state, not an optimistic guess.

Storefront may revalidate availability at reservation/checkout against merchant truth.

---

## 7. Versioned event envelope

Public/network-safe events require a stable envelope independent of internal table structure.

### Conceptual envelope

```text
StraleonPublicEvent
- event_id
- event_type
- schema_version
- occurred_at
- published_at
- organization_public_id
- subject_type
- subject_public_id
- aggregate_version?
- correlation_id?
- causation_id?
- privacy_classification
- payload
```

### Event guarantees

- `event_id` is globally stable and duplicate-safe;
- consumers MUST be idempotent;
- delivery may be at least once;
- ordering is not assumed globally;
- aggregate/version ordering may be enforced per subject where required;
- consumers can reject unsupported schema versions;
- event replay must not duplicate business effects;
- public events contain only fields allowed for their privacy classification.

### Event types

Exact names belong to the implementation/versioning phase. Categories may later include:

- offer published/changed/withdrawn;
- public availability changed;
- merchant public profile changed;
- consumer consent changed;
- loyalty ledger event;
- passport claim published/revoked;
- aggregate trust/demand signals.

Internal domain events do not automatically become public events.

---

## 8. Consumer Identity contract

### SNC-003 — Authentication identity is global; commercial relationship is local

`ConsumerIdentity` may contain:

- stable public ID;
- login provider links;
- verified email state;
- account state;
- security/recovery metadata;
- identity-level preferences allowed by policy.

It MUST NOT become a global order/customer-history table.

### Merchant Customer binding

A `MerchantCustomerLink` or equivalent binds:

```text
ConsumerIdentity
    ↕
Organization
    ↕
merchant-private Customer
```

The merchant customer side may hold merchant-authorized:

- local display/contact information;
- merchant-specific addresses where applicable;
- merchant-specific notes;
- merchant-specific loyalty relation;
- local order/commercial history;
- communication preferences/consent evidence.

No other merchant receives that relationship by implication.

---

## 9. Authentication and checkout modes

Storefront should support:

### Public browse

No consumer account required for public catalog/discovery.

### Registered checkout

Merchant policy may require authenticated `ConsumerIdentity`.

### Guest checkout

Merchant policy may permit guest checkout.

### Identity UX foundation

The architecture supports:

- federated login such as Google;
- native email/password;
- email verification;
- password recovery/reset;
- controlled account linking;
- duplicate-account prevention;
- stable consumer public ID.

These are identity capabilities, not reasons to share merchant-private Customer data.

---

## 10. Consent and communication preferences

Registration or purchase MUST NOT imply marketing consent.

Consent evidence should distinguish at least:

- transactional communication necessary for fulfillment;
- merchant marketing consent;
- Straleon/network marketing consent;
- specific channels when legally/operationally relevant.

Consent must be:

- explicit where required;
- attributable;
- timestamped/versioned;
- revocable;
- scoped to the party and purpose.

Fulfillment consultation through a chosen channel is not automatically marketing permission.

---

## 11. Fulfillment contract

Storefront must treat fulfillment as a merchant-owned workflow with durable intent/evidence boundaries.

### Current binding sequence

```text
consumer intent
    ↓
InventoryReservation
(quantity + tolerance)
    ↓
FulfillmentPreference
(substitution / contact / fallback)
    ↓
preparation / picking
    ↓
VariableQuantityFulfillment
(measured + accepted actual, if applicable)
    ↓
merchant finalization
    ↓
money / fiscal closure
```

### SNC-004 — Storefront never writes stock

Any stock effect occurs through merchant inventory commands/movements.

### SNC-005 — Quantity authorities remain separate

- reservation owns the commercial hold/tolerance envelope;
- variable fulfillment owns measured/accepted actual evidence;
- transformation owns material lineage only when material identity/composition changes;
- money/fiscal closure owns the final monetary/legal boundary.

---

## 12. Substitution / consultation design

Fulfillment Preference records what the consumer allows or requires.

Future substitution resolution should be separate durable evidence containing, where relevant:

- original reserved product;
- proposed alternative;
- decision source;
- consumer/operator decision;
- consultation channel;
- decision timestamp;
- impact on quantity/price requiring revalidation.

A preference MUST NOT itself execute a substitution.

A consultation preference MUST NOT itself send WhatsApp/email/SMS.

Messaging delivery remains a separate integration capability.

---

## 13. Publication lifecycle

Internal existence does not mean public visibility.

### Canonical lifecycle

```text
PRIVATE
  │ explicit merchant publish
  ▼
PUBLISHED
  │ temporary pause
  ▼
SUSPENDED
  │ resume
  └──────────────► PUBLISHED

PUBLISHED / SUSPENDED
  │ merchant withdrawal
  ▼
WITHDRAWN
```

Implementation may use different internal enums, but semantics are binding.

### Publication rules

- publication is merchant opt-in;
- withdrawal removes public discoverability/projection;
- withdrawal does not erase merchant historical truth;
- projection rebuild honors current publication state;
- public IDs are not recycled;
- privacy-sensitive fields require explicit projection rules.

---

## 14. Data classification gates

Canonical classifications:

### `PRIVATE_INTERNAL`

Examples:
- internal cost/margin;
- raw stock internals;
- reservation internals;
- private Customer history;
- staff/internal notes;
- internal transformation workflow;
- supplier terms.

### `MERCHANT_CHANNEL`

Safe for an authorized merchant channel such as its Storefront.

### `CONSUMER_ACCOUNT`

Specific to the authenticated consumer and authorized relationship.

### `PUBLIC_NETWORK`

Explicitly publishable.

### `AGGREGATED_NETWORK`

De-identified/aggregated analytics/intelligence.

No data crosses to a broader class without an explicit projector/policy.

---

## 15. Network consumers

### Merchant Network

Consumes merchant public profile/publication projections.

### Discovery Network

Consumes `PublicOfferProjection` + `PublicAvailabilityProjection` and later ranking signals.

### Loyalty Network

Consumes explicitly permitted identity/transaction/ledger events, not the merchant’s complete order database.

### Product Passport

Consumes explicit passport claim projections.

### Trust Passport

Consumes derived public-safe verified signals, not raw internal logs.

### Need Engine / Demand-to-Supply

Consumes public and aggregate demand/availability signals.

### Straleon Connect

Exposes versioned contracts; it does not proxy internal database schemas.

---

## 16. Loyalty compatibility

Loyalty architecture must remain compatible with the identity split.

### Merchant-scoped relationship

Points/benefits belong to a merchant relationship unless explicitly defined otherwise.

### Ledger rule

Points are append-only evidence with derived balance.

Required eventual ledger behaviors:

- earning;
- redemption;
- exact reversal;
- expiration;
- campaign/source attribution;
- merchant funding vs future Straleon funding distinction.

### Cross-merchant redemption

Deferred until a dedicated clearing/settlement ledger exists.

Consumer global identity is necessary for interoperability but is not sufficient to make balances globally spendable.

---

## 17. Product Passport compatibility

Internal traceability can later produce explicit passport claims.

The projection boundary must support:

- claim version;
- issuer;
- subject public ID;
- claim type;
- evidence timestamp;
- revocation/supersession;
- privacy-safe payload.

Raw internal movement/transformation/service records are never automatically published.

---

## 18. Trust / ranking compatibility

Discovery ranking may later consider combinations of:

- availability;
- distance;
- price;
- fulfillment modes;
- delivery;
- merchant reputation/trust;
- loyalty benefits.

Ranking inputs must identify freshness and provenance. Trust signals should be explainable at the category level and recomputable from evidence.

---

## 19. Failure isolation

### SNC-006 — Network outage does not block merchant commerce

Merchant-local Storefront checkout must not require live Network availability unless the merchant explicitly chooses an optional Network-dependent function.

### Storefront dependency hierarchy

Preferred:

```text
Storefront
   ↓
merchant/channel contracts
   ↓
Merchant Core
```

Not preferred:

```text
Storefront
   ↓
Network
   ↓
Merchant Core
```

### Projection failure behavior

- stale availability becomes stale/unknown;
- invalid publication fails closed;
- an event publishing failure can retry asynchronously;
- duplicate event delivery causes no duplicate business action;
- private data is never exposed as a fallback.

---

## 20. Activation gates

### Gate A — Storefront compatibility

Can be implemented during natural roadmap boundaries:

- stable external IDs;
- public offer/availability contracts;
- Consumer Identity split;
- event/idempotency contract;
- fulfillment/finalization contract;
- consent/preferences.

This work MUST NOT materially delay Storefront.

### Gate B — Merchant / Discovery Network

Requires:

- stable projections;
- freshness semantics;
- explicit publication;
- sufficient merchant/offer density;
- operational monitoring/replay.

### Gate C — Loyalty Network

Requires:

- Consumer Identity maturity;
- consent model;
- merchant relationship boundary;
- append-only loyalty ledger;
- exact reversal/expiration.

### Gate D — Product Passport / Trust / Demand

Requires:

- meaningful verified evidence;
- explicit privacy-safe projectors;
- versioned claims/signals;
- evidence that runtime value justifies activation.

### Gate E — Cross-merchant settlement

Requires dedicated clearing/settlement architecture. Not implied by identity or Network membership.

---

## 21. Compatibility implementation checklist

Every Storefront-facing feature should verify:

- [ ] stable public subject ID exists if externally referenced;
- [ ] authoritative merchant owner is explicit;
- [ ] idempotency is defined;
- [ ] public/channel projection is separate from internal truth;
- [ ] privacy classification is assigned;
- [ ] stale/failure behavior is explicit;
- [ ] Network is not a mandatory synchronous dependency;
- [ ] correction/replay semantics are defined;
- [ ] public event schema is versionable if emitted;
- [ ] consumer identity is not confused with merchant Customer.

---

## 22. Non-goals of V1

This contract does not activate:

- Network runtime;
- public search/discovery runtime;
- loyalty runtime;
- Product Passport runtime;
- Trust Passport runtime;
- Need Engine runtime;
- Merchant Exchange runtime;
- Straleon Connect public API runtime;
- cross-merchant clearing;
- production mutation.

It creates the seam that lets those modules arrive later without forcing Storefront or Merchant Core to be rebuilt.

---

## 23. Canonical outcome

The Compatibility Pack is successful when Storefront can ship against merchant-owned contracts and the exact same contracts can later feed Network-safe projectors/events without making Network a source of merchant truth.
