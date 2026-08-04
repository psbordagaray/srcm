# ADR 18 — Reclamos de garantía y reingreso correctivo

**Estado:** Aceptada
**Fecha:** 2026-08-03
**Ámbito:** Reparaciones Core 9

## Contexto

Reparaciones Core 5 registra una entrega única e inmutable por orden y crea
garantías atribuibles a resultados técnicos concretos. Una orden entregada es
terminal y no puede volver a diagnóstico o trabajo sin reescribir hechos ya
confirmados.

Cuando el cliente regresa porque una falla reaparece, SRCM debe conservar:

- la orden, la entrega y la garantía originales;
- el nuevo ingreso físico y la custodia;
- la decisión de cobertura;
- el eventual trabajo correctivo;
- la nueva calidad, entrega y garantía;
- la devolución física cuando el reclamo sea rechazado.

## Decisión

Un reclamo de garantía será un expediente propio
`ServiceWarrantyClaim`, vinculado simultáneamente con:

- la garantía reclamada;
- la entrega original;
- la orden original;
- una nueva orden correctiva;
- el mismo activo físico.

La orden original nunca se reabre. El registro del reclamo crea atómicamente
una nueva orden en estado `received`, reutilizando el `ServiceAsset` original y
generando una nueva fotografía de ingreso y un nuevo evento de custodia.

Sólo puede existir un reclamo abierto por garantía. Los reclamos cerrados
permanecen como historia y no bloquean reclamos posteriores.

## Resolución

Cada reclamo admite una única resolución inmutable:

- `accepted`;
- `partially_accepted`;
- `rejected`.

Resolver requiere rol Administrador. Un reclamo presentado con la garantía
vencida puede registrarse, pero su aceptación exige una excepción
administrativa con motivo obligatorio.

Las aceptaciones completa y parcial llevan la orden correctiva a
`in_progress`. El rechazo la lleva a `ready_for_return`.

## Autorización de trabajo y repuestos

El trabajo comercial existente conserva su contrato.

Un `ServiceWorkItem` tendrá exactamente una fuente de autorización:

- `service_quote_option_id`; o
- `service_warranty_claim_resolution_id`.

Un `ServicePartRequirement` tendrá exactamente una fuente de autorización:

- `service_quote_line_id`; o
- `service_warranty_claim_resolution_id`.

La exclusión mutua se protege en dominio y base de datos para SQLite y
MySQL/MariaDB. Los métodos comerciales actuales no cambian; se agregan entradas
específicas `planWarranty()`.

## Cierre

Una aceptación se cierra únicamente cuando la orden correctiva supera calidad
y registra una nueva entrega. Esa entrega puede producir una nueva garantía si
el resultado correctivo otorgó plazo.

Un rechazo se cierra mediante `ServiceWarrantyClaimReturn`, con evento de
custodia `warranty_returned`. No se registra una falsa entrega comercial ni se
reutiliza el flujo de cancelación posterior a aprobación.

## Estados

El reclamo utiliza:

1. `pending_review`
2. `accepted` o `partially_accepted` o `rejected`
3. `in_corrective_work` o `ready_for_return`
4. `closed`

Cada transición posee historia inmutable e idempotente.

## Integridad

Se exige:

- aislamiento por organización;
- referencias compuestas por organización;
- idempotencia con fingerprint;
- inmutabilidad de reclamo, resolución, historia y devolución;
- evidencia previa para cada transición;
- una única garantía abierta por reclamo activo;
- prohibición de cancelación genérica sobre órdenes correctivas;
- compatibilidad SQLite y MySQL/MariaDB.

## Consecuencias

### Positivas

- La entrega original conserva su verdad histórica.
- El mismo activo puede tener varias intervenciones trazables.
- Garantía y presupuesto quedan como autorizaciones explícitas y excluyentes.
- WORK, Repuestos, Calidad y Entrega se reutilizan sin duplicar motores.
- Las nuevas garantías correctivas pueden encadenarse sin alterar las previas.

### Costos

- WORK y Repuestos incorporan una segunda fuente de autorización.
- Las migraciones deben reemplazar guardas existentes preservando su
  comportamiento comercial.
- La superficie HTTP/UI se implementará por separado en Reparaciones Core 10.

## Fuera de alcance

- cobros, reintegros y caja;
- compensaciones comerciales;
- garantía de proveedor o fabricante;
- notificaciones;
- portal del cliente;
- fotografías y archivos;
- firma digital;
- varias garantías dentro de un mismo reclamo;
- varios reclamos abiertos simultáneamente sobre la misma garantía.
