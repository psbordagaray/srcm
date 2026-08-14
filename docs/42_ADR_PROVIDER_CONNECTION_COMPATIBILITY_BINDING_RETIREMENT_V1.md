# ADR 42 — Provider Connection Compatibility Binding & Retirement V1

Estado: Aceptada para P5.7.2

Checkpoint de partida:
`b9cdfeb200a98158d5d2648de387d12970e1ec2e`

## 1. Decisión

P5.7.2 vincula cada conexión tenant-specific con snapshots globales del
Compatibility Registry sin convertir el contrato externo en parte mutable de
la identidad de `FinancialProviderConnection`.

La vinculación es un historial append-only separado.

## 2. Binding append-only

`financial_provider_connection_compatibility_bindings` registra:

- conexión financiera;
- snapshot global de compatibilidad;
- binding anterior;
- actor que realizó el binding;
- instante de binding.

El primer binding no posee anterior.

Una migración agrega otro binding que apunta al binding actual. Nunca modifica
ni elimina el anterior.

`previous_binding_id` es único. Por lo tanto, un binding no puede generar dos
ramas de migración.

## 3. Compatibilidad operativa

Un snapshot puede convertirse en binding operativo cuando:

- pertenece al mismo `provider_key` que la conexión;
- no fue retirado;
- `migration_required=false`;
- su estado global es `compatible` o `degraded`.

`unknown`, `migration_required` y `blocked` fallan cerrado para bindings
operativos.

`degraded` se permite como contrato conocido pero parcialmente degradado. La
decisión capability-specific de bloquear automatizaciones inseguras se
completa en P5.8.

## 4. Transición de conexiones legacy

P5.7.2 no reescribe retroactivamente la identidad de una conexión ya creada.

Una conexión todavía sin binding sigue existiendo durante la transición. Una
vez que posee binding, su reactivación exige que el binding actual continúe
siendo utilizable.

Esto permite migrar instalaciones existentes sin mezclar un cambio destructivo
de datos con la introducción del registry.

## 5. Retiro de versiones

El retiro es un registro append-only separado:

`financial_provider_compatibility_retirements`.

Un snapshot no puede retirarse si existe cualquier conexión activa cuyo
binding actual dependa de ese snapshot.

La regla existe tanto en dominio como en la base de datos.

Después de migrar una conexión activa a un snapshot nuevo, el snapshot viejo
deja de ser su dependencia actual y puede retirarse aunque el historial de
binding siga conservado.

## 6. Reactivación

Una conexión inactiva que conserva como binding actual un snapshot retirado,
bloqueado, desconocido o pendiente de migración no puede reactivarse.

No se inventa una migración automática ni se sustituye silenciosamente el
snapshot.

## 7. Coexistencia

Dos o más versiones del mismo proveedor pueden coexistir en el registry.

Distintas conexiones pueden depender de versiones diferentes durante una
migración gradual.

El retiro de una versión sólo es posible cuando ninguna conexión activa la
mantiene como dependencia actual.

## 8. Seguridad

Los bindings y retiros:

- no contienen Access Tokens;
- no contienen Webhook Secrets;
- no contienen credenciales;
- no contienen payloads externos.

Los snapshots globales siguen separados de la salud runtime tenant-specific,
que corresponde a P5.8.

## 9. Inmutabilidad

Binding y retiro son inmutables tanto a nivel Eloquent como mediante triggers
de base de datos.

La base también rechaza:

- binding con provider mismatch;
- binding de snapshot retirado;
- cadena sin binding anterior cuando ya existe historial;
- `previous_binding_id` perteneciente a otra conexión;
- retiro con dependencia activa.

## 10. Alcance

P5.7.2 completa el contrato de mantenimiento P5.7 a nivel de:

- coexistencia de versiones;
- dependencia de conexiones;
- migración explícita;
- retiro seguro.

P5.8 agregará health checks y monitoreo de compatibilidad/conexión.
