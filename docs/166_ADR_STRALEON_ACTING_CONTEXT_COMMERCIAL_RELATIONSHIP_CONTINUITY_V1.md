# ADR 166 — Straleon Acting Context & Commercial Relationship Continuity V1

**Status:** ACCEPTED — CANONICAL ARCHITECTURE BINDING
**Date:** 2026-09-10
**Scope:** identity context, personal/business separation, commercial principal, relationship continuity, customer self-service, omnichannel intent and B2B correlation
**Runtime authorization:** NONE — every productive slice still requires RECON, implementation contract, tests and normal checkpoint/CI
**Companion ADR:** `docs/165_ADR_STRALEON_GLOBAL_PRODUCT_KNOWLEDGE_V1.md`
**Roadmap binding:** `docs/167_ROADMAP_AMENDMENT_GLOBAL_KNOWLEDGE_ACTING_CONTEXT_OMNICHANNEL_COMMERCE_V1.md`

---

## 1. Context

A single person can participate in Straleon in several legitimate roles.

Canonical case:

```text
Carlos
├── personal consumer
│   ├── buys groceries
│   └── buys clothing
│
└── owner/operator of Taller Carlos
    ├── buys parts from JB Repuestos
    ├── buys oils from another supplier
    ├── has supplier account-current history
    ├── serves decades of workshop customers
    └── may authorize employees to buy later
```

Carlos must not need separate human identities merely because he acts in different commercial contexts.

At the same time, creating `Taller Carlos` must never expose Carlos's private purchases to the workshop, its employees, accountant, suppliers or other merchants.

The existing `ConsumerIdentity ≠ merchant Customer` law remains binding. This ADR adds the missing question:

> **Given an authenticated person, who is acting now, for whom, under which commercial relationship, and with which authority?**

---

## 2. Decision

Straleon SHALL model an explicit **Acting Context** boundary.

Authentication answers:

```text
Who authenticated?
```

Acting Context answers:

```text
In what capacity is this person acting now?
For which personal/business principal?
Against which merchant/counterparty?
Under which membership/role/relationship?
For what operation?
```

The context is explicit, auditable where consequential, and may not be inferred merely from the last purchase or from the existence of an Organization membership.

---

## 3. Conceptual separation

The architecture distinguishes at least:

```text
PERSON / AUTHENTICATED IDENTITY
"Who is the human?"

ORGANIZATION / BUSINESS CONTEXT
"Which business workspace is active?"

COMMERCIAL / LEGAL PRINCIPAL
"On whose behalf does the commercial act occur?"

ROLE / MEMBERSHIP / AUTHORITY
"What may this human do for that business?"

MERCHANT / COUNTERPARTY RELATIONSHIP
"What commercial relationship applies between the principals?"

ACTING CONTEXT
"Which of those facts apply to this operation now?"
```

Exact persistence names are deferred to RECON.

---

## 4. ConsumerIdentity, current User runtime and future person identity

The current architecture already defines a global `ConsumerIdentity` for Storefront/Network and organization-scoped merchant customers.

This ADR does **not** silently merge the current application `User`, future `ConsumerIdentity`, staff identities or credentials.

Instead it fixes the product-level rule:

> **One real person should be able to authenticate once and select every authorized personal or organizational context without duplicating commercial histories.**

How current `User` and future `ConsumerIdentity` converge technically requires a dedicated identity RECON.

Acting Context is downstream from successful authentication and does not become a credential store.

---

## 5. Personal and business contexts remain isolated

Canonical selector:

```text
You are using Straleon as:

● Carlos
○ Taller Carlos
○ Another Organization...
```

When acting as **Carlos personally**, visible relationships may include personal commerce.

When acting as **Taller Carlos**, visible relationships may include:

- JB Repuestos;
- oil suppliers;
- workshop customers;
- workshop purchases;
- workshop account-current/payables;
- work orders;
- workshop inventory and operations.

Forbidden:

