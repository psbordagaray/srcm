# CSF-4 — Capability Declaration + Progressive Policy Binding Contract

Estado: **V1 — candidato vinculante; requiere checkpoint + CI natural GREEN antes de autorizar implementación**

Programa: **P13.B — Catalog Semantic Foundation**

Predecesores vinculantes:

- `docs/157_ADR_CATALOG_SEMANTIC_FOUNDATION.md`
- `docs/158_CSF_1_SEMANTIC_REGISTRY_FOUNDATION_CONTRACT.md`
- `docs/159_CSF_2_ATTRIBUTE_TYPING_MEASUREMENT_BINDING_CONTRACT.md`
- `docs/160_CSF_3_EFFECTIVE_SEMANTIC_RESOLUTION_COMPOSITION_CONTRACT.md`

Baseline de diseño:

- rama: `feature/core-entity`
- HEAD previo: `76164bf3da6eee32ad80e3e3c080edc8318e28ed`
- tree previo: `9c55bd5965bd8d3ccb477b4c79decf6bb7c06568`
- CI168 natural: `34298759853`
- job `quality-gates`: `102300987226`
- CSF-4 RECON V1: GREEN, estrictamente read-only

---

## 1. Propósito

CSF-4 debe responder dos preguntas distintas sin mezclarlas:

> **¿Qué comportamientos son semánticamente aplicables a este tipo de producto?**

y:

> **De esos comportamientos aplicables, cuáles están activos/configurados en este contexto operativo?**

La primera pregunta pertenece al contrato semántico versionado.

La segunda pertenece a política operativa explícitamente overrideable.

CSF-4 no debe convertir atributos descriptivos en behavior flags, ni convertir
políticas operativas en verdad semántica, ni crear un motor genérico de
overrides.

La regla maestra es:

```text
semantic capability applicability
        ≠
operational capability activation
        ≠
authorization permission
        ≠
domain-specific policy value
```

---

## 2. Decisión central

CSF-4 V1 introduce tres conceptos separados:

```text
SemanticCapabilityDefinition
        ↓
ProductSchemaCapabilityDeclaration
        ↓
EffectiveSemanticCapability
```

y, por otra vía:

```text
ProductSchemaCapabilityDeclaration
        +
current Organization policy
        ↓
EffectiveCapabilityPolicy
```

El perfil semántico conserva la declaración estructural.

El resolver de política conserva la activación operativa actual.

No se mezclan ambos hechos en una sola columna, flag o tabla.

---

## 3. Negative space vinculante

CSF-4 V1 NO autoriza:

- `CatalogProduct → ProductDefinition` assignment;
- reclassification de CatalogProduct;
- dynamic semantic attribute value storage;
- Variant runtime;
- ContextualInteractionProfile;
- requirement gates;
- Storefront/Network semantic runtime;
- measurement conversion;
- generic policy-value JSON blobs;
- generic last-write-wins;
- generic most-specific-wins;
- ProductCategory como fuente de semantic precedence;
- Inventory FIFO/FEFO/LIFO accounting valuation;
- cambios silenciosos de defaults operativos existentes;
- nuevas superficies UI de operador final;
- P13.C.

CSF-5 continúa siendo la frontera de assignment/reclassification.

CSF-6 continúa siendo la frontera de valores semánticos tipados.

CSF-7 continúa siendo la frontera de resolución contextual de interacción.

CSF-8 continúa siendo la frontera Variant.

---

## 4. Capabilities descriptivas vs capabilities de autorización

Straleon ya posee `App\Domain\Authorization\Capability`.

Esa clase representa una **capability de autorización**:

```text
numerics.discrepancy.override
...
```

CSF-4 NO la reutiliza como identidad semántica de producto.

Una capability de autorización responde:

> ¿Puede este actor realizar esta acción?

Una semantic capability responde:

> ¿Es este comportamiento relevante/aplicable al tipo semántico de producto?

Por lo tanto:

```text
Authorization\Capability
        ≠
SemanticCapabilityDefinition
```

Pueden usar convenciones de naming similares, pero no comparten autoridad,
lifecycle ni persistencia.

---

## 5. SemanticCapabilityDefinition

