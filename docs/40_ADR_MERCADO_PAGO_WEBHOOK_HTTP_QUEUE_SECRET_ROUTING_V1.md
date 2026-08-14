# ADR 40 — Mercado Pago Webhook HTTP/Queue y secret routing

**Estado:** Propuesta ejecutable P5.5
**Fecha:** 2026-08-13
**Ámbito:** P5.5 — endpoint stateless, secret routing, ACK y job

## 1. Decisión

P5.5 convierte la fundación P5.4 en una superficie HTTP ejecutable sin
entregar autoridad de tenancy al proveedor.

Flujo:

`POST /api/webhooks/finance/mercado-pago/{connectionPublicId}`
`-> resolver conexión interna`
`-> cargar secretos fuera de DB/repo`
`-> parsear query/body`
`-> validar HMAC + identidad`
`-> encolar datos mínimos`
`-> HTTP 200`
`-> job GET /v1/orders/{id}`
`-> adapter`
`-> ExternalFinancialProviderIngestor(Webhook)`

## 2. Route-owned connection routing

El route parameter `connectionPublicId` es el UUID ya existente e inmutable de
`FinancialProviderConnection`.

No se utiliza implicit route binding porque el modelo tenant-owned exige
`CurrentOrganization` para el binding normal, mientras que un Webhook público
no tiene sesión organizacional.

La consulta pública sólo acepta una conexión:

- existente;
- activa;
- `provider_key=mercado-pago`.

El UUID no es un secreto y no autentica por sí solo. Su única función es
seleccionar el candidato interno cuya clave HMAC deberá validar la petición.

Un body firmado con secretos de otra conexión no puede saltar de tenant porque
se verificará contra el secret de la conexión fijada por la URL.

## 3. Secret store

Los secretos no se agregan a `financial_provider_connections`.

Se introduce el contrato:

`MercadoPagoConnectionSecretStore`

y la implementación inicial:

`EnvironmentMercadoPagoConnectionSecretStore`.

La implementación lee en runtime:

`SRCM_MERCADO_PAGO_CONNECTION_SECRETS_JSON`

con un mapa cuya clave es el `public_id` de la conexión.

Cada entrada contiene:

- `webhook_secret`;
- `access_token`;
- `application_id`;
- `user_id`;
- `live_mode`.

La variable debe ser inyectada como variable de entorno del proceso en
producción. No se incluye ningún valor real en `.env.example`, repositorio,
logs, jobs ni base de datos.

La interfaz permite reemplazar esta implementación por un secret manager
externo sin modificar controller/job.

## 4. Invariante external_account_id

Cuando `FinancialProviderConnection.external_account_id` está definido, P5.5
exige que coincida con el `user_id` esperado del secret store.

Esto añade una segunda unión interna entre la conexión y la identidad del
vendedor sin confiar en el body recibido.

## 5. Query string cruda

Mercado Pago documenta `data.id` como query param usado en la firma.

PHP históricamente normaliza puntos en nombres de parámetros al construir
`$_GET`. Incluso el ejemplo PHP oficial de Mercado Pago utiliza `data_id`.

SRCM evita depender de esa transformación y parsea directamente
`QUERY_STRING`, preservando la clave literal `data.id`.

Se rechazan:

- query vacía;
- duplicados;
- `data.id` ausente;
- `type` ausente;
- tamaños anómalos.

## 6. Body

El endpoint exige `application/json`.

El body:

- se limita a 32 KiB;
- se decodifica como objeto JSON;
- se utiliza sólo para autenticar/validar la notificación;
- no se persiste;
- no se serializa en el job;
- no se usa como fuente de importes financieros.

La verdad financiera continúa siendo `GET /v1/orders/{id}`.

## 7. ACK rápido

Mercado Pago espera `HTTP 200` o `201` y recomienda confirmar primero la
recepción para procesar luego en servidor.

P5.5 realiza sincrónicamente sólo trabajo local barato:

1. conexión;
2. secret lookup;
3. parsing;
4. HMAC;
5. identidad externa;
6. enqueue.

No realiza `GET` a Mercado Pago antes del ACK.

Sólo responde `200` después de que Laravel aceptó el dispatch del job. Si la
firma/identidad falla, responde `401`. Si el enqueue genera una excepción, la
respuesta no llegará a `200` y Mercado Pago podrá reintentar.

## 8. Cola

SRCM ya usa `database` como queue por defecto y posee tabla `jobs`.

P5.5 usa la cola por defecto para no requerir una nueva configuración de worker.