```text
Create Taller Carlos
    ↓
automatically expose Carlos's grocery/clothing/private history
```

Organization membership is never permission to read the member's private consumer history.

---

## 6. Same person, same merchant, multiple relationships

A person can have more than one valid relationship with the same merchant.

Example:

```text
Carlos Consumer Identity
   │
   ├── personal relationship with JB
   │      └── buys wipers for his own car
   │
   └── owner/member of Taller Carlos
          └── B2B relationship with JB
                 └── buys a water pump for a workshop customer
```

Therefore Straleon may ask at a meaningful boundary:

```text
Who are you buying for?

○ Me — Carlos
● Taller Carlos
```

Consequences may include:

- different price list;
- different credit/account-current eligibility;
- different tax/fiscal data;
- different delivery address;
- different loyalty/benefit scope;
- different history;
- different authorization.

The choice may be safely remembered as a UX preference, but a consequential commercial act must pin the actual selected principal/context.

---

## 7. ActingContext conceptual contract

A future context may conceptually contain:

```text
ActingContext
- authenticated_identity_ref
- mode: personal | organization
- acting_organization_ref?
- commercial_principal_ref
- membership / delegated-authority ref?
- counterparty_ref?
- commercial_relationship_ref?
- purpose / operation
- selected_at
- authorization decision refs where required
```

This is not a permission grant.

The resolver consumes existing authority decisions; it does not manufacture them.

This `ActingContext` is distinct from the Catalog CSF-7 `OperationContext`, which describes the current semantic operation on a product.

---

## 8. Commercial/legal principal

`Organization` is an operational/business workspace, not proof of separate legal personality.

Example:

```text
Organization: Taller Carlos
Legal/commercial principal today: Carlos Gómez, sole proprietor
```

Later:

```text
Organization: Taller Carlos
Legal/commercial principal: Taller Carlos SRL
```

The system must be able to represent that difference without rewriting prior acts.

Commercial/fiscal evidence must retain the principal that actually participated at the time.

---

## 9. Pinning consequential acts

Once an order, sale, account-current charge, payment, fiscal document or other consequential act is confirmed, Straleon must preserve at least enough evidence to answer:

```text
Who authenticated?
In which ActingContext?
For which principal?
Against which counterparty/relationship?
Under which authority?
```

Changing the user's current context later does not rewrite those historical facts.

---

## 10. Linking an existing merchant Customer to a Straleon identity

A merchant may already have years of customer history before the person creates a Straleon account.

Example:

```text
JB Repuestos
└── Customer #438 — Carlos Gómez
    ├── 5 years of purchases
    ├── mechanic price condition
    └── current account balance
```

After invitation/verification:

```text
ConsumerIdentity Carlos
        ↕ explicit verified link
JB Customer #438
```

The link grants the consumer an authorized self-service view of that relationship.

It does not:

- create a second Customer;
- migrate the debt into a global identity;
- share the history with other merchants;
- imply marketing consent.

---

## 11. Commercial Relationship Continuity

When Carlos later creates `Taller Carlos`, he may explicitly claim that selected pre-existing relationships correspond to that business activity.

Conceptual workflow:

```text
Carlos creates Taller Carlos
        ↓
"Link existing business relationships"
        ↓
Carlos explicitly selects:
  ✓ JB Repuestos
  ✓ Pepito Aceites
  ✗ Huevos Mauri
  ✗ Punto Nebel
        ↓
evidence / identity checks
        ↓
counterparty confirmation where required
        ↓
CommercialRelationshipContinuity
```

The outcome makes relevant history available in the business context while preserving original facts.

---

## 12. Continuity never rewrites history

If a historical invoice says:

```text
Buyer: Carlos Gómez
```

the continuity operation must not silently rewrite it to:

```text
Buyer: Taller Carlos
```

Instead Straleon records that the relationship/history is now recognized as belonging to or usable within a business context under explicit evidence.

The original principal, document, date, amounts and issuer remain immutable.

Rule:

> **Continuity adds context; it does not falsify the past.**

---

## 13. Continuity authority and conflict handling

A relationship claim may require different validation depending on risk.

Possible evidence:

- same verified person;
- same tax/legal principal;
- pre-existing merchant Customer link;
- merchant confirmation;
- business registration/tax identifiers;
- historical contact data;
- explicit counterparty acceptance.

High-impact cases such as moving credit conditions or recognizing a B2B account-current relationship should fail closed when identity/principal evidence is ambiguous.

A rejected claim does not delete either side's history.

A later correction uses supersession/reversal semantics, not destructive rewrite.

---

## 14. Account-current and reciprocal perspectives

JB may own the canonical account-receivable truth for credit it granted:

```text
JB Repuestos
Accounts Receivable
Taller Carlos owes 382,000
```

Taller Carlos may view or maintain the reciprocal accounts-payable perspective:

```text
Taller Carlos
Accounts Payable
Owes JB Repuestos 382,000
```

Straleon must not create a single global mutable debt field controlled by both parties.

Instead, cross-merchant correlation may link each party's authoritative evidence.

Differences are explicit reconciliation events, not last-writer-wins updates.

---

## 15. Commercial Relationship Thread

A superordinate but non-authoritative **Commercial Relationship Thread** may provide stable correlation between two principals/organizations.

Conceptually:

```text
CommercialRelationshipThread
JB Repuestos ↔ Taller Carlos
       │
       ├── purchase/sales order correlations
       ├── invoices/remitos/ASNs
       ├── account statements
       ├── payments/allocations references
       ├── disputes/differences
       └── continuity evidence
```

The thread is a correlation/envelope, not a shared operational ledger.

Each fact retains:

- issuer/owner;
- source authority;
- privacy class;
- immutable identifiers;
- correlation to the counterpart fact where known.

This allows zero avoidable double-entry without destroying organization sovereignty.

---

## 16. Additive privacy class: COUNTERPARTY_SHARED

This ADR adds a privacy concept to the existing classification model:

```text
COUNTERPARTY_SHARED
```

Meaning:

> information explicitly authorized for an authenticated commercial counterparty, scoped to a specific bilateral relationship/purpose, but not public to Network and not readable by unrelated merchants.

Examples:

- purchase/sales order projection;
- invoice/remito/ASN projection;
- account statement shared with the debtor/customer;
- B2B availability/offer disclosed under relationship rules;
- relationship-continuity confirmation.

Rules:

- source/issuer authority remains explicit;
- sharing is purpose-scoped and revocable where legally/operationally possible;
- the class does not expose internal margin, other customers, private stock policy or unrelated notes;
- `COUNTERPARTY_SHARED` does not become `PUBLIC_NETWORK` by inference.

This is additive to `PRIVATE_INTERNAL`, `MERCHANT_CHANNEL`, `CONSUMER_ACCOUNT`, `PUBLIC_NETWORK` and `AGGREGATED_NETWORK`.

---

## 17. Delegated business actors

A business relationship belongs to the business/principal, not to one employee's password.

Example:

```text
Taller Carlos
├── Carlos — owner
└── Pedro — authorized buyer
```

Pedro may buy from JB for Taller Carlos only if:

- he has a valid organization membership/delegation;
- the action is within capability/scope/limits;
- the active ActingContext is Taller Carlos;
- any required approval/step-up succeeds.

The purchase belongs to Taller Carlos's commercial history while retaining Pedro as the actor.

Revoking Pedro's access does not erase the business history.

---

## 18. Consumer self-service projection

A verified consumer may see an authorized projection of their relationship with a merchant:

- account-current balance;
- charges and payments;
- purchase/order history;
- documents/receipts;
- warranties/after-sales;
- benefits/price conditions where publishable to that consumer;
- pending actions.

This is a read/projection surface over merchant-owned truths.

It is not a second ledger.

JB no longer needs to rotate the monitor or print a paper statement merely so a customer can know their own balance.

