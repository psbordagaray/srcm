# ADR 41 — External Provider Maintenance Contract & Compatibility Registry V1

Estado: Aceptada para P5.7.1
Checkpoint de partida: `dc41bda2323062b7ab4f6e165f42d2388a921306`

## 1. Decisión

Las APIs de proveedores financieros externos no son una dependencia directa
del core de SRCM. Cada integración queda detrás de un adaptador y de un
contrato de compatibilidad explícito y versionado.

P5.7 introduce un **registry global de compatibilidad** separado de:

- las cuentas financieras de cada organización;
- `FinancialProviderConnection`, que sigue siendo el vínculo tenant-specific;
- la salud runtime de una conexión concreta, que corresponde a P5.8.

El registry nunca contiene secretos, tokens, contraseñas ni payloads sensibles.

## 2. Registry por snapshots inmutables

Cada evaluación se registra como un snapshot inmutable que identifica:

- proveedor;
- versión/contrato externo;
- referencia de evidencia del contrato externo;
- clase/adaptador SRCM cuando existe;
- versión del contrato del adaptador;
- estado global;
- si requiere migración;
- versión SRCM verificada;
- fecha de verificación;
- notas operativas.

Una corrección o reevaluación no edita el snapshot anterior: crea uno nuevo
con otra `registry_key`.

La misma `registry_key` es idempotente solamente cuando toda la evidencia y
todas las capacidades coinciden. Un conflicto falla cerrado.

## 3. Estados

Estados mínimos vinculantes:

- `compatible`
- `degraded`
- `migration_required`
- `blocked`
- `unknown`

`compatible` exige que todas las capacidades marcadas como obligatorias estén
también en `compatible`.

`migration_required=true` no puede ocultarse detrás de un estado global
`compatible` o `unknown`.

## 4. Capacidades

El registry V1 distingue al menos:

- `create`
- `read`
- `webhook`
- `refund`
- `reconciliation`

Cada capacidad tiene:

- estado propio;
- bandera `required`;
- evidencia;
- notas.

Una capacidad opcional puede permanecer `unknown` sin degradar por sí sola un
snapshot global `compatible`.

## 5. Mercado Pago como primera referencia

El snapshot Mercado Pago toma como evidencia P5.3–P5.6:

- Orders create: compatible;
- Orders canonical read: compatible;
- Point authenticated webhook: compatible;
- refund: unknown/no verificado;
- reconciliación provider-neutral: compatible a nivel de ingestión
  normalizada.

La firma Point conserva el case exacto de `data.id` según la evidencia externa
que cerró P5.6.

## 6. Payway como segunda referencia

Payway se registra como segundo proveedor para demostrar que el registry no
está diseñado alrededor de Mercado Pago.

En P5.7.1 no se inventa compatibilidad Payway:

- contrato externo: no verificado;
- adapter específico: ausente;
- estado global: `unknown`;
- capacidades: `unknown`.

## 7. Coexistencia y retiro

El diseño admite múltiples snapshots de un mismo proveedor y distintas
versiones de contrato/adaptador de manera simultánea.

La vinculación explícita de una `FinancialProviderConnection` a un snapshot y
la regla “no retirar una versión mientras existan conexiones activas que
dependan de ella” se implementarán en P5.7.2. P5.7.1 no modifica todavía la
identidad de las conexiones existentes.

## 8. Separación con P5.8

P5.7 responde:

> ¿Qué contrato externo + adapter SRCM se considera compatible y con qué
> evidencia?

P5.8 responderá:

> ¿Está sana hoy esta conexión concreta de esta organización?

No se mezclan ambas dimensiones.

## 9. Seguridad y degradación

Un proveedor externo degradado o incompatible sólo puede degradar/bloquear
automatizaciones que dependan de ese contrato. No puede degradar el core,
caja, inventario o ventas.

SRCM nunca inventa un resultado financiero cuando un proveedor está
indisponible o su contrato es desconocido.