El job serializa exclusivamente:

- `connectionPublicId`;
- `resourceId`;
- `notificationId`.

No serializa:

- Access Token;
- Webhook Secret;
- body;
- payer;
- datos de tarjeta;
- headers arbitrarios.

El job vuelve a resolver la conexión y los secretos en el momento de ejecución.

## 9. Procesamiento

`ProcessMercadoPagoPointWebhook`:

1. resuelve la conexión activa por UUID;
2. obtiene el Access Token desde secret store;
3. ejecuta `GET /v1/orders/{id}`;
4. normaliza con el adapter Mercado Pago;
5. ingresa la observación por
   `ExternalFinancialProviderIngestor` con source `Webhook`.

Los reintentos de Mercado Pago y de la cola son seguros porque el recorder P5.1
ya deduplica misma operación/estado/dinero y conserva transiciones append-only.

## 10. CSRF y sesión

La ruta se define en `routes/api.php`.

Laravel aplica el middleware group `api`, que es stateless y no contiene la
protección CSRF del grupo `web`.

No se instala autenticación de usuario para el Webhook: la autenticación es
HMAC del proveedor, ligada a una conexión interna concreta.

## 11. Qué no hace P5.5

P5.5 no:

- publica todavía una URL HTTPS en Internet;
- configura Mercado Pago Developers;
- usa credenciales reales;
- prueba Webhooks externos;
- agrega secret manager cloud;
- agrega dashboard de jobs;
- cambia la cola por defecto;
- reconcilia automáticamente una venta concreta.

La primera prueba externa del endpoint queda para un checkpoint separado.

## 12. Principio vinculante

> **ACK rápido sólo después de autenticar y encolar; secretos y payload bruto
> nunca viajan con el job; la order canónica es la única evidencia financiera.**

<!-- P5.6_REAL_POINT_ENVELOPE_2026_08_14 -->
## 13. Hallazgo P5.6 — envelope Point real/documentado

La validación HTTPS externa de P5.6 confirmó una diferencia entre los mocks
iniciales y el envelope `order.processed` documentado por Mercado Pago Point.

Para una notificación procesada, Mercado Pago puede enviar:

- `action`, `api_version`, `application_id`, `date_created`, `live_mode`,
  `type` y `user_id` en el nivel superior;
- la Order completa dentro de `data`;
- `data.id` como identificador de recurso;
- sin un `id` de notificación obligatorio en el nivel superior.

La documentación de validación de firma también muestra una forma compacta que
sí incluye `id` superior. Por lo tanto SRCM acepta ambas variantes.

Decisión vinculante:

- `data.id` sigue siendo obligatorio y debe coincidir entre query y body;
- `x-signature` + `x-request-id` + `data.id` siguen siendo obligatorios;
- `application_id`, `user_id` y `live_mode` siguen comparándose con la
  identidad esperada de la conexión;
- el `id` superior, cuando existe, se valida y conserva;
- cuando no existe, se representa como `null`;
- nunca se inventa un identificador de notificación;
- la idempotencia financiera continúa basada en la Order/estado/evidencia
  canónica, no en ese `id` opcional.

<!-- P5.6_ACK_AFTER_IMMEDIATE_ENQUEUE_2026_08_14 -->
## 14. Corrección P5.6 — ACK solamente después del enqueue efectivo

Las pruebas locales con servidor PHP persistente demostraron dos fronteras
insuficientes para el contrato estricto "ACK después de enqueue":

1. `ProcessMercadoPagoPointWebhook::dispatch(...)` crea un `PendingDispatch`;
   el envío real ocurre al destruir ese objeto.
2. `Illuminate\Contracts\Bus\Dispatcher::dispatch($job)` tampoco produjo, en
   el harness HTTP persistente, una fila observable en la database queue antes
   del ACK.

Para este endpoint público la frontera vinculante pasa a ser el backend de
queue directamente.

Decisión vinculante:

- el controller recibe `Illuminate\Contracts\Queue\Factory`;
- obtiene la conexión default mediante `$queues->connection()`;
- construye `ProcessMercadoPagoPointWebhook` con IDs seguros únicamente;
- invoca `$queues->connection()->push($job)` antes de crear HTTP 200;
- para el driver `database`, `Queue::push()` persiste el payload en la tabla
  de jobs mediante `DatabaseQueue`;
- si `connection()` o `push()` falla, no se emite ACK 200;
- esto sólo persiste el job: el worker sigue separado del request;
- sigue prohibido serializar secretos, body crudo, payer data o Access Token.
