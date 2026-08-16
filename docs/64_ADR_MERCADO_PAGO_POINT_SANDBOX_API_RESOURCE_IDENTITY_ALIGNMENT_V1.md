# ADR 64 — Mercado Pago Point Sandbox API Resource Identity Alignment V1

Estado: Aceptada para P8.4.3.7.1

Checkpoint de partida:
`50caf3741878a36d9f788bee7a3e49cf47ee874f`

## 1. Evidencia externa que originó este corte

Durante P8.4.3.7 el preflight autenticado de `/users/me` confirmó la identidad
exacta del vendedor de prueba.

Con el request de creación alineado a la referencia oficial Point (`50.00` y
`NEWLAND_N950__SBX0000001`), `POST /v1/orders` dejó de fallar por amount y el
harness avanzó hasta su propia guarda local.

La guarda abortó porque exigía `live_mode=false` dentro del recurso API de
Orders.

## 2. Contrato oficial observado

La referencia oficial vigente de:

- `POST /v1/orders`, y
- `GET /v1/orders/{order_id}`

documenta recursos Point con:

- `id`;
- `type=point`;
- `user_id`;
- `country_code`;
- `config.point.terminal_id`;
- `status`;
- `transactions`.

Esos recursos no documentan `live_mode`.

En cambio, los ejemplos de notificaciones Webhook de los escenarios sandbox sí
incluyen `live_mode=false` en el **envelope de notificación**.

Por lo tanto, no corresponde trasladar un campo del envelope Webhook al
contrato del recurso Orders API.

## 3. Decisión

`MercadoPagoPointRefundSandboxSmokeRunner` deja de exigir la presencia de
`live_mode` en respuestas de create/get.

La prueba sandbox se ata a evidencia que sí pertenece al recurso API:

1. el secreto local debe estar clasificado `liveMode=false`;
2. `type` debe ser `point`;
3. `user_id` debe coincidir exactamente con el usuario de prueba esperado;
4. `config.point.terminal_id` debe coincidir exactamente con
   `NEWLAND_N950__SBX0000001` para el poi_type elegido;
5. `country_code` debe ser `AR` o `ARG`;
6. la create debe comenzar en `created`;
7. el pago procesado debe conservar el importe exacto.

Si Mercado Pago llegara a devolver explícitamente un campo `live_mode`, su único
valor aceptado continúa siendo `false`.

## 4. Barrera externa complementaria

El ejecutor externo P8.4.3.7 realiza antes de crear la order un GET autenticado
`/users/me` y compara exactamente la identidad con el User ID del vendedor de
prueba ingresado localmente.

Esa prueba no se persiste en SRCM y no promueve health productivo.

## 5. No cambia

Este corte no cambia:

- el contrato productivo `POST /v1/orders/{id}/refund`;
- el adapter Refund;
- compatibility/bindings;
- health productivo;
- automation gate;
- FinancialExternalMovement;
- CommercePayment;
- BD real.

Tampoco ejecuta HTTP externo durante tests o durante el paquete de
implementación.

## 6. Tests

Los tests pasan a modelar el recurso Orders API oficial **sin `live_mode`** y
validan:

- create -> processed -> refunded;
- usuario exacto;
- terminal virtual exacta;
- rechazo local de `liveMode=true`;
- rechazo si un eventual `live_mode` explícito es true;
- dinero exacto antes de refunded;
- ausencia de llamada al endpoint productivo `/refund`.

## 7. Consecuencia

Después de este corte, el smoke externo puede reintentarse sin inventar un
campo que no pertenece al recurso Orders API.

Un smoke sandbox exitoso continúa siendo evidencia de integración, no health
productivo `Healthy`.
