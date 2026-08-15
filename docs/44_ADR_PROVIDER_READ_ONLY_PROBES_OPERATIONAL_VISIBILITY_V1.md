# ADR 44 — Provider Read-Only Probes & Operational Visibility V1

Estado: Aceptada para P5.8.2

Checkpoint de partida:
`ff6fc95b4af8d87b4b23aa757b3734d3bfc429a1`

## 1. Decisión

P5.8.2 agrega probes explícitamente read-only detrás de un contrato
provider-neutral y expone el estado operativo en la superficie existente de
cuentas financieras.

Un probe no es una operación financiera ni un intento de reconciliación.

## 2. Contrato provider-neutral

`FinancialProviderConnectionHealthProbe` declara:

- proveedor;
- capacidad;
- operación de probe;
- resultado normalizado como `FinancialProviderHealthObservation`.

`FinancialProviderHealthProbeRegistry` resuelve un probe sólo cuando existe
una implementación explícita para proveedor + capacidad.

Un proveedor sin probe implementado falla cerrado y no dispara red.

## 3. Primer probe: Mercado Pago / read

La documentación oficial vigente de Mercado Pago recomienda validar el Access
Token mediante un GET autenticado al recurso `/users/me`:

`GET https://api.mercadolibre.com/users/me`

El Access Token viaja exclusivamente en el header `Authorization: Bearer ...`.

P5.8.2 usa ese GET como prueba read-only de identidad/acceso.

No crea órdenes, pagos, reembolsos ni ninguna mutación en Mercado Pago.

Fuente oficial verificada para P5.8.2:
https://www.mercadopago.com.ar/developers/es/docs/checkout-api-orders/resources/credentials

## 4. Reutilización del secret store

El probe reutiliza `MercadoPagoConnectionSecretStore`.

No crea un segundo almacén de credenciales.

Access Token, Webhook Secret y demás credenciales sólo viven de forma
transitoria en memoria durante el request. Nunca forman parte de un health
check, mensaje de UI, log diagnóstico ni resultado persistido.

## 5. Mapeo seguro

El probe persiste exclusivamente códigos estructurados:

- `ok` -> `healthy`
- `authentication_failed` -> `unavailable`
- `rate_limited` -> `degraded`
- `provider_unavailable` -> `unavailable`
- `transport_error` -> `unavailable`
- `invalid_provider_response` -> `degraded`
- `identity_mismatch` -> `unavailable`
- `unexpected_http_status` -> `degraded`
- `credentials_unavailable` -> `unavailable`

Nunca persiste response body, headers, exception message ni texto arbitrario
del proveedor.

Un HTTP 2xx sólo es `healthy` cuando contiene una identidad numérica y esa
identidad coincide con el `userId` seguro de la conexión.

## 6. Binding-aware

`FinancialProviderHealthProbeRunner` entrega el resultado al health manager
P5.8.1.

Por lo tanto el check queda asociado al binding de compatibilidad actual y una
migración de contrato no hereda salud de la versión anterior.

## 7. Visibilidad operacional

La vista de cuentas financieras muestra, cuando existe conexión:

- provider key;
- snapshot/registry actual;
- compatibilidad global;
- compatibilidad de capacidad `read`;
- último health `read`;
- código diagnóstico seguro;
- instante de última verificación;
- decisión actual del automation gate.

Sólo usuarios con `manage-financial-accounts` pueden ejecutar manualmente el
probe.

La visibilidad no muestra ni recibe secretos.

## 8. Degradación localizada

Un probe degradado/no disponible sólo actualiza evidencia de health.

No desactiva automáticamente:

- la cuenta financiera;
- la conexión;
- caja;
- ventas;
- inventario;
- el core.

El automation gate decide si una automatización provider-dependent puede
continuar.

## 9. Seguridad de implementación

Los tests de P5.8.2 utilizan exclusivamente `Http::fake()` y
`Http::preventStrayRequests()`.

El paquete de implementación P5.8.2 no realiza tráfico real a Mercado Pago.

La validación externa, si se autoriza después, será un checkpoint separado,
controlado y read-only.
