# ADR — Straleon Catalog Semantic Foundation

**Document:** `157_ADR_CATALOG_SEMANTIC_FOUNDATION.md`
**Status:** ACCEPTED — CANONICAL ARCHITECTURE BINDING
**Product:** Straleon
**Program:** P13.B — horizontal Catalog foundation
**Runtime authorization:** NONE
**Effective date:** 2026-09-08

---

## 1. Context

Straleon already has a deliberately generic, multirubro Catalog foundation:
`CatalogProduct`, `ProductCategory`, `Brand`, `Manufacturer`, `TechnicalModel`,
identifiers, compatibility, units and inventory relationships.

That genericity is correct, but it does not yet answer a different question:

> **How should Straleon understand what a product actually is, which information
> describes it, which capabilities apply to it, and which subset of that
> complexity should a user see in the current operation?**

A universal product form with every possible field is not an acceptable solution.

A smartphone, tyre, bolt, dress, cheese, lubricant, service part and bakery
ingredient do not require the same data, inventory semantics, fulfillment
policies, Storefront presentation or validation rules.

The architecture therefore needs a semantic layer that is reusable across
verticals without forking Catalog and without pushing domain complexity into the
operator interface.

---

## 2. Decision

Straleon adopts **Catalog Semantic Foundation (CSF)**.

CSF separates four questions:

```text
1. WHAT IS IT?
   → ProductDefinition

2. WHAT INFORMATION DESCRIBES IT?
   → ProductSchema / AttributeDefinition / future bindings

3. WHAT CAN STRALEON DO WITH IT?
   → capabilities

4. WHAT DOES THIS PERSON NEED TO DO NOW?
   → contextual interaction
```

Canonical conceptual flow:

```text
ProductDefinition
      ↓
ProductSchemaVersion
      ↓
AttributeDefinitions / future AttributeBindings
      ↓
Capability declarations
      ↓
EffectiveSemanticProfile

EffectiveSemanticProfile
+ OperationContext
+ Permissions
+ CurrentProductState
      ↓
ContextualInteractionProfile
```

CSF is a **semantic and capability architecture**, not a second Catalog,
Knowledge, inventory or public-network authority.

---

## 3. Governing UX laws

The following rules are binding:

> **Domain complexity must live inside Straleon, not inside the user's form.**

> **The interface must show only the properties and capabilities relevant to the
> object and operation currently being handled.**

> **Straleon should be more complex internally so that it can be simpler
> externally.**

> **Straleon should request each datum when that datum naturally becomes known,
> not earlier.**

A useful acceptance test is the **persona test**:

> Can a new, occasional or regular employee complete their ordinary task without
> understanding the internal architecture that an administrator or domain
> specialist needs?

If not, redesign the interaction. Do not weaken semantic truth merely to simplify
the implementation.

---

## 4. Product Core remains small

CSF does not move every product-specific concern into `CatalogProduct`.

Existing first-class concepts remain first-class:

- `CatalogProduct`;
- `ProductCategory`;
- `Brand`;
- `Manufacturer`;
- `TechnicalModel`;
- identifiers;
- compatibility;
- units/presentations;
- inventory relationships.

The Product Core must not become:

- a universal table with hundreds of nullable columns;
- a giant JSON bag with no contract;
- an inventory-policy container;
- a Storefront schema;
- a Network payload;
- a Knowledge replacement.

---

## 5. ProductDefinition

`ProductDefinition` answers:

> **What semantic kind of product is this?**

Examples:

```text
straleon.catalog.smartphone
straleon.catalog.tyre
straleon.catalog.fastener.bolt
straleon.catalog.cheese
straleon.catalog.lubricant
```

It is canonical and reusable across organizations.

It is not organization-owned.

A ProductDefinition may later select or compose:

- schema versions;
- attribute bindings;
- capability profiles;
- requirement gates;
- Storefront-safe semantic projections.

But the definition itself remains semantic identity, not product-instance data.

---

## 6. Critical Knowledge / Object Master boundary

This boundary is mandatory.