`SemanticCapabilityDefinition` es la identidad global y estable de una
capability semántica.

Ejemplos conceptuales:

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

Registrar una identidad NO activa runtime de ese dominio.

En particular, la existencia de `storefront.variant_selection` no abre Variant.

### 5.1 Campos conceptuales

```text
SemanticCapabilityDefinition
- id
- key
- name
- description
- status
- created_at
- updated_at
```

### 5.2 Semantic key

La `key`:

- usa la autoridad `SemanticKey`;
- es globalmente única;
- es inmutable;
- es namespaced;
- no se recicla.

### 5.3 Lifecycle

Estados:

```text
ACTIVE
DEPRECATED
RETIRED
```

Transiciones:

```text
ACTIVE -> DEPRECATED -> RETIRED
```

No existe retorno a un estado anterior.

No existe physical delete.

`name` y `description` son metadata mutable y NO forman parte de la verdad
histórica estructural.

---

## 6. La capability declaration pertenece al exact ProductSchemaVersion

La capability applicability NO vive directamente en `ProductDefinition`.

Vive en el exact `ProductSchemaVersion`.

Razón:

```text
ProductDefinition
    identidad semántica estable

ProductSchemaVersion
    contrato semántico histórico exacto
```

Una capability puede aparecer o dejar de ser aplicable entre versiones.

Si la relación viviera directamente en ProductDefinition, un cambio actual
podría reescribir silenciosamente la semántica de versiones publicadas
anteriores.

Por eso:

```text
ProductDefinition
        ↓
ProductSchemaVersion V1
        └── capabilities de V1

ProductSchemaVersion V2
        └── capabilities de V2
```

y nunca:

```text
ProductDefinition
        └── mutable capability bag compartida por toda la historia
```

---

## 7. ProductSchemaCapabilityDeclaration

`ProductSchemaCapabilityDeclaration` declara que una semantic capability es
aplicable dentro de un exact ProductSchemaVersion.

Campos conceptuales:

```text
ProductSchemaCapabilityDeclaration
- id
- product_schema_version_id
- semantic_capability_definition_id
- activation_mode
- default_enabled
- created_at
- updated_at
```

Unique identity:

```text
(product_schema_version_id, semantic_capability_definition_id)
```

### 7.1 Qué significa presence

Si existe declaration:

> la capability es semánticamente aplicable al schema.

Si no existe:

> la capability NO forma parte del contrato semántico de ese schema.

No existe fallback por key parecida.

No existe inferencia por categoría.

No existe inferencia por atributos presentes.

No existe inferencia por nombre de producto.

---

## 8. Activation mode: no toda capability es overrideable

CSF-3 estableció que un hecho sólo puede entrar en precedence si el contrato
declara explícitamente que es overrideable.

Por lo tanto, cada declaration debe declarar uno de dos modos:

```text
FIXED_ENABLED
CONFIGURABLE
```

### 8.1 FIXED_ENABLED

La capability está activa siempre que ese schema sea la autoridad semántica.

```text
activation_mode = FIXED_ENABLED
default_enabled = true
```

No admite organization override.

Un binding operacional externo NO puede apagarla para ese schema.

### 8.2 CONFIGURABLE

La capability es aplicable, pero su activación puede configurarse mediante la
cadena de política autorizada.

```text
activation_mode = CONFIGURABLE
default_enabled = true|false
```

En V1, el único overlay runtime habilitado es Organization.

### 8.3 Invariante

No existe un modo implícito.

Un `activation_mode` desconocido falla cerrado.

---

## 9. El default pertenece al exact schema declaration

CSF-4 V1 NO crea un mutable global-system-default por capability.

La razón es que la misma capability puede tener defaults seguros distintos
según el tipo semántico.

Ejemplo conceptual:

```text
YOGURT schema
inventory.expiry_tracking
default_enabled = true

GENERIC_NON_PERISHABLE schema
inventory.expiry_tracking
(no declaration)
```

El default operativo base queda versionado junto al schema que declara la
capability.

Esto evita que cambiar un default global reescriba silenciosamente el
comportamiento base de todos los tipos de producto.

---

## 10. Draft mutation y published immutability

Las capability declarations siguen la misma ley de historia que
`AttributeBinding`.

