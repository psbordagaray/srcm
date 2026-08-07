# ADR 25 — Identidad comercial y rol Cliente

Estado: aceptada.

Checkpoint de decisión: `2649bae5bc73fdd17e4c0725e211ae06e5647663`.

## Contexto

SRCM ya utiliza `BusinessParty` como identidad privada por organización en Proveedores, Reparaciones y Ventas. Existe además un scaffold histórico `Person`, sin atributos operativos ni relaciones de negocio. Crear una segunda identidad de cliente sobre `Person` produciría duplicación y ambigüedad cuando una misma persona u organización actúa en varios roles.

## Decisión

`BusinessParty` es la única identidad comercial privada. `Customer` y `Supplier` son roles independientes 1:1 sobre esa identidad.

Una `BusinessParty` puede tener simultáneamente rol `Customer` y `Supplier`. El rol guarda únicamente atributos propios del vínculo comercial, inicialmente `notes` y `active`. Nombre, tipo, documento, correo, teléfono y sitio web siguen perteneciendo a `BusinessParty`.

La tabla `customers` incluye `organization_id` y una FK compuesta `(organization_id, business_party_id)` contra `business_parties`, además de unicidad de `business_party_id`. Esto impide cruces de tenant incluso ante bypass del modelo.

La creación adopta una identidad existente sólo cuando documento fiscal o correo permiten una coincidencia inequívoca y el tipo/nombre son consistentes. Conflictos o coincidencias probables por nombre fallan cerrados y requieren revisión.

`Person` no se elimina en este bloque; queda sin uso operativo hasta una limpieza posterior demostrablemente segura.

## Permisos

- `view-customers`: admin, operator, viewer.
- `manage-customers`: admin y operator.
- No existe `destroy`.

## Expediente

El expediente de cliente muestra identidad/contacto, rol proveedor cuando exista, ventas vinculadas por `customer_business_party_id`, reparaciones como cliente y órdenes donde la identidad figura como propietaria.

## Fuera de alcance

CRM, campañas, fidelización, crédito/cuenta corriente, cobranzas, listas de precios por cliente, fiscalidad, importación masiva y eliminación del scaffold `Person`.
