# ADR 115 — WSFE FECAE Transport Input Boundary V1

Estado: aceptada para el corte posterior al RECON de Transport Serialization Readiness.

## Contexto

SRCM ya posee dos fronteras separadas y publicadas:

1. autoridad remota de secuencia WSFE, que deriva el próximo `CbteNro` desde
   `PtoVta + CbteTipo`;
2. compositor canónico de `FeCAEReq`, que construye `FeCabReq` y un
   `FECAEDetRequest` exclusivamente desde evidencia fiscal explícita.

El RECON posterior confirmó que ambas piezas todavía no estaban conectadas:

- `ArcaFiscalAuthorizationAdapter` no invocaba el compositor;
- `FiscalAuthorizationTransportRequest` no transportaba `FeCAEReq`;
- el transport seguía siendo un contrato, sin implementación SOAP;
- no existe todavía Ticket de Acceso WSAA ni respuesta rica de proveedor.

## Contrato oficial relevante

La operación oficial `FECAESolicitar` recibe:

- `Auth` con `Token`, `Sign` y `Cuit`;
- `FeCAEReq`, que contiene `FeCabReq` y `FeDetReq/FECAEDetRequest`.

Este V1 cierra únicamente el segundo punto.

No agrega `Auth`, no obtiene Ticket de Acceso y no serializa SOAP.

## Decisión

Se introduce `WsfeFecaeRequestComposerContract` como contrato puro de
composición.

`WsfeFecaeRequestComposer` implementa ese contrato sin cambiar sus reglas de
negocio.

`ArcaFiscalAuthorizationAdapter`:

1. valida identidad fiscal externa;
2. verifica readiness de credenciales;
3. consulta la autoridad remota de secuencia;
4. deriva el próximo `CbteNro`;
5. compone el `FeCAEReq` canónico con ese número;
6. verifica que el payload compuesto repita exactamente:
   - `CantReg=1`;
   - `PtoVta`;
   - `CbteTipo`;
   - `CbteDesde=CbteHasta=CbteNro` remoto;
7. sólo entonces entrega el request al transport.

## Transport input

`FiscalAuthorizationTransportRequest` pasa a contener:

- `organizationId`;
- `fiscalDocumentId`;
- `environment`;
- `pointOfSaleNumber`;
- `voucherTypeCode`;
- `voucherNumber`;
- `fecaeRequest`.

La duplicación de identidad externa es deliberada: permite al transport y a
los siguientes adapters validar que el contexto de autenticación, endpoint,
secuencia y payload pertenecen a la misma operación.

## Prohibiciones

Este boundary no transporta ni debe transportar:

- `FiscalDocumentNumber.number`;
- id interno de punto de venta como `PtoVta`;
- Access Token WSAA;
- `Sign`;
- certificado;
- clave privada;
- endpoint;
- WSDL;
- envelope SOAP;
- respuesta cruda.

La numeración local continúa siendo evidencia interna y nunca autoridad de
`CbteNro`.

## Fail-closed

Si el compositor devuelve un `FeCAEReq` cuya cabecera o numeración no coincide
con la identidad/secuencia determinada por el adapter, el transport no es
invocado.

No se corrige silenciosamente el payload.

## Relación con WSAA

`FiscalAuthorizationCredentialStore::configuredFor()` continúa siendo sólo un
gate de readiness en este corte.

No representa el Ticket de Acceso.

La siguiente frontera deberá introducir un contrato efímero de autenticación
WSAA para `Token + Sign + Cuit`, separado de certificados/clave privada y
scopeado por organización, ambiente y servicio.

## Runtime local

El RECON detectó PHP `openssl` disponible y extensión PHP `soap` ausente.

Eso no bloquea este V1, porque no existe cliente SOAP todavía. Sí deberá
resolverse antes del corte que instancie un cliente SOAP concreto si esa fuera
la implementación elegida.

## Siguiente corte

`WSFE_WSAA_ACCESS_TICKET_BOUNDARY_V1`

Después:

1. environment endpoint map;
2. SOAP serializer/client;
3. response envelope/normalization;
4. homologación controlada con credenciales reales.

## Fuera de alcance

- firma CMS;
- WSAA real;
- certificados y claves;
- `Token/Sign` reales;
- WSDL;
- SOAP/HTTP;
- endpoint selection;
- respuesta `CAE/CAEFchVto/Errors/Events`;
- persistencia CAE;
- producción;
- retries/concurrencia;
- FCE y estructuras régimen-específicas.