### Draft

En Draft:

- puede declararse una capability;
- puede quitarse;
- puede cambiar activation mode;
- puede cambiar default;
- sólo puede referenciar SemanticCapabilityDefinition ACTIVE.

### Published / Deprecated / Retired publicados

Una vez publicado el schema:

- no puede agregarse declaration;
- no puede eliminarse declaration;
- no puede cambiarse activation mode;
- no puede cambiarse default;
- no puede cambiarse capability identity.

El historial publicado debe permanecer resolvible aunque posteriormente el
registry global pase ACTIVE → DEPRECATED → RETIRED.

---

## 11. Atomic draft inheritance

`ProductSchemaVersionManager::createDraft()` ya clona atómicamente los
AttributeBindings del current published schema.

CSF-4 extiende esa misma ley:

> abrir un nuevo Draft debe clonar en la misma transacción todas las capability
> declarations del único current Published schema.

No existe dynamic inheritance V3 → V2 → V1 en runtime.

El nuevo Draft se vuelve autocontenido.

Conceptualmente:

```text
Published V2
  attributes
  capabilities
       ↓ atomic clone at createDraft()
Draft V3
  own attributes
  own capabilities
```

Resolver runtime:

```text
exact selected schema
        ↓
own attributes
own capabilities
```

y nunca recorre versiones anteriores.

---

## 12. Publication validation

Publicar un Draft requiere:

1. ProductDefinition ACTIVE;
2. todas las AttributeDefinitions requeridas ACTIVE según CSF-2;
3. Measurement dependencies válidas según CSF-2;
4. cada SemanticCapabilityDefinition declarada ACTIVE;
5. declaration identity única;
6. activation mode válido;
7. `FIXED_ENABLED` coherente con `default_enabled=true`;
8. `CONFIGURABLE` con default boolean explícito.

La publicación falla cerrada ante cualquier dependencia inválida.

---

## 13. EffectiveSemanticProfile se extiende sólo con capability structure

CSF-4 puede extender `EffectiveSemanticProfile` con:

```text
capabilities: list<EffectiveSemanticCapability>
```

`EffectiveSemanticCapability` contiene sólo hechos de declaration:

```text
- declaration_id
- semantic_capability_definition_id
- semantic_capability_key
- activation_mode
- provenance
```

No contiene:

- current organization override;
- current effective enabled/disabled state;
- actor permission;
- Inventory FIFO/FEFO/LIFO value;
- UI visibility decision;
- CatalogProduct id;
- location id.

La separación protege la ley de CSF-3:

> EffectiveSemanticProfile sigue siendo verdad semántica derivada, no contexto
> operativo mutable.

---

## 14. Deterministic capability ordering

Las capabilities del perfil se ordenan por:

```text
SemanticCapabilityDefinition.key ASC
then
SemanticCapabilityDefinition.id ASC
```

No se usa:

- insertion order;
- declaration id accidental;
- updated_at;
- database row order.

Duplicate capability id o duplicate capability key dentro del exact schema
falla cerrado.

---

## 15. Capability provenance

Cada `EffectiveSemanticCapability` retiene:

```text
product_schema_version_id
product_schema_capability_declaration_id
semantic_capability_definition_id
semantic_capability_key
resolution_rule = declared_by_exact_schema_capability_declaration
```

No se usa metadata mutable como historical authority.

---

## 16. Progressive policy binding V1: scope deliberadamente estrecho

CSF-4 V1 implementa únicamente la política de **activation**.

No implementa un motor genérico para cualquier policy field.

La cadena V1 es:

```text
exact schema declaration default
        ↓
organization override, sólo si CONFIGURABLE
```

Eso es suficiente para fundar:

- defaults seguros;
- `unset = inherit`;
- provenance;
- explicit overrideability;
- configuración progresiva.

Y evita abrir anticipadamente CSF-5.

---

## 17. OrganizationCapabilityPolicyBinding

Conceptualmente:

```text
OrganizationCapabilityPolicyBinding
- id
- organization_id
- semantic_capability_definition_id
- enabled
- created_at
- updated_at
```

Unique:

```text
(organization_id, semantic_capability_definition_id)
```

### 17.1 Absence = inherit

En V1:

```text
no organization binding
        =
UNSET
        =
inherit exact schema default
```

Esto NO significa missing configuration.

El contrato define explícitamente la semántica de ausencia para este único
field overrideable.

### 17.2 Presence = explicit override

Si existe binding:

```text
enabled = true|false
```

sólo participa cuando el selected schema declara esa capability como
`CONFIGURABLE`.

### 17.3 FIXED_ENABLED ignores organization activation policy

Si la declaration es `FIXED_ENABLED`, el effective state es enabled.

Un organization binding de la misma capability puede existir porque esa
capability puede ser configurable en otro schema.

Para el schema FIXED_ENABLED:

```text
organization binding
        ↓
not applicable to this declaration
```

No gana por especificidad.

---

## 18. Product / product-location scopes quedan RESERVED para CSF-5+

CSF-4 V1 NO crea product override ni product-location override.

La razón es arquitectónica:

CSF-5 todavía no ha establecido:

```text
CatalogProduct
        ↓ controlled assignment
ProductDefinition
```

Sin ese vínculo no existe autoridad suficiente para afirmar que un
CatalogProduct override pertenece al semantic profile que se está resolviendo.

Por lo tanto, el futuro layering puede extenderse a:

```text
schema default
→ organization
→ product
→ product-location
```

pero `product` y `product-location` quedan RESERVED hasta que CSF-5 provea la
asignación controlada y la identidad necesaria para validarlos.

---

## 19. ProductCategory no entra en CSF-4 V1 precedence

ADR 157 mostró tempranamente una posible cadena con `category/family`.

CSF-3 refinó esa idea y estableció:

> ProductCategory MUST NOT participate in semantic inheritance or precedence.

CSF-4 V1 conserva la regla más reciente y específica.

No existe:

```text
ProductCategory → capability declaration
ProductCategory → semantic capability override
ProductCategory → implicit activation
```

Mover un producto de categoría no puede alterar su semantic kind ni su
capability set.

Si en el futuro se necesita un concepto de semantic family o un operational
policy grouping, deberá tener identidad y contrato propios; no se inferirá de
ProductCategory.

---

## 20. EffectiveCapabilityPolicy

El resolver operacional produce por capability declarada:

```text
EffectiveCapabilityPolicy
- semantic_capability_definition_id
- semantic_capability_key
- activation_mode
- enabled
- resolved_from
- schema_default_enabled
- organization_policy_binding_id|null
- provenance
```

`resolved_from` V1 puede ser:

```text
schema_fixed
schema_default
organization_override
```

---

## 21. Resolver law

`EffectiveCapabilityPolicyResolver` opera sobre un
`EffectiveSemanticProfile` ya resuelto.

### 21.1 Current operational resolution

Input:

```text
EffectiveSemanticProfile in CURRENT_PUBLISHED mode
+ Organization
```

Algorithm por capability:

```text
if activation_mode == FIXED_ENABLED
    enabled = true
    resolved_from = schema_fixed

else if organization binding exists
    enabled = binding.enabled
    resolved_from = organization_override

else
    enabled = declaration.default_enabled
    resolved_from = schema_default
```

La Organization debe ser una identidad válida y explícita.

No se infiere desde global current organization ambient state dentro del DTO.

### 21.2 Schema-default resolution

Puede existir una operación read-only que resuelva sólo el schema-level state:

```text
EffectiveSemanticProfile
        ↓
schema fixed/default activation
```

Eso sirve tanto para CURRENT_PUBLISHED como EXACT_HISTORICAL.

No aplica current Organization.

---

## 22. Historical law

EXACT_HISTORICAL conserva:

- exact capability declaration;
- exact activation mode;
- exact schema default.

Pero CSF-4 V1 NO afirma conocer el historical organization override de una
fecha pasada.

Los organization bindings de V1 son current operational configuration.

Por lo tanto:

```text
historical semantic capability declaration
        = supported

historical organization activation at arbitrary past time
        = NOT reconstructed by CSF-4 V1
```

Si el producto requiere historia temporal de políticas operativas, eso debe
introducirse mediante un contrato temporal explícito, no aplicando la
configuración actual hacia atrás.

---

## 23. Dormant organization policy is allowed but not semantic authority

