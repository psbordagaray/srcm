# ADR 37 — Mercado Pago Point sobre provider-neutral P5

**Estado:** Propuesta ejecutable P5.2
**Fecha:** 2026-08-13
**Ámbito:** P5.2 — Primer adaptador concreto

## 1. Decisión

Mercado Pago se integra a SRCM como **adaptador** y no como un libro financiero paralelo.

La primera superficie concreta será **Mercado Pago Point usando la API de Orders vigente**.

Flujo vinculante:

`Mercado Pago Point Order → MercadoPagoExternalFinancialProviderAdapter → ExternalFinancialProviderObservation → ExternalFinancialProviderIngestor → FinancialExternalMovement`

P5.2 no duplica `FinancialExternalMovement`, no modifica `PaymentReconciliation` y no crea una segunda verdad financiera.

## 2. API Point vigente

P5.2 se diseña contra la API de Orders actual:

- listar terminales: `GET /terminals/v1/list`;
- crear cobro Point: `POST /v1/orders`;
- consultar order: `GET /v1/orders/{order_id}`;
- cancelar: `POST /v1/orders/{order_id}/cancel`;
- reembolsar: `POST /v1/orders/{order_id}/refund`.

Las operaciones mutantes exigen idempotencia explícita. P5.2 Foundation no ejecuta ninguna de ellas en producción.

## 3. Límite del adapter de esta slice

`MercadoPagoExternalFinancialProviderAdapter` normaliza únicamente un **recurso completo Point Order**:

- respuesta API completa; o
- webhook cuyo `data` contenga el recurso completo.

Una notificación que sólo contenga un ID se rechaza. El ID por sí solo no es evidencia financiera suficiente: el futuro handler deberá resolver el recurso completo de forma autenticada antes de ingerir.

El adapter no persiste payload crudo.

## 4. Identidad e idempotencia

Para Point, una order admite un pago y la identidad estable de lifecycle es el `order.id`.

Por eso:

- `external_operation_id = order.id`;
- `observation_key = point-order:{order.id}:{status}[:v{version}]`;
- mismo status/version produce una clave determinista;
- los cambios de status siguen siendo hechos append-only bajo P5.1.

## 5. Status provider-neutral

Mapeo inicial:

- `created`, `at_terminal`, `action_required` → `pending`;
- `processed` → `posted`;
- `refunded` → `reversed`;
- `canceled`, `expired`, `failed` → `failed`.

Cualquier status no reconocido falla cerrado.

## 6. Dinero

La API de Orders expresa importes decimales. El adapter convierte unidades monetarias a minor units sin float binario.

Se rechazan floats y decimales con precisión inesperada.

En esta slice, la Order Point constituye evidencia **bruta** del cobro. No se inventan comisiones ni retenciones que no estén expresadas por esa superficie:

- `gross_amount_minor = importe de la order`;
- `net_amount_minor = gross_amount_minor`;
- `fee_amount_minor = 0`;
- `withholding_amount_minor = 0`.

Esto no significa que Mercado Pago no cobre comisiones. Significa que P5.2 no las adivina. La verdad de liquidación/comisiones deberá enriquecerse desde una fuente provider-specific que las exponga de manera inequívoca antes de usarlas como neto de acreditación.

## 7. Datos sensibles

El adapter ignora deliberadamente:

- access tokens;
- payment/card tokens;
- PAN completo;
- primeros/últimos dígitos como referencia interna;
- email/datos del pagador;
- payload arbitrario;
- `external_reference` como raw reference.

`raw_reference` se construye sólo con ID de order, status y status_detail sanitizado.

## 8. Credenciales

Los Access Token no pertenecen a la base financiera ni a Git.

Para pruebas reales se usarán únicamente en memoria/variable de proceso y por header `Authorization: Bearer ...`.

El runner P5.2 puede realizar un smoke test **read-only** contra `GET /terminals/v1/list`:

- no imprime el token;
- no guarda el token;
- no modifica terminales;
- no crea orders;
- no cobra;
- no cancela;
- no reembolsa.

## 9. Point real

Disponer de un Point físico permite una validación posterior de extremo a extremo, pero una transacción real sólo se autorizará después de:

1. validar adapter y suite local;
2. identificar de forma read-only la terminal correcta;
3. confirmar cuenta/sucursal/caja/modo PDV;
4. definir un importe de prueba explícito;
5. usar una clave de idempotencia nueva;
6. capturar y conciliar el resultado sin secretos ni datos de tarjeta.

## 10. Principio vinculante

> **Primero identificar y observar; después mover dinero. Un Access Token nunca es un dato del dominio financiero y una notificación incompleta nunca se convierte en verdad por conveniencia.**