Straleon already has Knowledge, `Entity`, `TechnicalModel`, identifiers and
compatibility concepts that answer questions such as:

```text
What concrete thing/model/entity is this?
Which identifiers does it have?
Which technical models are compatible?
Which universal identity or relation does it carry?
```

CSF answers a different question:

```text
What semantic class of product is it?
Which schema/capabilities may apply to that class?
```

Therefore:

> **ProductDefinition is not a second Object Master.**

And:

```text
ProductDefinition ≠ Entity
ProductDefinition ≠ TechnicalModel
ProductDefinition ≠ CatalogProduct
ProductDefinition ≠ ProductCategory
ProductDefinition ≠ InventoryUnit
```

Example:

```text
ProductDefinition:
straleon.catalog.smartphone

TechnicalModel:
a concrete Samsung / Apple / Motorola technical model

CatalogProduct:
the merchant-facing product record that may later reference that definition
```

CSF may reference or cooperate with Knowledge later. It must never duplicate
universal identity, identifiers, compatibility or technical-model authority.

---

## 7. ProductCategory boundary

`ProductCategory` is commercial/navigation taxonomy.

`ProductDefinition` is semantic identity.

Changing a product's category must never silently reclassify its
ProductDefinition.

A product can move between merchandising categories while remaining the same
semantic kind.

Future explicit reclassification must be a controlled operation with validation
and migration/evidence rules when semantic data already exists.

---

## 8. ProductSchemaVersion

A ProductDefinition evolves through versioned semantic schemas.

Conceptual chain:

```text
ProductDefinition
    ├── Schema v1
    ├── Schema v2
    └── Schema v3
```

A schema version is a historical semantic contract.

Once published, its semantic meaning is immutable.

Changing requirements creates a new version rather than silently mutating
published history.

Initial lifecycle:

```text
DRAFT → PUBLISHED → DEPRECATED → RETIRED
  └────────────────────────────→ RETIRED
```

At most one draft and one current published version should exist per definition
through domain coordination.

---

## 9. AttributeDefinition vs AttributeBinding

CSF separates reusable attribute identity from its use inside a product schema.

### AttributeDefinition

Answers:

> **What semantic attribute is this?**

Examples:

```text
straleon.attribute.screen_size
straleon.attribute.ram_capacity
straleon.attribute.tyre_width
straleon.attribute.tyre_aspect_ratio
straleon.attribute.thread_pitch
```

### Future AttributeBinding

Answers:

> **How does this attribute behave in this particular schema/version?**

Future binding concerns may include:

- required/optional;
- value scope;
- type;
- measurement dimension;
- constraints;
- filterability;
- comparability;
- variant-axis eligibility;
- Storefront visibility;
- requirement timing.

These concerns do **not** belong in the global AttributeDefinition identity.

---

## 10. Typed attributes are a later contract

CSF requires typed semantic attributes, but CSF-1 deliberately does not introduce
a partial type system.

Future typing must be designed together with existing numerical and unit
foundations.

Potential families include:

- text;
- boolean;
- integer/count;
- exact decimal;
- measurement;
- enum/reference;
- date/time;
- identifier/reference.

Binary float is not an acceptable numeric authority.

Measurement values must preserve dimension and exact unit semantics.

---

## 11. Value scopes

Not every semantic value belongs to the product master.

Future attribute/value scope must distinguish at least:

```text
PRODUCT
VARIANT
INVENTORY_UNIT
LOT_OR_BATCH
SUPPLIER_OFFER
```

Examples:

```text
phone model family         → PRODUCT
shirt size/color           → VARIANT
IMEI                       → INVENTORY_UNIT
expiry date                → LOT_OR_BATCH
supplier pack code         → SUPPLIER_OFFER
```

CSF must not force operational facts into Product merely because they are
product-related.

---

## 12. Capabilities are separate from descriptive attributes

An attribute describes.

A capability activates behavior.

Examples of future capabilities:

```text
inventory.serial_tracking
inventory.lot_tracking
inventory.expiry_tracking
inventory.fractional_container
inventory.rotation_policy
commerce.variable_quantity
commerce.quantity_tolerance
commerce.substitution_preferences
transformation.lineage
service.maintainable_subject
storefront.variant_selection
```

Capabilities must be activated only where semantically relevant.

A simple unit product must not expose FIFO/FEFO/LIFO controls merely because
Straleon supports them somewhere else.

This binds CSF to the existing progressive-configuration architecture.

---

## 13. Capability-driven progressive configuration

Future effective configuration may combine:

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

More specific valid configuration overrides less specific configuration.

`unset` means **inherit**, not missing configuration.

Semantic definition and organization policy remain distinct:

- ProductDefinition says what can make sense;
- organization/product/location policy says what is enabled/configured here.

---

## 14. EffectiveSemanticProfile

Future runtime resolution should produce an effective semantic profile rather
than making UI or domain code interpret raw schema fragments independently.

Conceptually:

```text
ProductDefinition
+ published ProductSchemaVersion
+ bindings
+ capability declarations
+ organization overlays
+ product overrides
      ↓
EffectiveSemanticProfile
```

The resolver must be deterministic and explainable.

It should be possible internally to answer:

```text
resolved capability
resolved schema rule
resolved from
override source
```

This is configuration explanation, not a public-network contract.

---

## 15. ContextualInteractionProfile

Semantic truth and UI interaction are not the same artifact.

A second resolver must eventually combine:

```text
EffectiveSemanticProfile
+ current operation
+ actor permissions
+ current product state
+ known/missing evidence
      ↓
ContextualInteractionProfile
```

Examples:

### Catalog creation

Ask for stable product-master facts known at creation.

### Purchase receipt

Ask for lot/expiry/serial evidence only when that evidence naturally becomes
known at receipt.

### Picking

Ask for the specific serial/container/lot only when selection is required.

### Storefront

Expose only consumer-relevant public-safe choices.

This is how Straleon avoids both a giant product form and hidden data-quality
loss.

---

## 16. Requirement gates and capture timing

A semantic field may be required for a later operation without being required
during initial product creation.

Future requirement gates may express ideas such as:

```text
required_before_purchase_receipt
required_before_sale
required_before_publication
required_before_serialized_fulfillment
required_before_transformation
```

The architecture must distinguish:

```text
required eventually
≠
required now
```

---

## 17. UNCLASSIFIED vs GENERIC_GOOD

Straleon must not confuse lack of classification with intentional genericity.

Future concepts should distinguish:

```text
UNCLASSIFIED
```

Meaning:

> semantic classification has not yet been established.

From:

```text
GENERIC_GOOD
```

Meaning:

> the product is intentionally represented with a minimal generic semantic
> contract.

This prevents "unknown" from becoming a silent permanent schema.

---

## 18. Reclassification

Changing ProductDefinition later must be explicit.

Future reclassification must consider:

- existing semantic values;
- variant structure;
- inventory-unit/lot evidence;
- Storefront publication;
- compatibility with the target schema;
- required migration or review;
- auditability.

A category edit is never semantic reclassification.

---

## 19. Variant boundary

Variant is not part of CSF-1.

The architecture nevertheless reserves the distinction:

```text
Product
    ↓
Variant axes / options
    ↓
Variant
```

Examples:

- size/color;
- storage capacity/color;
- tyre dimensions where modeled as variants;
- pack or presentation only when semantically a variant rather than a unit
  conversion/presentation.

Variant must not become a workaround for inventory units, lots or presentations.

---

## 20. Schema composition

Future schemas should support controlled composition/reuse rather than deep class
inheritance.

Conceptual example:

```text
base physical good
+ dimensional attributes
+ electrical attributes
+ serialized capability
+ smartphone-specific bindings
```

Composition must produce deterministic effective contracts and avoid ambiguous
multiple authorities.

No composition runtime is part of CSF-1.

---

## 21. Organization semantic overlays

Canonical definitions remain global.

Organizations may later need local semantic extensions or policy overlays.