Una Organization puede poseer un binding para una capability que no aparece en
un schema determinado.

Ese binding:

- no inventa capability applicability;
- no agrega la capability al profile;
- no habilita behavior en un schema que no la declaró;
- puede ser relevante para otro ProductSchemaVersion.

La regla es:

> policy can configure declared applicability; policy cannot manufacture
> applicability.

---

## 24. Domain-specific policy values remain domain-owned

CSF-4 V1 NO guarda en generic policy storage:

```text
FIFO
FEFO
LIFO
MANUAL
SPECIFIC
```

Esos valores continúan perteneciendo al dominio Inventory.

La semantic capability:

```text
inventory.rotation_policy
```

puede decir que el comportamiento de rotación es aplicable/activo.

No decide por sí sola qué algoritmo concreto se usa.

La arquitectura vigente permanece:

- simple unit → no rotation controls;
- serialized/IMEI → specific-unit selection;
- lot + expiry → puede default FEFO;
- lot sin expiry → puede default FIFO, con LIFO disponible;
- fractional/container → usa sus políticas específicas;
- LIFO → política logística, nunca accounting valuation.

Cuando Inventory conecte su policy resolver con CSF, deberá conservar su propia
autoridad y provenance.

---

## 25. Future capability-specific policy fields

CSF-4 V1 sólo implementa activation.

Cualquier field adicional, por ejemplo:

```text
inventory.rotation.strategy
inventory.fractional_container.consumption_strategy
commerce.quantity_tolerance.default_mode
```

requiere un contrato explícito que defina:

1. policy key;
2. owning domain;
3. exact value type;
4. exact allowed values/range;
5. exact allowed source layers;
6. exact precedence;
7. default;
8. whether absence means inherit;
9. provenance shape;
10. ambiguity/failure rules;
11. lifecycle/historical semantics.

No se autoriza:

```text
policy_key + arbitrary JSON
```

como escape hatch universal.

---

## 26. No generic override engine

CSF-4 no introduce una función:

```text
resolveAnythingByMostSpecificRow()
```

Tampoco una tabla universal:

```text
scope_type
scope_id
key
json_value
priority
```

El field `activation` puede usar precedence porque este contrato lo autoriza
explícitamente.

Otros fields deberán ser autorizados por su propio contrato.

---

## 27. Safe-default law

Todo `CONFIGURABLE` declaration posee un default explícito.

Por eso la ausencia de organization override nunca deja una capability en
estado ambiguo.

```text
CONFIGURABLE
+ no org binding
        ↓
schema default
```

No existe `null` effective activation.

Si la data persistida impide obtener un boolean efectivo, el resolver falla
cerrado.

---

## 28. Progressive UX law

La potencia de CSF-4 queda detrás de escena.

### 28.1 Operador ordinario

No ve:

- policy layers;
- provenance ids;
- schema versioning;
- capability registry lifecycle;
- irrelevant controls.

### 28.2 Configuración autorizada

Muestra únicamente capabilities declaradas como relevantes para el contexto.

Para CONFIGURABLE:

```text
Usar configuración predeterminada
Activado
Desactivado
```

El texto cotidiano puede variar, pero el modelo interno sigue siendo:

```text
inherit
enabled
disabled
```

En V1, `inherit` se persiste como ausencia del organization binding.

### 28.3 Irrelevant capability

Si el schema no la declara:

> no se muestra.

No se muestra disabled.

No se muestra advanced.

Simplemente no forma parte de la tarea del usuario.

---

## 29. Examples

### 29.1 Simple unit product

Schema:

```text
(no inventory.rotation_policy declaration)
```

Resultado:

```text
rotation UI = hidden
FIFO/FEFO/LIFO = not offered
```

### 29.2 Serialized smartphone

Schema conceptual:

```text
inventory.serial_tracking
    FIXED_ENABLED

(no inventory.rotation_policy)
```

Resultado:

```text
serial tracking = relevant
rotation controls = hidden
specific-unit selection remains Inventory behavior
```

### 29.3 Perishable lot product

Schema conceptual:

```text
inventory.lot_tracking
inventory.expiry_tracking
inventory.rotation_policy CONFIGURABLE default_enabled=true
```