---

## 19. Omnichannel is one intent, many transports

P15 channels must not become parallel business systems.

Ingress examples:

```text
Storefront
WhatsApp Business
Instagram
marketplace messaging
future channels
```

They feed a normalized interaction/intention layer.

The channel-specific message is transport/evidence.

The business need is channel-agnostic.

---

## 20. CommercialIntentCase

Straleon SHALL introduce a future **CommercialIntentCase** concept before building many omnichannel adapters.

It answers:

> **What is this person/business trying to resolve with this merchant, what context is already known, what has Straleon resolved automatically, and what still requires human attention?**

Conceptual fields/evidence may include:

```text
CommercialIntentCase
- merchant / counterparty
- consumer identity or anonymous session
- ActingContext / commercial principal when known
- source channel(s)
- intent type
- product / service / vehicle / asset context
- structured answers/evidence
- Knowledge resolution references
- offer / availability references
- pricing/relationship context
- resolution confidence
- current resolution state
- required next action
- correlation to cart/order/service case when created
```

Exact model/table/state names require RECON.

---

## 21. A case may start anonymous and become identified

At 02:00 a Storefront visitor can ask:

```text
"Does this fit my car?"
```

The case may begin with an anonymous session.

Straleon can progressively ask:

```text
make
model
year
version
engine
VIN/chassis when genuinely required
```

If the person later authenticates, the case may link to their identity/relationship under explicit rules without duplicating the case.

Anonymous browsing remains allowed where merchant policy permits.

---

## 22. Automated resolution before human attention

Canonical resolution sequence:

```text
incoming intent
      ↓
recover known structured context
      ↓
ask only missing relevant questions
      ↓
Global Product Knowledge / compatibility
      +
merchant PublicOffer / Commercial Availability
      +
pricing / customer relationship policy
      ↓
confidence/authority gate
   ┌──┴──────────────────┐
   │                     │
resolved safely       unresolved
   │                     │
automatic answer      structured case
cart/order path       REQUIRES_ATTENTION
                         │
                         ▼
                 Operational Attention Center
```

This is the operating promise for a solo merchant such as JB Repuestos:

> **Straleon keeps resolving repetitive commerce while the merchant sleeps; the morning inbox contains only what genuinely needs a human.**

---

## 23. Operational Attention integration

`CommercialIntentCase` owns the interaction/resolution state.

The existing Operational Attention Center consumes a projection such as:

```text
REQUIRES_ATTENTION
```

and routes it to the person who can resolve it.

The Attention Center does not duplicate case state.

When the case leaves the actionable state, the attention projection disappears/updates deterministically.

---

## 24. CRM integration

CRM/P19 consumes merchant relationship and interaction history.

CRM must not create another customer master or another conversation truth.

Conceptually:

```text
Merchant Customer / Relationship
       +
CommercialIntentCases
       +
orders / purchases / service
       +
consent/preferences
       ↓
Customer 360 / analytics
```

Channel messages are evidence/context, not the CRM's own competing business record.

---

## 25. Need Engine and Demand-to-Supply integration

Unresolved or unavailable intents can become powerful demand signals.

Example:

```text
many customers ask for part X
JB does not stock it
       ↓
privacy-safe aggregate demand signal
       ↓
Demand-to-Supply / purchasing intelligence
```

Rules:

- raw private conversations do not become Network-wide customer histories;
- cross-merchant analytics use allowed aggregated/de-identified projections;
- demand intelligence is advisory unless an authorized merchant accepts an action;
- no automated supplier order merely because demand was observed.

---

## 26. Omnichannel response routing

A case may start on one channel and continue on another when authorized.

Example:

```text
Storefront question
    ↓
customer chooses WhatsApp follow-up
    ↓
same CommercialIntentCase
```

Straleon must not create a second business case simply because the transport changed.

Consent and transactional communication purpose remain explicit.

Marketing permission is separate.

---

## 27. Merchant-to-network acquisition loop

