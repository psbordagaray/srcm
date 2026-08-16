# ADR 65 — Mercado Pago Point Sandbox Refund Read-Model Anomaly V1

Estado: Aceptada para P8.4.3.7.2

Checkpoint de partida:
`69799da2f063c1144002ebec846f8bf1e6233cad`

## 1. Contexto

P8.4.3.3 implementó y validó mediante `Http::fake` el contrato productivo de
refund de Mercado Pago Point sobre:

`POST /v1/orders/{order_id}/refund`

P8.4.3.4 dejó el adapter cableado detrás del gate financiero, con health Refund
intencionalmente en:

- `Degraded`;
- diagnóstico `refund_smoke_required`;
- automatización monetaria bloqueada.

P8.4.3.5–P8.4.3.7 alinearon y ejecutaron un smoke externo sandbox separado del
endpoint productivo, siguiendo el mecanismo oficial de simulación de estados:

`POST /v1/orders/{order_id}/events`

El smoke sandbox nunca constituye por sí mismo evidencia de refund productivo
real ni autoriza una promoción de health.

## 2. Contrato oficial contrastado el 2026-08-16

Mercado Pago Developers, en la documentación vigente de Point / Probar la
integración, indica para el escenario de reembolso sandbox:

1. la order debe estar previamente en `processed`;
2. se envía `POST /v1/orders/{order_id}/events` con `status=refunded`;
3. una solicitud exitosa devuelve `204 No Content`;
4. el cambio de estado puede demorar hasta 10 segundos;
5. `refunded` transiciona directamente, sin pasar por `at_terminal`;
6. se espera una notificación `order.refunded`;
7. `GET /v1/orders/{order_id}` puede utilizarse para verificar el estado.

La referencia del endpoint de simulación repite que `204` representa una
simulación aceptada y que el cambio de estado puede tardar hasta 10 segundos.

## 3. Evidencia R8

La corrida externa R8 utilizó exclusivamente credenciales de prueba y la
terminal virtual estándar.

Identidad observada:

- seller test user: `3615190576`;
- order: `ORDTST01M05DPWMF1TXZ6S1TJYP9H4PD`;
- payment: `PAY01M05DPWMXDP8KCDT9Z5VGAJT9`;
- importe: ARS 50.00.

Secuencia observada:

1. create -> `created`;
2. simulación `processed` -> HTTP `204`;
3. lectura canónica -> `processed/accredited`;
4. pago único por ARS 50.00 con `credit_card`, `visa`, 1 cuota;
5. simulación `refunded` -> HTTP `204`;
6. treinta lecturas canónicas posteriores conservaron
   `order.status=processed`.

Por lo tanto, el evento `refunded` fue aceptado por el endpoint oficial de
simulación, pero el read model consultable no materializó el estado esperado
dentro de una ventana tres veces mayor que la documentada.

## 4. Evidencia R9

R9 no creó una nueva order y no volvió a publicar ningún evento.

Fue una observación tardía read-only de la misma identidad R8.

Tres lecturas adicionales conservaron:

- order `processed`;
- `status_detail=accredited`;
- payment `processed/accredited`;
- mismo payment ID.

La divergencia no fue una demora marginal de la ventana de polling inicial.
Persistió en una observación tardía independiente.

Clasificación:

`PROVIDER_SANDBOX_READ_MODEL_ANOMALY_PERSISTENT`

## 5. Decisión

P8.4.3.7.2 NO convierte esta evidencia en un falso GREEN de refund sandbox.

También evita repetir indefinidamente el mismo smoke contra una condición que
ya quedó reproducida con identidad estable, aceptación HTTP `204`, polling
extendido y probe tardío.

Se registra el resultado externo como:

`AMBER_PROVIDER_SANDBOX_READ_MODEL_ANOMALY_PERSISTENT`

Esto significa:

- el mecanismo oficial de simulación fue aceptado;
- la lectura canónica documentada no confirmó `refunded`;
- SRCM no inventa el estado faltante;
- el smoke externo no satisface el criterio de lectura final consistente;
- la anomalía pertenece al ambiente sandbox observado, no a la verdad
  financiera de SRCM.

## 6. Health y gate productivo

No cambia ninguna barrera de dinero.

Refund permanece:

- health `Degraded`;
- diagnóstico `refund_smoke_required`;
- automation gate `BLOCKED`.

No se registra `Healthy` por:

- un `204` de simulación;
- una suposición sobre eventual consistency;
- una inferencia basada en documentación;
- una order de prueba;
- un estado no observado.

El endpoint productivo `/refund` no fue ejercitado externamente por R8/R9.

## 7. Contrato productivo preservado

P8.4.3.7.2 no modifica ni invalida:

- `MercadoPagoPointRefundAdapter`;
- `POST /v1/orders/{order_id}/refund`;
- idempotencia;
- total con body vacío;
- parcial con transacción e importe;
- normalización de `transactions.refunds[]`;
- dispatch/evidence append-only;
- `FinancialExternalMovement`;
- conciliación;
- políticas de autorización.

Los tests de esos contratos continúan siendo la evidencia interna determinista.

## 8. Reapertura del smoke

No repetir automáticamente R8/R9.

La investigación sandbox se reabre sólo si existe al menos una de estas
condiciones:

1. Mercado Pago modifica o aclara oficialmente el comportamiento de
   `refunded` en el simulador Point;
2. un nuevo smoke oficial obtiene lectura canónica `refunded`;
3. existe evidencia Webhook `order.refunded` utilizable de forma segura y se
   decide ampliar formalmente el criterio de observación mediante otro ADR;
4. soporte del proveedor confirma una incidencia o una precondición adicional;
5. cambia el contrato API relevante.

Ninguna de estas condiciones autoriza por sí misma dinero productivo.

## 9. Consecuencia para continuidad

El trabajo de P8 puede continuar sin ocultar la dependencia externa.

La situación queda explícita:

- implementación interna del contrato Refund: validada;
- wiring controlado: validado;
- sandbox processed: observado;
- sandbox refunded event: aceptado por HTTP 204;
- sandbox refunded canonical read: NO observado;
- clasificación externa: AMBER por anomalía persistente del proveedor;
- health productivo: Degraded;
- gate monetario: bloqueado.

SRCM conserva el principio APB: **no promover una capacidad monetaria por una
señal externa contradictoria y no reescribir la evidencia para obtener un
resultado deseado**.