Organization puede desactivar únicamente si el declaration lo permite.

Inventory continúa siendo autoridad de FEFO/FIFO/LIFO.

### 29.4 Fractional product

Schema conceptual:

```text
inventory.fractional_container
```

CSF declara aplicabilidad.

El dominio Inventory conserva:

- opened-container logic;
- fractional consumption strategy;
- FIFO/FEFO/manual semantics;
- confirmed movement truth.

---

## 30. Proposed persistent model V1

### 30.1 `catalog_semantic_capability_definitions`

Conceptual columns:

```text
id
key
name
description nullable
status
created_at
updated_at
```

Constraints:

```text
UNIQUE(key)
```

### 30.2 `catalog_product_schema_capability_declarations`

Conceptual columns:

```text
id
product_schema_version_id
semantic_capability_definition_id
activation_mode
default_enabled
created_at
updated_at
```

Constraints:

```text
UNIQUE(product_schema_version_id, semantic_capability_definition_id)
INDEX(semantic_capability_definition_id)
```

Foreign keys use restrictive semantics appropriate to immutable semantic
history.

### 30.3 `catalog_organization_capability_policy_bindings`

Conceptual columns:

```text
id
organization_id
semantic_capability_definition_id
enabled
created_at
updated_at
```

Constraints:

```text
UNIQUE(organization_id, semantic_capability_definition_id)
INDEX(semantic_capability_definition_id, organization_id)
```

No generic scope polymorphism in V1.

---

## 31. No ProductCategory FK in policy tables

CSF-4 V1 persistence MUST NOT contain:

```text
product_category_id
category_scope
category_priority
```

Esto evita que taxonomy se transforme en semantic authority accidental.

---

## 32. No CatalogProduct FK in CSF-4 policy tables

Hasta CSF-5 no se agrega:

```text
catalog_product_id
inventory_location_id
product_location_scope
```

a la política de activation CSF.

Esos scopes requieren primero controlled semantic assignment.

---

## 33. Manager responsibilities

### 33.1 SemanticCapabilityDefinitionManager

Debe:

- validar SemanticKey;
- crear capability ACTIVE;
- permitir metadata update mientras no esté RETIRED;
- deprecate;
- retire;
- impedir key mutation;
- impedir physical delete.

### 33.2 ProductSchemaCapabilityManager

Debe:

- mutar sólo Draft;
- bind capability;
- reconfigure activation mode/default;
- remove declaration en Draft;
- rechazar capability registry no ACTIVE al crear/reconfigurar;
- impedir duplicate declaration.

### 33.3 OrganizationCapabilityPolicyManager

Debe:

- validar Organization;
- set explicit enabled/disabled binding;
- reset to inherit eliminando el binding actual mediante flujo auditado;
- no permitir payloads fuera del boolean activation field.

La ausencia posterior al reset equivale a inherit.

---

## 34. Model guards

Los modelos deben reforzar:

- capability key immutable;
- retired registry immutable;
- no physical delete de semantic registry;
- schema capability declaration draft-only;
- declaration identity immutable fuera de Draft;
- organization/capability identity de un binding no se cambia silenciosamente.

Cambio de Organization o capability en un binding existente se representa como
otra operación, no mutation de identidad.

---

## 35. Resolver fail-closed conditions

Falla cerrado ante:

- missing SemanticCapabilityDefinition;
- invalid semantic capability key;
- unknown capability registry lifecycle value;
- duplicate capability id;
- duplicate capability key;
- invalid activation mode;
- FIXED_ENABLED con incoherent default;
- CONFIGURABLE sin boolean default;
- declaration que pertenece a otro schema;
- current policy resolution con profile no CURRENT_PUBLISHED;
- organization binding duplicado;
- organization binding con invalid boolean/storage shape;
- unknown future policy source;
- attempt de product/category/location precedence en V1 resolver;
- generic most-specific fallback;
- generic last-write-wins fallback.

---

## 36. Determinism and concurrency

Read resolution debe:

- usar exact schema identity;
- ordenar por stable semantic key + id;
- no seleccionar por latest timestamp;
- no seleccionar por highest id;
- no depender de DB row order.

