# ADR 79 — Customer Credit Policy + Admin Override V1

Estado: Aceptada para P9.4

Checkpoint de partida:
`880301efe3692eef5e59a9c92b500f2b25913401`

Fecha:
`2026-08-17`

## 1. Contexto

P9.1 incorporó `CustomerReceivable`, P9.2 incorporó cobranzas y aplicaciones, y
P9.3 incorporó aging y exposición derivada. El relevamiento P9.4 confirmó que no
existía todavía una entidad de límite, política o excepción de crédito, aunque
SRCM ya posee patrones maduros de autorización explícita en Inventario, Compras
y Caja.

Dirección fijó la política comercial:

- modalidad **controlada**;
- una venta a crédito que supera el límite debe bloquearse;
- una deuda vencida debe bloquear nuevo crédito;
- sólo un Administrador puede autorizar una excepción;
- el Operador no puede eludir esas condiciones.

## 2. Decisión

P9.4 introduce:

1. `CustomerCreditPolicy`: versiones append-only del límite por cliente y
   moneda;
2. `CustomerCreditExposureReader`: exposición derivada desde P9.1–P9.3;
3. `CustomerCreditPolicyGuard`: decisión transaccional antes de cualquier
   efecto de stock;
4. `CustomerCreditOverride`: evidencia append-only de una excepción aprobada
   por Administrador;
5. evidencia de decisión en `CustomerReceivable`.

No se crea un saldo mutable de crédito.

## 3. Exposición

Para cada cliente y moneda:

`exposición = SUM(deuda original - cobranzas confirmadas aplicadas)`

`exposición proyectada = exposición + nuevo saldo pendiente`

El límite nunca mezcla monedas.

`CustomerCredit` continúa siendo saldo a favor del cliente y no se netea
automáticamente contra esta exposición.

## 4. Política versionada

Cada cambio de límite crea una nueva versión inmutable con:

- cliente;
- moneda;
- límite;
- motivo administrativo;
- Administrador;
- fecha de servidor;
- idempotencia y fingerprint.

La última versión es la política vigente.

Un límite `0` permite expresar que el Operador no dispone de cupo ordinario.

## 5. Venta dentro de política

Con política configurada, Administrador u Operador pueden registrar una venta
con saldo pendiente cuando simultáneamente:

- el cliente está activo;
- no posee deuda vencida;
- la exposición proyectada no supera el límite vigente.

La decisión queda trazada como `within_policy`.

## 6. Bloqueo y excepción

Con política configurada, se requiere excepción administrativa cuando:

- la exposición proyectada supera el límite; o
- existe cualquier importe vencido pendiente.

El Operador falla cerrado antes de crear salida de inventario, venta, pago o
cuenta por cobrar.

El Administrador puede continuar únicamente informando un motivo explícito.
SRCM materializa `CustomerCreditOverride` con:

- venta;
- cliente y moneda;
- política vigente;
- importe nuevo;
- exposición anterior y proyectada;
- importe vencido y mayor atraso;
- límite;
- flags de sobrelímite/vencido;
- fingerprint del snapshot;
- motivo;
- Administrador y tiempo.

La cuenta por cobrar enlaza esa excepción.

## 7. Transición de despliegue

P9.1 existía antes de que hubiese políticas configurables. Para no convertir la
migración de P9.4 en un corte operativo incompatible:

- si un cliente/moneda **todavía no tiene ninguna política**, sólo el
  Administrador conserva el comportamiento P9.1;
- el Operador queda bloqueado hasta que un Administrador defina la primera
  versión del límite;
- la confirmación del Administrador en ese estado se registra como
  `legacy_admin`;
- una vez creada la primera política, el modo controlado es obligatorio para
  las ventas nuevas de ese cliente/moneda.

Este modo transitorio no debe interpretarse como cupo ilimitado para
Operadores.

## 8. Atomicidad

La política se evalúa dentro de la misma transacción de checkout y antes de la
salida de inventario.

El row-lock del cliente serializa para ese cliente:

- nuevas ventas a crédito;
- cobranzas;
- cambios de política.

