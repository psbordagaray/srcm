# ADR 38 — Mercado Pago Point Orders: cliente y prueba controlada

**Estado:** Propuesta ejecutable P5.3
**Fecha:** 2026-08-13
**Ámbito:** P5.3 — Orders API / entorno de prueba

## 1. Decisión

P5.3 incorpora el transporte HTTP mínimo para Mercado Pago Point Orders sin
convertir el proveedor en una segunda verdad financiera.

Flujo de prueba:

`SRCM → POST /v1/orders → dispositivo virtual → simulación de estado → GET order → adapter P5.2`

La prueba real de esta slice usa exclusivamente credenciales de prueba y el
dispositivo virtual estándar:

`NEWLAND_N950__SBX0000001`

No usa el Point físico del comercio y no mueve dinero real.

## 2. Cliente Orders

`MercadoPagoPointOrdersClient` implementa únicamente:

- crear una Point Order;
- obtener una Point Order por ID.

No incorpora todavía:

- cancelación productiva;
- reembolso productivo;
- webhooks;
- polling operativo;
- persistencia de credenciales;
- selección automática de cuenta financiera;
- conciliación automática.

El Access Token entra como valor transitorio de backend y nunca se persiste,
audita ni incluye en mensajes de excepción.

## 3. Idempotencia

Crear una order exige `X-Idempotency-Key`.

SRCM P5.3 requiere UUID v4 para esa clave.

Un reintento del mismo intento comercial debe reutilizar la misma clave. Una
operación comercial distinta debe usar una nueva.

P5.3 no genera todavía esa identidad desde `CommercePayment`; ese enlace será
una slice posterior.

## 4. Dinero

SRCM conserva importes internos en minor units enteros.

El cliente convierte:

`1234 minor → "12.34"`

sin floats.

Mercado Pago recibe `transactions.payments[0].amount` como string decimal.

## 5. Moneda del recurso Point

La respuesta vigente de Point Orders documenta `country_code` y no garantiza
un campo `currency`.

Por eso el adapter queda endurecido:

1. si existe `currency`, debe ser ISO de tres letras;
2. si no existe, `AR` o `ARG` se mapea explícitamente a `ARS`;
3. cualquier país no mapeado falla cerrado.

No se adivinan monedas de otros países.

## 6. Prueba controlada

El runner puede ejecutar, por opción explícita del usuario:

1. crear una order de prueba por ARS 1,00 en el dispositivo virtual;
2. simular el estado final `processed`;
3. consultar la order hasta observar `processed`;
4. normalizar el recurso mediante el adapter P5.2;
5. verificar `provider=mercado-pago`, `status=posted`, `currency=ARS`,
   `gross_amount_minor=100`.

El smoke no llama a `ExternalFinancialProviderIngestor`.

Por lo tanto no crea:

- `FinancialExternalMovement`;
- `PaymentReconciliation`;
- `CashMovement`;
- `CommercePayment`.

## 7. Webhooks

Mercado Pago recomienda tener webhooks configurados al validar la integración
completa. P5.3 prueba deliberadamente sólo el camino síncrono
create/simulate/get.

La autenticación y resolución de notificaciones será una slice separada.

## 8. Producción

P5.3 no autoriza credenciales productivas ni Point físico.

El paso productivo queda detrás de un checkpoint explícito posterior, con:

- conexión segura;
- terminal productiva identificada;
- modo PDV confirmado;
- vínculo a cuenta financiera;
- idempotencia desde una intención comercial;
- observabilidad;
- estrategia de cancelación/reintentos.

## 9. Principio vinculante

> **Primero demostrar el contrato con una order simulada y reversible; después
> conectar dinero real.**


## 10. Hardening V1.1 del harness

El primer intento externo de P5.3 reveló dos defectos del harness, no del Core:

- una excepción del smoke podía terminar con exit code 0 y producir un GREEN falso;
- el stack trace de PHP podía incluir argumentos y exponer el Access Token transitorio.

V1.1 vuelve vinculantes estas reglas:

- `zend.exception_ignore_args=1` durante el smoke;
- toda excepción del smoke se captura y termina con exit code distinto de cero;
- un error inesperado nunca imprime mensaje ni stack trace;
- los errores HTTP del cliente exponen sólo status, códigos provider y paths de campo sanitizados;
- jamás se imprime body arbitrario del proveedor, Authorization ni Access Token;
- el smoke usa ARS 5,00, alineado con el ejemplo oficial de escenario `processed`;
- cualquier HTTP 4xx/5xx impide el GREEN y exige diagnóstico antes del checkpoint.