An existing merchant can invite its customers to Straleon:

```text
JB Customer
    ↓ invitation
ConsumerIdentity
    ↓
self-service relationship with JB
    ↓
"Do you operate a business?"
    ↓
Taller Carlos Organization
```

The person's new Organization may later become another Straleon merchant.

The loop is recursive:

```text
JB → mechanics/workshops → their customers → more identities/businesses
```

This is a network-growth mechanism, not permission to share private histories between merchants.

---

## 28. Integration with pricing and loyalty

Professional pricing such as “mechanic -10%” belongs to the merchant relationship/commercial pricing rules, not automatically to Loyalty.

Acting Context determines which relationship is eligible to be evaluated.

Example:

```text
Carlos personally → public price
Taller Carlos → mechanic/professional price list
```

Loyalty remains a separate append-only points/benefits domain.

---

## 29. Integration with Storefront

Storefront must eventually understand:

- anonymous vs authenticated visitor;
- active ActingContext;
- personal vs Organization principal;
- merchant relationship;
- relationship-specific pricing/credit eligibility;
- consumer self-service projections;
- CommercialIntentCase;
- structured escalation;
- stable public IDs;
- privacy classes.

Storefront remains a channel, not a second customer, credit, pricing, inventory or interaction backend.

---

## 30. Integration with Merchant Exchange

Merchant Exchange requires more than knowing two Organizations exist.

Before B2B runtime, it should be able to rely on:

- verified ActingContext;
- commercial principal;
- organization membership/delegation;
- CommercialRelationshipThread or equivalent correlation;
- `COUNTERPARTY_SHARED` privacy projection;
- structured documents/events;
- independent authorities on each side;
- reconciliation semantics.

This prevents B2B from becoming “shared database access.”

---

## 31. Canonical JB / Taller Carlos acceptance case

Future implementations must prove this scenario remains possible:

1. JB has a five-year Customer/account-current relationship with Carlos;
2. Carlos creates/links one Straleon identity;
3. he can view JB relationship self-service without exposing it to other merchants;
4. Carlos creates `Taller Carlos`;
5. he explicitly claims the JB and oil-supplier relationships as business relationships;
6. grocery/clothing relationships remain private personal relationships;
7. counterparties confirm high-impact continuity where required;
8. historical documents remain unchanged;
9. current valid credit/price conditions can continue under authorized mapping;
10. Carlos can still buy personally from JB;
11. Carlos can buy as Taller Carlos under business pricing/CxC;
12. a future authorized employee can buy for Taller Carlos without becoming owner of the relationship;
13. both organizations can correlate structured documents without sharing internal databases.

---

## 32. Forbidden designs

Architectural violations include:

1. Organization membership exposes personal consumer history;
2. creating a business automatically moves every personal purchase into it;
3. same merchant forces one relationship/context for every purchase by a person;
4. continuity rewrites historical invoices or fiscal evidence;
5. global `ConsumerIdentity` becomes a global account-current ledger;
6. two organizations directly edit one shared debt field;
7. an employee owns the business relationship because they placed an order;
8. channel-specific WhatsApp/Instagram tables become separate customer/order truths;
9. CRM invents a second customer master;
10. Operational Attention duplicates the underlying case state;
11. CommercialIntentCase directly mutates inventory/money/fiscal truth;
12. an unresolved question becomes public demand data with identifiable private history;
13. registration implies marketing consent;
14. ActingContext grants permission rather than consuming valid authority;
15. context changes retroactively rewrite confirmed acts.

---

## 33. Canonical outcome

Straleon succeeds when Carlos can remain one person, keep private life private, operate Taller Carlos under the same authenticated experience, continue legitimate years-old business relationships without falsifying the past, delegate business actions safely, and let every Storefront/WhatsApp/Instagram interaction converge into one structured commercial intent that Straleon resolves automatically whenever possible.

> **One person, many authorized contexts. One relationship history, no rewritten past. Many channels, one commercial intent.**