Si el riesgo exige excepción, la excepción se crea contra la venta en estado
`building` antes de materializar la cuenta por cobrar.

## 9. Integridad de base

P9.4 agrega guardas SQLite y MySQL/MariaDB para:

- versiones de política;
- inmutabilidad;
- autoridad Administrador al configurar límite;
- excepciones contra venta, cliente y política vigentes;
- recomputación de exposición y vencido al insertar la excepción;
- evidencia de decisión obligatoria en nuevas cuentas por cobrar;
- `within_policy` sólo con exposición proyectada dentro del límite y sin
  vencido;
- `admin_override` sólo con excepción coincidente y Administrador;
- `legacy_admin` sólo mientras no exista política.

Las cuentas por cobrar históricas previas a P9.4 pueden conservar columnas de
evidencia nuevas en `NULL`; la guarda aplica a inserciones posteriores.

## 10. Superficie operativa

La Cuenta Corriente muestra por ARS/USD:

- política configurada o modo transitorio;
- límite;
- exposición actual;
- vencido;
- cupo disponible;
- versión vigente.

El Administrador puede registrar una nueva versión del límite con motivo.

La pantalla de Venta muestra la política del cliente seleccionado. El Operador
puede cargar saldo pendiente dentro de política. Ante riesgo ve el bloqueo. El
Administrador ve el campo de motivo de excepción.

## 11. Fuera de alcance

P9.4 no implementa:

- solicitud de excepción por Operador y aprobación asincrónica posterior;
- bandeja/attention específica de crédito;
- cuotas propias;
- anticipos/señas;
- intereses o mora;
- cupo global multimoneda;
- neteo automático con saldo a favor;
- scoring externo.

Una futura mejora puede agregar handoff Operador → Administrador sin modificar
los hechos append-only definidos aquí.

## 12. Criterio de aceptación

P9.4 es GREEN si:

1. la política es versionada, idempotente e inmutable;
2. Operador vende a crédito sólo dentro de política;
3. sobrelímite bloquea al Operador antes de stock;
4. deuda vencida bloquea al Operador aunque quede cupo;
5. Administrador requiere motivo para una excepción bajo política;
6. la excepción conserva snapshot, motivo y actor;
7. una cobranza cambia la exposición derivada sin reescribir política ni deuda;
8. falta de política mantiene sólo el modo transitorio Administrador;
9. la DB rechaza política, excepción o evidencia de receivable forjadas;
10. P9.1–P9.3, CustomerCredit y checkout permanecen GREEN;
11. la suite integral queda GREEN;
12. la BD real local permanece byte-a-byte intacta y sin migración real.

## 13. Continuidad

Los próximos slices de CxC —cuotas propias y anticipos/señas— deben consumir
esta política sin convertir límite, exposición o pendiente en saldos manuales
mutables.

## 14. Aceptación P9.4

P9.4 quedó validado GREEN antes de su checkpoint de publicación:

- política de crédito controlado por cliente y moneda implementada;
- límites versionados, idempotentes e inmutables;
- exposición derivada desde CxC/aging, sin saldo mutable paralelo;
- Operador habilitado sólo para crédito dentro de política;
- sobrelímite bloquea al Operador antes de efectos de stock;
- deuda vencida bloquea al Operador aunque exista cupo disponible;
- excepción de Administrador exige motivo explícito y conserva snapshot, actor y tiempo;
- cobranza confirmada reduce exposición derivada y puede rehabilitar el crédito ordinario;
- ausencia de política conserva únicamente el modo transitorio Administrador;
- no existe bypass del Operador;
- no existe neteo automático con `CustomerCredit`;
- focal P9.4: 8 tests / 68 assertions;
- regresiones P9.1–P9.3, CustomerCredit, checkout y patrones de autorización: GREEN;
- suite completa: 742 tests / 6149 assertions;
- BD real byte-a-byte sin cambios;
- ninguna migración real ejecutada;
- ningún HTTP externo de proveedor ejecutado.

El checkpoint publica exactamente los 26 paths P9.4 sobre el parent
`880301efe3692eef5e59a9c92b500f2b25913401` con el commit:

`feat(commerce): add customer credit policy foundation`