Mutations de schema draft y publication deben conservar transacciones y locks ya
establecidos por ProductSchemaVersionManager.

Organization policy writes deben proteger duplicate/current binding mediante
unique constraint y transacción.

---

## 37. Provenance shape

Conceptualmente:

```text
EffectiveSemanticCapabilityProvenance
- product_schema_version_id
- product_schema_capability_declaration_id
- semantic_capability_definition_id
- semantic_capability_key
- rule = declared_by_exact_schema_capability_declaration
```

Y:

```text
EffectiveCapabilityPolicyProvenance
- product_schema_version_id
- product_schema_capability_declaration_id
- semantic_capability_definition_id
- semantic_capability_key
- activation_mode
- schema_default_enabled
- organization_id|null
- organization_policy_binding_id|null
- resolved_from
```

---

## 38. Storefront / Network boundary

Storefront y Network NO reciben private policy internals como contrato público.

Pueden consumir outcomes derivados cuando su frontera se abra.

No deben recibir automáticamente:

- organization policy binding ids;
- internal precedence layers;
- Inventory selection policy details;
- private configuration provenance.

---

## 39. Knowledge boundary

Knowledge / TechnicalModel no declara capability activation.

Puede aportar identidad técnica, compatibilidad y conocimiento verificable.

No reemplaza:

```text
ProductSchemaCapabilityDeclaration
```

ni:

```text
OrganizationCapabilityPolicyBinding
```

---

## 40. Inventory boundary

Inventory sigue siendo autoridad de:

- physical stock;
- movements;
- lots/batches;
- expiry facts;
- serial/IMEI instances;
- containers;
- selection/consumption execution;
- FIFO/FEFO/LIFO/manual/specific operational behavior.

CSF dice qué capability es relevante.

Inventory ejecuta la política operacional que le pertenece.

---

## 41. Requirement-gate boundary

`FIXED_ENABLED` NO equivale a un requirement gate temporal.

Ejemplo:

```text
inventory.serial_tracking FIXED_ENABLED
```

dice que la capability está activa para ese semantic schema.

No decide en qué operación exacta un serial debe haberse capturado.

Eso continúa reservado a futuros requirement gates / ContextualInteractionProfile.

---

## 42. No implicit UI contract

CSF-4 expone facts y provenance.

No decide:

- formulario exacto;
- orden visual;
- componente Blade;
- wizard;
- role-specific visibility;
- operation-specific prompts.

Esas decisiones pertenecen a la capa contextual posterior.

La única regla UX vinculante de CSF-4 es:

> una capability no aplicable no debe contaminar la experiencia con controles
> irrelevantes.

---

## 43. Proposed V1 implementation surface

Una implementación posterior a este contrato puede requerir, como máximo
conceptual, piezas equivalentes a:

```text
Enums
- SemanticCapabilityStatus
- SemanticCapabilityActivationMode

Models
- SemanticCapabilityDefinition
- ProductSchemaCapabilityDeclaration
- OrganizationCapabilityPolicyBinding

Domain
- SemanticCapabilityDefinitionManager
- ProductSchemaCapabilityManager
- OrganizationCapabilityPolicyManager
- EffectiveSemanticCapability
- EffectiveSemanticCapabilityProvenance
- EffectiveCapabilityPolicy
- EffectiveCapabilityPolicyProvenance
- EffectiveCapabilityPolicyResolver

Existing controlled modifications
- ProductSchemaVersion
- ProductSchemaVersionManager
- EffectiveSemanticProfile
- EffectiveSemanticProfileResolver

Tests
- semantic capability registry
- schema capability lifecycle
- draft cloning
- publication validation
- effective semantic capability resolution
- organization activation policy resolution
```

El exact scope debe reconciliarse contra source antes de escribir implementación.

---

## 44. Implementation must not seed behavior silently

CSF-4 foundation NO debe cambiar el comportamiento actual de Inventory,
Commerce, Service o Storefront por el mero hecho de crear tablas/classes.

No se habilitan capabilities existentes de forma silenciosa.

No se cambian defaults de productos actuales.

Domain wiring requiere un corte explícito y validado.

---

## 45. Required focal scenarios for eventual implementation

La futura implementación debe cubrir al menos:

