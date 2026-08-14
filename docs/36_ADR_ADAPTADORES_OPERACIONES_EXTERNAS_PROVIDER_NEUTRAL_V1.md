# ADR 36 — Adaptadores y operaciones financieras externas provider-neutral

**Estado:** Aceptada como Foundation P5.1
**Fecha:** 2026-08-13
**Ámbito:** P5 — Operaciones externas y adaptadores

## 1. Decisión

SRCM no tendrá un segundo libro financiero por proveedor.

Mercado Pago, Payway, bancos, billeteras, adquirentes y futuros integradores son
**adaptadores**. Todos deben traducir su evidencia a los hechos financieros ya
existentes de P3:

`provider → adapter → observación segura → FinancialExternalMovement → conciliación`

`FinancialExternalMovement` sigue siendo la única verdad interna sobre un
movimiento externo observado.

P5 no redefine `PaymentReconciliation`.

## 2. Conexión de proveedor

P5.1 incorpora `financial_provider_connections` para vincular explícitamente:

- organización;
- `FinancialAccount`;
- `provider_key` canónico;
- ID de cuenta externa, cuando exista;
- estado activo/inactivo;
- actores de configuración.

La conexión **no almacena secretos**.

No existen columnas para access token, refresh token, client secret, API key,
webhook secret, contraseña, PAN o CVV.

Credenciales y secretos pertenecen a configuración segura externa al dominio
financiero y se incorporarán con el adaptador concreto correspondiente.

Una cuenta física de efectivo (`cash_box` / `cash_reserve`) no puede conectarse
a un proveedor financiero externo.

Una cuenta vinculada conserva inmutables su tipo, proveedor y moneda. Para
cambiar la identidad de integración se crea una nueva configuración explícita;
no se reescribe historia.

## 3. Contrato de adaptador

`ExternalFinancialProviderAdapter` representa el límite provider-specific.

Un adaptador:

1. conoce su `providerKey`;
2. recibe payload del proveedor sólo como entrada transitoria;
3. descarta secretos y datos sensibles;
4. produce `ExternalFinancialProviderObservation`;
5. no crea directamente movimientos financieros;
6. entrega la observación al ingestor provider-neutral.

Una observación contiene únicamente metadata financiera segura:

- proveedor;
- clave de observación;
- ID estable de operación externa;
- dirección;
- estado;
- moneda;
- bruto;
- neto;
- comisión;
- retención;
- referencia segura opcional;
- timestamp externo.

No se conserva payload crudo arbitrario.

## 4. Ingestión automática

P5.1 admite como fuentes automáticas:

- API;
- webhook;
- polling.

CSV/XLSX/manual continúan perteneciendo al motor P3/P7 y no entran por el
ingestor automático P5.

`ExternalFinancialProviderIngestor` valida el límite y delega el registro al
`ExternalFinancialMovementRecorder`.

El recorder conserva dos caminos explícitos:

- humano/autorizado, con `User`;
- automático, con `FinancialProviderConnection`.

El camino automático no inventa un usuario humano. `created_by_user_id` puede
ser NULL y la auditoría conserva actor humano NULL.

## 5. Idempotencia multicanal

Los proveedores pueden entregar la misma operación por webhook y luego por
polling.

Para evitar efectos duplicados:

- `source + source_key` conserva la idempotencia de entrega ya existente;
- P5.1 agrega una deduplicación provider-neutral por
  `financial_account + external_operation_id + status`;
- si la misma operación/estado reaparece con los mismos importes, SRCM devuelve
  el hecho ya registrado aunque llegue por otro canal;
- si la misma operación/estado reaparece con contenido financiero diferente,
  SRCM falla cerrado;
- un cambio de estado (`pending → posted`, por ejemplo) crea un nuevo hecho
  inmutable con el mismo `external_operation_id`, no actualiza el anterior.

Así:

`delivery retry ≠ nuevo hecho`

`otro canal, mismo estado financiero ≠ nuevo hecho`

`nuevo estado externo = nuevo hecho inmutable`

## 6. Autoridad e aislamiento

Sólo quien puede administrar cuentas financieras puede crear o activar una
conexión.

La ingestión automática no usa ese permiso humano en cada evento; usa una
conexión previamente creada y activa como límite de organización/cuenta.

Esto no constituye todavía un endpoint público. P5.2 deberá resolver:

- autenticación del proveedor;
- firma de webhook;
- secretos;
- identificación segura de conexión;
- jobs/reintentos;
- observabilidad de fallos.

Ningún webhook podrá seleccionar libremente una organización o cuenta a partir
de datos no verificados.

## 7. Relación con conciliación

P5 sólo registra evidencia externa.

Un `posted credit` puede posteriormente ser usado por P6 para conciliar un
`CommercePayment`.

Registrar evidencia externa:

- no confirma por sí solo un cobro declarado;
- no concilia;
- no modifica Venta;
- no modifica Caja;
- no crea un pago a proveedor;
- no resuelve diferencias silenciosamente.

## 8. Primer proveedor concreto

Mercado Pago será el primer adaptador concreto cuando se implemente P5.2.

P5.1 no incluye credenciales reales, llamadas HTTP, webhook público ni polling
real. Su objetivo es dejar el Core listo para que el primer adaptador se monte
sobre un contrato estable sin contaminar P3 con lógica específica de proveedor.

## 9. Principio vinculante

> **El proveedor entrega evidencia; el adaptador la traduce; P3 conserva la
> verdad financiera. Ningún canal externo obtiene permiso para reescribirla.**
