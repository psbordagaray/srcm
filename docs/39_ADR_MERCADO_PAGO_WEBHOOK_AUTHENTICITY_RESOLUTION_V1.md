# ADR 39 — Mercado Pago Webhooks: autenticidad y resolución segura

**Estado:** Propuesta ejecutable P5.4
**Fecha:** 2026-08-13
**Ámbito:** P5.4 — autenticidad, resolución y frontera de tenancy

## 1. Decisión

P5.4 incorpora la fundación de recepción segura de Webhooks de Mercado Pago
Point sin exponer todavía una URL HTTP pública y sin persistir secretos.

El flujo autorizado de esta slice es:

`request metadata -> HMAC verify -> notification contract -> expected identity
check -> GET /v1/orders/{id} -> adapter -> provider-neutral observation`

P5.4 no llama todavía a `ExternalFinancialProviderIngestor`.

## 2. Firma oficial y contrato Point efectivo

Mercado Pago envía:

- query `data.id`;
- header `x-request-id`;
- header `x-signature` con `ts` y `v1`.

El manifest usado por SRCM para **Mercado Pago Point** es:

`id:<data.id exacto>;request-id:<x-request-id>;ts:<ts>;`

La contrafirma es HMAC-SHA256 hexadecimal y se compara en tiempo constante.

La documentación general de Webhooks publicada por Mercado Pago contiene una
regla que indica pasar a minúsculas un `data.id` alfanumérico. Sin embargo, el
contrato Point verificado en P5.6 presenta evidencia específica y contradictoria:

- la documentación Point muestra IDs `ORD...` en mayúsculas y deriva la
  validación a los SDKs oficiales pasando `data.id` de la query;
- el `WebhookSignatureValidator` PHP oficial construye el manifest con
  `dataId` tal como fue recibido, sin normalizar el case;
- la simulación externa real de Point ejecutada el 2026-08-14 llegó a SRCM con
  `data.id` alfanumérico en mayúsculas y su HMAC coincidió con el manifest de
  case exacto, no con el manifest en minúsculas.

Por lo tanto, para el endpoint **Point** SRCM conserva byte a byte el case del
`data.id` recibido al construir el HMAC. No se admite una transformación
arbitraria de case ni se modifica luego el identificador para routing o para la
resolución canónica.

Esta decisión es específica del contrato Point observado y debe quedar sujeta
al registro de compatibilidad de proveedores P5.7. Un cambio futuro del
contrato externo requiere evidencia y migración explícita; no una relajación
silenciosa del verificador.

SRCM P5.4 exige los tres valores del manifest. Aunque la documentación general
permite omitir pares ausentes, para Point no aceptamos una notificación con
identidad incompleta.

## 3. No confiar en el body para tenancy

La firma Webhook autentica el manifest, no convierte arbitrariamente todos los
campos del body en una clave de tenancy segura.

Por eso:

- `application_id` no selecciona organización;
- `user_id` no selecciona organización;
- `data.external_reference` no selecciona organización;
- ninguna referencia recibida decide `FinancialAccount`;
- el futuro endpoint debe resolver primero una conexión interna desde una
  fuente segura de configuración/secretos.

Después de resolver esa identidad interna, P5.4 permite comparar el
`application_id`, `user_id` y `live_mode` recibidos contra los valores
esperados. Una discrepancia falla cerrado.

## 4. Recurso canónico

Aunque Mercado Pago puede enviar datos de la order dentro del body, P5.4 no
los usa como verdad financiera.

Después de verificar firma e identidad, SRCM realiza:

`GET /v1/orders/{data.id}`

con Access Token transitorio y normaliza ese recurso completo mediante
`MercadoPagoExternalFinancialProviderAdapter`.

De esta forma:

- un amount arbitrario dentro del body no entra al ledger;
- datos personales del body no se persisten;
- el mismo adapter usado por API/polling/webhook conserva una sola semántica.

## 5. Secretos

P5.4 no agrega columnas de secretos y no incorpora secrets al repositorio.

`webhookSecret` y `accessToken` son argumentos transitorios del resolver.

La selección del secret store productivo, su rotación y su asociación a una
conexión financiera se implementarán en una slice posterior.

## 6. Timestamp y reintentos

P5.4 valida el formato de `ts`, pero no aplica todavía una ventana temporal
corta.

Mercado Pago reintenta Webhooks cuando no recibe confirmación y esos reintentos
pueden ser diferidos. La defensa contra duplicados se conserva en la identidad
estable de la order, su estado y el recorder append-only P5.1.

Una política temporal productiva sólo se agregará si no rompe reintentos
legítimos.

## 7. Acciones Point admitidas

P5.4 reconoce las acciones documentadas:

- `order.processed`;
- `order.canceled`;
- `order.refunded`;
- `order.action_required`;
- `order.failed`;
- `order.expired`.

Acciones no conocidas fallan cerrado hasta ser incorporadas explícitamente.

## 8. HTTP público

P5.4 no crea todavía una ruta pública.

Antes de exponerla se debe resolver:

1. secret store;
2. vínculo secret/application/user -> conexión interna;
3. estrategia de ACK rápido;
4. cola/job y reintentos internos;
5. protección CSRF específica de la ruta;
6. observabilidad sin payloads sensibles;
7. URL HTTPS pública de pruebas;
8. simulación desde Tus integraciones.

## 9. Principio vinculante

> **Una notificación autenticada identifica un recurso externo; nunca obtiene
> por sí sola autoridad para elegir tenant, cuenta ni crear un hecho financiero.**
