# ADR 60 — Mercado Pago Point Refund Contract + Adapter V1

Estado: Aceptada para P8.4.3.3

Checkpoint de partida:
`1268d3844fbec2d3bcde2e27d2c2e0d3ffa2f6d9`

## 1. Evidencia externa

La documentación oficial vigente de Mercado Pago Point Orders establece:

- endpoint de reembolso:
  `POST /v1/orders/{order_id}/refund`;
- `Authorization: Bearer ...` obligatorio;
- `X-Idempotency-Key` obligatorio;
- reembolso total: body vacío;
- reembolso parcial: `transactions[]` con `id` de la transacción de pago y
  `amount`;
- la suma reembolsada no puede superar la transacción;
- la API responde HTTP 201 cuando acepta la solicitud;
- `transactions.refunds[].id` identifica el refund;
- `transactions.refunds[].transaction_id` identifica el pago reembolsado;
- `transactions.refunds[].amount` informa el valor;
- `transactions.refunds[].status` documenta `processing` y `processed`;
- para Point, el contrato documenta hasta 90 días y advierte que ciertas
  operaciones pueden exigir la terminal física.

La idempotencia admite una clave exclusiva, incluyendo UUID v4 o strings
aleatorias únicas.

## 2. Decisión

Se implementa `MercadoPagoPointRefundAdapter`, que cumple el contrato
provider-neutral `FinancialProviderRefundAdapter`.

El adapter:

1. obtiene secretos sólo desde `MercadoPagoConnectionSecretStore`;
2. hace GET canónico de la order original;
3. exige una única transacción de pago identificable;
4. decide total/parcial contra `paid_amount` o `amount`;
5. llama el endpoint de refund con la misma clave idempotente durable creada en
   P8.4.3.2;
6. exige que la respuesta contenga exactamente un refund que coincida con
   transacción e importe solicitados;
7. normaliza el refund como `ExternalFinancialProviderObservation` Debit.

## 3. Dinero exacto

No se aceptan floats.

Los importes externos se convierten a minor units desde strings/ints decimales
de máximo dos posiciones.

El importe normalizado debe coincidir exactamente con el instruido por SRCM.

## 4. Estados

Se mapean exclusivamente estados de refund documentados:

- `processing` → `Pending`
- `processed` → `Posted`

Cualquier estado no reconocido falla cerrado.

El estado de la order no se utiliza como sustituto del estado de la
transacción de refund.

## 5. Identidad externa

El `externalOperationId` financiero del reembolso es
`transactions.refunds[].id`, no el ID de la order original.

La `observationKey` incorpora refund ID + estado, permitiendo el patrón
append-only ya existente para:

`Pending → Posted`.

## 6. Recuperación

P8.4.3.2 persiste el dispatch antes de la llamada.

Ante resultado de red incierto, se reutiliza exactamente
`provider_idempotency_key`. El adapter no genera una clave nueva.

La documentación oficial de Mercado Pago exige idempotencia para evitar
duplicidad de reembolsos.

## 7. Polling

El adapter expone normalización de una order completa consultada posteriormente
para un refund ID ya conocido.

P8.4.3.3 no agrega un scheduler ni worker de polling; sólo deja el contrato
determinista para un corte posterior.

## 8. Compatibility snapshot

El registry incorpora un método explícito para registrar un snapshot nuevo de
Mercado Pago Point con `Refund = Compatible`.

Este snapshot NO reemplaza el snapshot de referencia histórico y NO migra
bindings automáticamente.

Por tanto:

- conexiones existentes siguen vinculadas al contrato anterior;
- el gate de automatización continúa bloqueando refund hasta una migración
  explícita del binding y un health Refund nuevo;
- P8.4.3.3 **no registra todavía el adapter en el container productivo**.

El adapter se valida por harness directo. Su wiring productivo queda reservado
al checkpoint de activación controlada.

## 9. Fuera de alcance

P8.4.3.3 no:

- registra el adapter Refund en el container productivo;
- migra bindings productivos;
- ejecuta un reembolso real;
- usa Access Token real en tests;
- consulta una terminal física;
- crea nuevas credenciales;
- persiste payload crudo;
- modifica `CommercePayment`;
- crea otro ledger financiero.

## 10. Próximo corte

P8.4.3.4 deberá validar el nuevo snapshot/binding en un entorno controlado,
registrar health Refund específico y ejecutar un smoke externo con usuario de
prueba si Mercado Pago permite una operación reproducible sin dinero real.

Producción continuará bloqueada hasta ese checkpoint explícito.
