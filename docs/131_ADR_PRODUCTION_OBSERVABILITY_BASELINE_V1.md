# ADR 131 — Production Observability Baseline V1

Estado: **ACEPTADA**
Fecha: **2026-08-19**
Base: `1c1811ffe32fd18d33107fb537b41176385c8307`
Corte: `P11_PRODUCTION_OBSERVABILITY_BASELINE_V1`

## Contexto

Production Security Baseline V1 quedó publicado y el RECON focal de
observabilidad clasificó como parciales request/correlation context, logging
estructurado, health/readiness, visibilidad de queue/jobs, errores de
integración y alerting. Métricas y tracing permanecen ausentes.

El primer corte no debe introducir OpenTelemetry, Prometheus, Horizon,
Telescope ni un proveedor externo de alertas antes de consolidar una base
operativa nativa y verificable.

## Decisión

### 1. Request ID y correlation ID globales

Cada request recibe un `request_id` UUID generado por SRCM. Un
`X-Correlation-ID` entrante sólo se conserva si es UUID válido; de lo contrario
el `correlation_id` cae al `request_id` local.

Ambos valores se guardan como atributos del request, se comparten con el
contexto de logging y vuelven como `X-Request-ID` / `X-Correlation-ID`. El
middleware pasa a ser global, por lo que cubre web, API y health.

`x-request-id` de Mercado Pago conserva su significado provider-specific para
la validación de la notificación y no se reutiliza como correlación interna.

### 2. Logging JSON de producción

Se agrega el canal `stderr_json` sobre Monolog `StreamHandler` +
`JsonFormatter`, sin paquete externo. Desarrollo puede conservar `stack/single`
y Pail; producción debe desplegar `LOG_CHANNEL=stderr_json` y `LOG_LEVEL=info`
o incluir `stderr_json` dentro de su stack.

El readiness check considera no-ready una producción que no usa el canal JSON
aceptado. No se registran secretos, bodies ni mensajes crudos de excepciones en
los eventos operativos agregados por este corte.

### 3. Excepciones, jobs e integraciones

Laravel conserva su reporte normal de excepciones, ahora enriquecido con
`request_id` y `correlation_id` cuando existe un request HTTP.

Un provider dedicado de observabilidad registra hooks del queue manager y emite señales estructuradas para excepción de intento y fallo
final con sólo conexión, cola, clase del job, intento y clase de excepción. El
contexto se limpia antes y después de cada job para evitar contaminación entre
ejecuciones de workers largos.

El webhook Mercado Pago emite eventos seguros de rechazo/encolado. Su job
transporta únicamente un `correlation_id` UUID opcional adicional a los tres
identificadores mínimos ya existentes; nunca serializa access token, webhook
secret, body crudo ni material fiscal.

### 4. Readiness

`GET /api/health/ready` complementa `/up`.

- `/up` permanece como liveness del framework;
- `/api/health/ready` verifica acceso DB, backend de cola, almacenamiento
  `failed_jobs` y logging estructurado requerido en producción;
- la respuesta sólo publica `ok/fail`, sin cantidades, nombres internos,
  excepciones ni configuración sensible;
- cualquier chequeo fallido responde HTTP 503.

Este endpoint no afirma que un worker externo esté vivo; sólo valida que el
backend configurado sea operativo desde SRCM. Supervisión del proceso worker y
alerting externo pertenecen al deployment/resilience posterior.

## Fuera de alcance

- OpenTelemetry;
- backend de métricas externo;
- tracing distribuido;
- alert provider externo;
- Horizon / Telescope;
- dashboards;
- backup automation y restore drills;
- outbox genérico;
- homologación ARCA real / WSASS.

## Criterio de aceptación

- request/correlation IDs cubren web + API + health y no aceptan identificadores
  entrantes arbitrarios;
- excepción HTTP conserva contexto correlacionable;
- `stderr_json` existe y el readiness de producción lo exige;
- queue exceptions/failures emiten eventos seguros y limpian contexto;
- webhook → job preserva `correlation_id` sin ampliar el payload sensible;
- `/api/health/ready` distingue ready/not-ready sin filtrar detalles;
- focal, regresión de webhook/security/health y suite completa GREEN;
- BD real autoritativa intacta;
- commit/push exactos y repo limpio.
