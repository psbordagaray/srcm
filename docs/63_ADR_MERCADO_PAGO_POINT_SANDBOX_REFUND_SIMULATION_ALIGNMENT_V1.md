# ADR 63 — Mercado Pago Point Sandbox Refund Simulation Alignment V1

Estado: Aceptada para P8.4.3.6

Checkpoint de partida:
`a075a7a57d37731360e667da4c9d8283daf92404`

## 1. Motivo

P8.4.3.5 dejó un harness local que ejercitaba con `Http::fake` el contrato real
`POST /v1/orders/{order_id}/refund`.

Antes de ejecutar un smoke externo, se volvió a contrastar el procedimiento con
la documentación oficial de pruebas de Mercado Pago Point.

La guía oficial de integración sandbox indica un flujo diferente para probar el
escenario de devolución:

1. crear una order con credenciales de prueba;
2. simular `processed` mediante `POST /v1/orders/{order_id}/events`;
3. esperar a observar la order procesada;
4. simular `refunded` mediante el mismo endpoint `/events`;
5. consultar la order para verificar el estado resultante.

El endpoint real `/refund` sigue siendo el contrato productivo validado en
P8.4.3.3, pero no es el mecanismo documentado para el smoke sandbox de
integración.

## 2. Decisión

P8.4.3.6 alinea exclusivamente el **harness sandbox** con el procedimiento
oficial.

`MercadoPagoPointRefundSandboxSmokeRunner` deja de invocar `refundOrder()`.

En su lugar utiliza:

- `simulateProcessed()`;
- GET canónico hasta `processed`;
- `simulateRefunded()`;
- GET canónico hasta `refunded`.

## 3. No mezclar simulación con verdad financiera

La simulación `refunded` no se interpreta como una transacción financiera real.

Por eso el resultado sandbox deja de exponer:

- `refundId`;
- `FinancialMovementStatus::Posted`.

El resultado expone únicamente evidencia de escenario de integración:

- order ID;
- payment ID observado en el estado procesado;
- terminal virtual;
- importe;
- moneda;
- `orderStatus = refunded`.

No se crea `FinancialExternalMovement`.

## 4. Contrato real de Refund

P8.4.3.6 NO revierte ni invalida P8.4.3.3.

El adapter productivo conserva:

`POST /v1/orders/{order_id}/refund`

con:

- `X-Idempotency-Key`;
- total con body vacío;
- parcial con `transactions[id, amount]`;
- normalización de `transactions.refunds[]`.

Ese contrato sigue cubierto por tests `Http::fake`.

## 5. Barreras

El smoke sandbox:

- rechaza `liveMode=true` antes de red;
- exige `live_mode=false` en las respuestas;
- utiliza `SBX0000001`;
- exige Point Argentina / ARS;
- verifica pago único e importe exacto;
- nunca llama `/refund`;
- nunca promueve health productivo;
- nunca modifica el gate productivo;
- nunca persiste secretos ni payload crudo.

## 6. Evidencia que sí aporta

Un smoke externo exitoso después de este checkpoint podrá afirmar:

- credencial de prueba aceptada;
- creación de Point Order sandbox;
- dispositivo virtual aceptado;
- transición sandbox a `processed`;
- transacción de pago observable;
- transición sandbox a `refunded`;
- lectura canónica final consistente.

No podrá afirmar que una devolución productiva real fue ejecutada.

## 7. Consecuencia para health

Incluso con un smoke sandbox exitoso:

`Refund health productivo = Degraded / refund_smoke_required`

permanece sin cambios.

La promoción de una conexión productiva concreta a `Healthy` requerirá una
decisión y evidencia distintas.

## 8. Próximo paso

Después del checkpoint P8.4.3.6 podrá ejecutarse el smoke externo sandbox con
credenciales de prueba locales y sin exponerlas en chat o archivos de resultado.