Those overlays must not:

- mutate the global ProductDefinition;
- redefine a global AttributeDefinition key;
- break historical schema versions;
- leak private merchant metadata into Network;
- create incompatible public meanings under the same canonical key.

The exact overlay model is deferred.

---

## 22. Storefront and Network boundary

CSF is upstream semantic infrastructure.

Storefront and Straleon Network consume explicit projections/contracts.

They do not read internal schema tables as their public API.

Future public projection may expose a sanitized subset of semantic facts, for
example:

- consumer filters;
- comparison attributes;
- variant selectors;
- technical highlights;
- public-safe capability hints.

Publication remains explicit.

Global semantic identity does not imply public exposure.

---

## 23. AI and provenance

AI may later assist with:

- definition suggestion;
- attribute extraction;
- classification;
- normalization candidates;
- mapping supplier descriptions;
- image/document interpretation.

Binding rule:

> **AI proposes; domain contracts validate; a valid authority confirms.**

AI output must never silently become canonical product truth.

Future assisted facts should preserve provenance and confidence where useful.

---

## 24. Single-truth integration

CSF is governed by the Straleon Single-Truth Principle.

Examples:

- physical stock remains owned by confirmed InventoryMovement;
- compatibility remains owned by Knowledge/compatibility structures;
- product category remains taxonomy;
- ProductDefinition owns only semantic-kind identity;
- a schema version owns only its semantic contract;
- AttributeDefinition owns only reusable attribute identity;
- Storefront/Network projections remain downstream.

If an implementation cannot identify which fact CSF owns versus which existing
domain already owns it, the cut must remain in design/recon.

---

## 25. CSF delivery sequence

Canonical sequence:

```text
CSF-0  Architecture / ADR
       → this document

CSF-1  Semantic Registry Foundation
       → ProductDefinition
       → ProductSchemaVersion
       → AttributeDefinition

CSF-2  Attribute typing + measurement + AttributeBinding

CSF-3  Effective semantic resolution / composition foundation

CSF-4  Capability declaration + progressive policy binding

CSF-5  CatalogProduct assignment + controlled reclassification

CSF-6  Dynamic semantic values by valid scope

CSF-7  Contextual requirement / interaction resolution

CSF-8  Variant semantic boundary where justified

CSF-9  Storefront / public semantic projection contracts

CSF-10 AI-assisted semantic ingestion with provenance
```

Exact numbering after CSF-1 remains subject to focused RECON before
implementation. The dependency direction is binding even if later cuts are
renamed.

---

## 26. CSF-1 boundary

CSF-1 is intentionally small.

It creates only the global registries required to establish semantic identity and
schema history:

```text
ProductDefinition
ProductSchemaVersion
AttributeDefinition
```

CSF-1 does not connect them to CatalogProduct yet.

It does not create semantic values, capabilities, variants or UI.

The exact implementation contract is frozen in:

`docs/158_CSF_1_SEMANTIC_REGISTRY_FOUNDATION_CONTRACT.md`.

---

## 27. Consequences

### Positive

- one Catalog can support many industries;
- forms remain context-sensitive;
- semantic history is versioned;
- capabilities become explainable and progressive;
- Storefront/Network gain a stable future semantic source;
- Knowledge remains the universal identity/compatibility authority;
- product-specific complexity no longer requires Core-column explosion.

### Costs

- semantic resolution becomes an explicit architectural subsystem;
- schema/version lifecycle must be enforced rigorously;
- later dynamic values require carefully scoped storage;
- UI cannot directly mirror database tables;
- reclassification requires controlled semantics.

These costs are accepted because they keep complexity in Straleon rather than
forcing it onto every merchant and employee.

---

## 28. Final rule

> **CatalogProduct represents the merchant-facing product record.
> ProductDefinition represents its semantic kind.
> Knowledge represents universal identity/relations.
> Inventory represents physical facts.
> Capabilities determine relevant behavior.
> Context determines what the user should see now.**

No future CSF cut may collapse those authorities into one universal product
record.
