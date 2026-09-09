# CSF-7 — Contextual Requirement / Interaction Resolution Contract

**Status:** FROZEN CONTRACT — P13.B
**Version:** V1
**Persistence:** NONE
**Runtime implementation:** NOT AUTHORIZED BY THIS DOCUMENT
**P13.C:** CLOSED

---

## 1. Purpose

CSF-7 defines the read-only composition boundary that answers:

> **Given this product, this operation, this actor and the current authoritative state, what information is relevant, what is still required, what may be shown, and what actions may be offered now?**

CSF-7 exists to support Straleon's canonical UX principle:

- maximum architectural power behind the scenes;
- minimum cognitive load for the operator;
- safe defaults;
- progressive configuration;
- everyday language;
- guided tasks;
- hiding irrelevant controls;
- never asking the user to decide something the system can already derive from authoritative facts.

CSF-7 does **not** create a new business truth.

It derives an ephemeral interaction projection from truths already owned by Catalog, Authorization, Inventory, Commerce, Fulfillment and other authoritative domains.

---

## 2. Architectural position

Conceptually:

```text
Effective Semantic Profile
        +
Current Semantic Values
        +
Effective Semantic Capabilities / Policies
        +
Operation Context
        +
Current Authoritative Operational State
        +
Actor / Permission Facts
        +
Lifecycle Status
        |
        v
Contextual Requirement / Interaction Resolution
        |
        v
Ephemeral Contextual Interaction Profile
```

The output is a projection for interaction.

It is **not** a source of truth.

It is **not** stored as durable state in V1.

---

## 3. Canonical ownership rule

CSF-7 MUST preserve existing domain ownership.

### Catalog owns semantic facts

Catalog remains authoritative for:

- ProductDefinition assignment;
- current ProductSchemaVersion;
- AttributeDefinitions;
- AttributeBindings;
- typed semantic values;
- semantic capability declarations;
- effective semantic profile provenance;
- semantic lifecycle state.

CSF-7 MUST NOT infer semantic meaning from:

- category;
- product name;
- SKU;
- brand;
- manufacturer;
- Knowledge;
- free text;
- AI output;
- Storefront metadata.

### Authorization owns authorization facts

The authorization system remains authoritative for:

- principal identity;
- capability/permission decisions;
- organization-scoped authorization;
- role-derived authorization;
- explicit deny/allow results.

CSF-7 MUST NOT grant permissions.

### Operational domains own operational facts

Inventory, Commerce, Fulfillment and other operational domains remain authoritative for their own facts and transitions.

Examples include:

- physical inventory;
- commercial availability;
- reservations;
- fractional containers;
- variable-quantity fulfillment;
- fulfillment preferences;
- sale state;
- settlement/review state;
- post-sale state;
- inventory movement state;
- negative-stock authorization;
- location-specific operational state.

CSF-7 MUST NOT reproduce those rules as parallel logic.

---

## 4. No new persistence in V1

CSF-7 V1 MUST NOT introduce a persistence authority for contextual interaction.

Forbidden as a CSF-7 V1 source of truth:

- `contextual_interaction_profiles` table;
- `contextual_requirements` table;
- `interaction_visibility_rules` table;
- mutable `is_visible`, `is_required`, `is_enabled` flags on CatalogProduct;
- cached durable action availability;
- serialized JSON interaction state;
- durable copies of permission decisions;
- durable copies of operational state;
- durable copies of semantic profile/value state.

A CSF-7 result MUST be recomputable from current authoritative facts.

Caching MAY be considered later only as a disposable projection optimization and MUST NOT become authoritative.

---

## 5. V1 subject boundary

The canonical CSF-7 V1 subject is:

```text
CatalogProduct
```

CSF-7 may consume current Product-scope semantic values established by CSF-6.

V1 does not authorize a generic polymorphic interaction subject engine.

Reserved for later contracts:

- Variant;
- InventoryUnit;
- Lot/Batch as independent semantic interaction subject;
- SupplierOffer as semantic subject;
- arbitrary external subjects.

Operational facts about those objects may still be consumed when an authoritative operation concerning a CatalogProduct requires them.

---

## 6. Required authoritative inputs

A contextual interaction resolution MUST be based only on authoritative inputs relevant to the requested interaction.

The resolver may consume, directly or through canonical readers/resolvers:

1. **CatalogProduct**
2. **Effective Semantic Profile**
3. **Current effective semantic values**
4. **Effective semantic capability/policy decisions**
5. **Requested operation context**
6. **Current operational state from owning domains**
7. **Actor/principal authorization facts**
8. **Relevant lifecycle status**
9. **Organization/current-tenant boundary**
10. **Other explicit authoritative facts required by the owning operation**

No input may be silently guessed.

---

## 7. Operation context

CSF-7 resolves an interaction **for a concrete operation or interaction purpose**.

Examples:

- edit product semantic data;
- reserve stock;
- perform checkout;
- prepare variable quantity;
- consume a fractional container;
- confirm an inventory movement;
- resolve a post-sale action.

The operation identity MUST be explicit.

CSF-7 MUST NOT scan the application and invent an operation from controller names, routes, UI labels or incidental state.

The owning operation/domain remains responsible for defining what facts it requires.

---

## 8. Canonical output: ephemeral Contextual Interaction Profile

The conceptual V1 output is an immutable, ephemeral profile.

It MUST be sufficient to express, when applicable:

### 8.1 Relevant information

Which semantic or operational information is relevant to the current interaction.

### 8.2 Requirement state

For a relevant piece of information, whether it is:

- already satisfied;
- missing and required for this interaction;
- optional;
- not applicable in this context;
- unavailable because an authoritative prerequisite cannot currently be resolved.

### 8.3 Interaction affordances

Which actions or controls may be **offered to the user** based on current authoritative facts.

Examples:

- show;
- hide;
- enable;
- disable;
- request additional input;
- explain why an action is unavailable.

These are interaction projections, not authorization grants.

### 8.4 Reasons

A blocked, hidden, disabled or missing requirement SHOULD carry a machine-readable reason and a human-presentable explanation source.

The reason MUST identify the authoritative cause rather than invent a new CSF-7 policy.

### 8.5 Provenance

The profile MUST retain enough provenance to explain which authoritative inputs produced the decision.

At minimum, provenance must preserve identities already exposed by upstream resolvers when available, such as:

- ProductDefinition;
- ProductSchemaVersion;
- AttributeBinding;
- semantic capability/policy provenance;
- permission/capability decision;
- operation/domain authority.

---

## 9. Requirement semantics

A CSF-7 "requirement" is contextual.

It does **not** mean that the underlying semantic attribute becomes globally mandatory.

Example:

```text
Attribute: expiration_date
Schema: available for this product definition

Inventory receiving operation:
    may require it for a lot/expiry-tracked flow

Ordinary catalog browse:
    may not require it

Checkout:
    may only consume derived availability and never ask the cashier
    to re-enter the expiration date
```

Therefore:

> **Contextual requirement != schema-global required flag.**

CSF-7 V1 MUST NOT add universal `required` semantics to AttributeBinding unless a separate future contract explicitly authorizes them.

---

## 10. Semantic value interpretation

CSF-7 may determine whether a contextual semantic requirement is satisfied by using the **current effective semantic values** exposed by CSF-6.

It MUST NOT:

- read stale semantic rows as current;
- rebind a historical value to another AttributeBinding;
- reinterpret a value under another schema;
- convert units by assumption;
- create default semantic values;
- mutate semantic values;
- synthesize missing semantic values.

No current value means the value is absent/unknown according to CSF-6 semantics.

---

## 11. Capability interpretation

CSF-7 may use effective semantic capability/policy resolution to decide whether an interaction branch is relevant.

Examples:

- a simple unit product should not expose lot-expiry controls;
- a serialized product may expose specific-unit selection and hide FIFO/FEFO/LIFO controls;
- a lot/expiry product may expose expiry-sensitive interaction;
- a fractional/container product may expose its relevant consumption interaction.

CSF-7 MUST consume the effective capability result.

It MUST NOT independently recreate capability activation or inheritance rules.

---

## 12. Authorization interpretation

CSF-7 may hide or disable an interaction when the authoritative principal is not allowed to perform it.

However:

> **Visibility or enablement is never the security boundary.**

Every state-changing operation MUST revalidate authorization through its owning authoritative path at execution time.

CSF-7 MUST NOT make an allow decision that bypasses the existing authorization contract.

If authorization cannot be resolved, the interaction MUST fail closed for any privileged action.

---

## 13. Operational state interpretation

CSF-7 may consume current state to make the interface context-aware.

Examples:

- do not offer a reservation action that the current authoritative availability rules reject;
- do not offer a destructive lifecycle transition that the owning manager disallows;
- do not ask for a variable-weight tolerance when the product/operation does not use variable quantity;
- do not expose fractional-container controls to products that do not participate in that flow.

The source of each state fact MUST remain the owning domain.

CSF-7 MUST NOT query raw tables and reconstruct business rules when an authoritative reader/resolver/manager contract already exists.

---

## 14. Lifecycle interpretation

CSF-7 may consume lifecycle state but MUST preserve lifecycle ownership.

Examples of relevant existing lifecycle facts include:

- ProductDefinition status;
- current published ProductSchemaVersion;
- release/lifecycle state where applicable.

CSF-7 MUST NOT:

- publish a schema;
- activate/deprecate/retire a ProductDefinition;
- reclassify a CatalogProduct;
- bypass CSF-5 or CSF-6 lifecycle guards.

---

## 15. Unclassified products

A CatalogProduct with no ProductDefinition remains **UNCLASSIFIED** according to CSF-5.

For an unclassified product:

- CSF-7 MUST NOT infer a semantic definition;
- semantic-specific requirements are absent unless/until explicit classification exists;
- generic operations may remain available when their own authorities allow them;
- the UI may offer an explicit classification interaction if the actor is authorized.

Unclassified does not mean broken.

It means semantic specialization has not been assigned.

---

## 16. Determinism and freshness

Given the same authoritative inputs, CSF-7 MUST produce the same contextual profile.

The profile is point-in-time.

Because state can change between rendering and execution:

- CSF-7 output MUST NOT be treated as a durable promise;
- state-changing operations MUST revalidate their own invariants;
- stale interaction output MUST fail safely at execution.

---

## 17. Fail-closed behavior

CSF-7 MUST fail closed when a required authority cannot be resolved.

It MUST NOT compensate by:

- guessing a ProductDefinition;
- assuming permission;
- assuming stock;
- assuming availability;
- assuming an operation state;
- assuming a capability;
- silently treating unknown as allowed.

A read-only UI may still present a safe generic explanation when possible.

---

## 18. Side effects

Contextual resolution MUST be side-effect free.

Forbidden during resolution:

- semantic value writes;
- classification/reclassification;
- inventory movements;
- reservation creation/release;
- checkout;
- fulfillment mutation;
- permission mutation;
- policy mutation;
- audit events that imply a business mutation;
- database writes whose only purpose is to store the interaction profile.

Pure telemetry may be considered separately, but it is not part of this contract.

---

## 19. Final execution authority

A key V1 law:

> **CSF-7 may recommend or expose an action; the owning domain decides whether the action actually executes.**

Examples:

- "Reserve" may be shown, but `InventoryReservationManager` remains authoritative.
- "Checkout" may be shown, but Commerce remains authoritative.
- "Consume container" may be shown, but the fractional-container domain remains authoritative.
- "Reclassify" may be shown, but `CatalogProductDefinitionAssignmentManager` remains authoritative.

This prevents CSF-7 from becoming a second operational rule engine.

---

## 20. Progressive UX law

The interaction profile SHOULD make the simple case simple.

Canonical behavior:

- hide irrelevant advanced settings;
- prefer inherited/default effective policy;
- ask only for information required by the current interaction;
- reuse information Straleon already knows;
- explain blocked actions in ordinary language;
- preserve expert capability without exposing it to every operator;
- progressively reveal complexity only when the product, operation or authorization requires it.

This is an architectural requirement, not merely a visual preference.

---

## 21. No category coupling

ProductCategory remains a commercial classification, not a semantic authority.

Changing category MUST NOT silently:

- change ProductDefinition;
- change schema;
- activate capabilities;
- change contextual requirements;
- enable operational behavior.

Any such semantic or operational change requires its own authoritative mechanism.

---

## 22. Knowledge / AI boundary

Knowledge and future AI assistance may suggest or explain.

They MUST NOT become silent authority for CSF-7 decisions.

A future assistant may say:

> "This appears to be a tyre; would you like to classify it as TYRE?"

It may not silently classify the product or activate tyre-specific requirements.

---

## 23. Storefront and Network boundary

CSF-7 V1 does not open Storefront or Straleon Network runtime.

Future Storefront/Network projections may consume an explicitly authorized contextual projection, but:

- merchant operational truth remains organization-scoped;
- Storefront/Network do not become semantic or operational authorities;
- Network remains downstream of merchant-owned projections/events;
- P13.C remains closed.

---

## 24. V1 non-goals

Not authorized by this contract:

- new contextual persistence;
- generic workflow engine;
- generic rules DSL;
- user-authored conditional expressions;
- AI-generated authoritative requirements;
- schema-global `required` attributes;
- computed semantic attributes;
- multivalue semantic fields;
- localized semantic values;
- unit conversion engine;
- generic polymorphic semantic subjects;
- Storefront runtime;
- Network runtime;
- P13.C.

---

## 25. Conceptual resolver contract

Implementation MAY expose a resolver equivalent to:

```text
ContextualInteractionProfileResolver
    -> resolve(CatalogProduct, explicit operation context, authoritative actor context)
    -> ContextualInteractionProfile
```

Exact PHP names are not frozen by this conceptual signature.

What **is** frozen:

- the resolver is read-only;
- the result is ephemeral;
- the operation is explicit;
- authoritative facts are consumed, not recreated;
- no new persistence is introduced;
- the owning domain revalidates before mutation.

---

## 26. Provenance and explainability

A contextual decision SHOULD be explainable.

For a relevant requirement or action, Straleon should be able to answer:

```text
Why is this field being requested?
Why is this action hidden?
Why is this action disabled?
Why is this advanced option visible?
Which product definition/schema/capability/permission/state caused it?
```

The answer must trace back to authoritative facts.

This supports:

- operator understanding;
- debugging;
- auditability of system behavior;
- future UI help text;
- safe evolution of semantic schemas and capabilities.

---

## 27. Compatibility with CSF-0 through CSF-6

CSF-7 is downstream of the semantic foundation.

It MUST preserve:

- semantic registry authority;
- ProductDefinition/category separation;
- versioned ProductSchema contracts;
- typed AttributeBindings;
- effective semantic profile provenance;
- capability declaration/policy resolution;
- explicit ProductDefinition assignment;
- CSF-6 typed semantic value identity and provenance.

CSF-7 does not rewrite any of those contracts.

---

## 28. Runtime implementation acceptance criteria

A future CSF-7 V1 implementation is acceptable only if tests demonstrate at least:

1. profile derivation is read-only;
2. no contextual persistence is created;
3. relevant semantic fields derive from the current effective profile only;
4. current CSF-6 semantic values correctly satisfy contextual requirements;
5. stale/noncurrent semantic values do not;
6. irrelevant capabilities hide their controls;
7. effective capability policy is reused rather than reimplemented;
8. unauthorized privileged actions fail closed;
9. operational state is consumed from owning authorities;
10. unclassified products do not receive inferred semantics;
11. category change does not alter semantic interaction by itself;
12. the same authoritative inputs produce the same profile;
13. state-changing managers revalidate their own rules independently of UI affordance;
14. provenance identifies the authoritative cause of contextual decisions;
15. P13.C remains closed.

---

## 29. Migration policy

CSF-7 V1 requires **no database migration**.

If implementation appears to require a migration, work MUST stop and the contract must be revisited before writing it.

No migration is implicitly authorized by this document.

---

## 30. Canonical laws summary

```text
LAW 1
CSF-7 derives interaction; it does not own business truth.

LAW 2
CSF-7 V1 has no persistence authority.

LAW 3
Semantic facts remain owned by Catalog.

LAW 4
Authorization facts remain owned by Authorization.

LAW 5
Operational facts remain owned by their operational domains.

LAW 6
Contextual requirement is not a schema-global required flag.

LAW 7
Visibility/enablement is not the security boundary.

LAW 8
Every mutating operation revalidates through its owning domain.

LAW 9
Unknown authority fails closed; it is never guessed.

LAW 10
Unclassified products remain semantically unclassified.

LAW 11
Category does not silently change semantic interaction.

LAW 12
The profile is ephemeral, deterministic and point-in-time.

LAW 13
CSF-7 must support progressive, low-cognitive-load UX.

LAW 14
No Storefront/Network runtime is opened by CSF-7.

LAW 15
P13.C remains closed.
```

---

## 31. Freeze statement

This document freezes the **CSF-7 Contextual Requirement / Interaction Resolution V1 contract**.

The authorized next engineering phase after a clean docs-only checkpoint and its natural CI is a **runtime implementation RECON/plan constrained by this contract**.

No implementation is authorized merely by creating this document.