1. capability registry key/lifecycle;
2. duplicate capability key rejection;
3. schema declaration only in Draft;
4. current Published declarations immutable;
5. createDraft atomic capability clone;
6. publish requires ACTIVE capability dependencies;
7. exact historical capability declaration survives registry deprecation/retirement;
8. EffectiveSemanticProfile capability ordering deterministic;
9. duplicate capability identity fails closed;
10. FIXED_ENABLED cannot be overridden;
11. CONFIGURABLE uses schema default without organization binding;
12. organization binding overrides configurable declaration;
13. reset/unset returns to schema default;
14. org binding cannot manufacture undeclared applicability;
15. exact historical semantic resolution does not apply current org policy;
16. ProductCategory has no semantic capability precedence;
17. no CatalogProduct/product-location scope in V1;
18. no Authorization\Capability reuse;
19. read resolvers perform no DB writes;
20. existing Inventory behavior remains unchanged until explicit wiring.

---

## 46. Multirubro validation

La arquitectura debe sostener simultáneamente:

```text
Smartphone
- serial tracking relevant
- rotation irrelevant

Yogurt
- lot + expiry relevant
- rotation relevant

Bolt / fastener
- simple unit behavior
- no expiry/serial UI by default

Paint
- lot may be relevant
- fractional behavior may be relevant depending schema

Apparel
- Variant future
- no lot/expiry UI by default

Lubricant
- fractional/container relevant
- inventory domain owns consumption policy
```

No debe aparecer un universal product form con todos los controles.

---

## 47. Canonical resolution picture

```text
SemanticCapabilityDefinition
        ↓
exact ProductSchemaVersion
        ↓
ProductSchemaCapabilityDeclaration
        ↓
EffectiveSemanticCapability
        │
        ├── FIXED_ENABLED
        │       ↓
        │   enabled
        │
        └── CONFIGURABLE
                ↓
        schema default
                ↓
        organization override
                ↓
        EffectiveCapabilityPolicy
```

Future, only after explicit later contracts:

```text
organization
→ product
→ product-location
```

ProductCategory is not inserted into the semantic chain.

---

## 48. Binding invariants

CSF-4 V1 binds these invariants:

1. semantic capability identity is separate from authorization capability;
2. capability applicability belongs to exact ProductSchemaVersion;
3. published capability declarations are historical immutable facts;
4. runtime never dynamically inherits declarations across schema versions;
5. new Draft clones current Published declarations atomically;
6. not every capability is overrideable;
7. overrideability is explicit through activation mode;
8. FIXED_ENABLED cannot be disabled by policy;
9. CONFIGURABLE requires an explicit safe schema default;
10. organization is the only runtime override layer in V1;
11. absence of organization binding means inherit;
12. ProductCategory is not semantic precedence;
13. CatalogProduct/product-location scopes are reserved until CSF-5+;
14. policy cannot manufacture undeclared applicability;
15. policy resolution is deterministic and provenance-bearing;
16. current org policy is not projected backward as historical truth;
17. domain-specific policy values remain domain-owned;
18. CSF-4 creates no generic arbitrary policy JSON engine;
19. irrelevant capabilities remain invisible to ordinary operators;
20. no existing domain behavior changes silently.

---

## 49. Authorization gate

Este documento, mientras sea sólo un Draft local o un archivo no publicado,
NO autoriza implementación.

La implementación CSF-4 V1 queda autorizada únicamente cuando:

1. este contrato se escriba con scope exacto;
2. pase whitespace/integrity checks;
3. se publique en checkpoint docs-only;
4. la CI natural del exact published SHA termine GREEN;
5. el worktree vuelva a estado limpio;
6. P13.C permanezca cerrado.

Hasta entonces:

```text
P13_B_CSF4_IMPLEMENTATION_AUTHORIZED=NO
```

---

## 50. Next boundary after contract publication

Si el contrato queda publicado y CI-verificado, el siguiente corte será:

```text
P13_B_CSF4_CAPABILITY_DECLARATION_PROGRESSIVE_POLICY_BINDING_IMPLEMENTATION_V1
```

Ese corte deberá reconciliar primero el exact source surface antes de escribir
código.

No se abre CSF-5 dentro de CSF-4.
