# ADR 62 — Mercado Pago Point Refund Sandbox Smoke Harness V1

Estado: Aceptada para P8.4.3.5

Checkpoint de partida:
`522c25f1bcd27bb028ff8c18015532811c3029b2`

## 1. Objetivo

P8.4.3.4 deja el adapter Refund cableado pero el gate monetario en:

`Degraded / refund_smoke_required`

P8.4.3.5 agrega un harness explícitamente sandbox para validar el flujo
externo sin dinero real y sin alterar la verdad financiera de SRCM.

Este corte NO cambia health de una conexión productiva.

## 2. Contrato oficial de prueba

La documentación oficial vigente de Mercado Pago Point indica que:

- las pruebas de integración usan credenciales de prueba;
- no es posible procesar pagos reales en una terminal física con cuentas de
  prueba;
- existe un dispositivo virtual estándar con serial `SBX0000001`;
- el ejemplo oficial usa `NEWLAND_N950__SBX0000001`;
- una order de prueba puede simularse a `processed` mediante
  `POST /v1/orders/{order_id}/events`;
- el cambio puede demorar algunos segundos;
- el endpoint real de refund de Orders es
  `POST /v1/orders/{order_id}/refund`;
- `X-Idempotency-Key` es obligatorio;
- un refund total usa body vacío;
- una respuesta aceptada usa HTTP 201 y expone
  `transactions.refunds[].id`.

## 3. Arquitectura

Se agregan:

- `MercadoPagoPointSandboxOrdersClient`;
- `MercadoPagoPointRefundSandboxSmokeRunner`;
- `MercadoPagoPointRefundSandboxSmokeResult`.

El runner reutiliza:

- `MercadoPagoPointOrdersClient` para create/get/refund;
- `MercadoPagoPointRefundAdapter` para normalización de evidencia.

No crea otro adapter financiero ni otro ledger.

## 4. Secuencia del smoke

La secuencia externa esperada es:

1. rechazar `liveMode=true`;
2. crear una Point Order de importe mínimo en dispositivo virtual;
3. exigir que Mercado Pago responda `live_mode=false`;
4. simular `processed` mediante `/events`;
5. consultar la order hasta observar `processed`;
6. exigir una única transacción de pago y dinero exacto;
7. ejecutar refund total con idempotencia propia del smoke;
8. identificar exactamente un refund por payment + amount;
9. normalizarlo con el adapter P8.4.3.3;
10. si llega `processing`, consultar hasta obtener `processed`.

Resultado exitoso:

`FinancialMovementStatus::Posted`

## 5. Barreras de seguridad

El harness:

- rechaza `liveMode=true` antes de red;
- usa exclusivamente el serial virtual `SBX0000001`;
- exige `live_mode=false` en create/get antes de simular o reembolsar;
- limita el importe a 10000 minor units;
- no acepta floats;
- no persiste token;
- no persiste payload crudo;
- no escribe la BD de SRCM;
- no crea `FinancialExternalMovement`;
- no altera `CommercePayment`;
- no registra health `Healthy`.

Si un token fue rotulado erróneamente como test pero el proveedor devuelve
`live_mode=true`, el harness aborta antes de procesar o reembolsar.

## 6. Tests de este corte

Los tests usan exclusivamente `Http::fake`.

Validan:

- flujo create → processed → refund → Posted;
- terminal virtual;
- body vacío para refund total;
- idempotencia presente;
- live mode rechazado antes de red;
- `live_mode=true` del proveedor aborta después de create y antes de cualquier
  procesamiento/refund;
- diferencia de importe aborta antes de refund.

No existe HTTP real durante implementación/checkpoint.

## 7. Ejecución externa posterior

El código de P8.4.3.5 deja preparado el harness, pero este checkpoint no lo
ejecuta contra Mercado Pago.

La ejecución externa deberá hacerse en un paquete separado con credenciales de
prueba suministradas localmente y sin imprimirlas.

Su resultado servirá como evidencia de integración sandbox, no como health de
una conexión productiva.

## 8. Health productivo

Un smoke sandbox no demuestra que una conexión productiva concreta pueda
mover dinero.

Por tanto, después de un smoke exitoso:

- el contrato/harness puede considerarse externamente validado;
- `Refund health` productivo continúa `Degraded/refund_smoke_required`;
- el gate productivo continúa `BLOCKED`.

La promoción a `Healthy` requerirá una decisión operativa separada y evidencia
de la conexión concreta.
