# ADR 117 — ARCA Environment Endpoint Map V1

Estado: aceptada para el corte posterior a WSAA Access Ticket Boundary V1.

## Contexto

SRCM ya distingue `FiscalEnvironment::Homologation` y
`FiscalEnvironment::Production`, compone FECAE, obtiene secuencia remota,
mantiene el transport sin secretos y tiene un contrato efímero de Ticket WSAA.

Todavía no existe cliente SOAP ni acceso HTTP real.

## Evidencia oficial

Fuentes oficiales ARCA consultadas al preparar este corte:

WSAA testing:
`https://wsaahomo.afip.gov.ar/ws/services/LoginCms`

WSAA producción:
`https://wsaa.afip.gov.ar/ws/services/LoginCms`

WSFEv1 homologación:
`https://wswhomo.afip.gov.ar/wsfev1/service.asmx`

WSDL homologación:
`https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL`

WSFEv1 producción:
`https://servicios1.afip.gov.ar/wsfev1/service.asmx`

WSDL producción:
`https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL`

La presencia de estas URLs en código NO constituye validación de conectividad ni
habilitación de producción.

## Decisión

Se introducen:

- `ArcaSoapEndpointSet`;
- `ArcaSoapEndpointMap`;
- `OfficialArcaSoapEndpointMap`.

El mapa resuelve exclusivamente por `FiscalEnvironment`.

Cada set contiene URL WSAA LoginCms, URL base WSFE y URL WSDL WSFE derivada
determinísticamente como `base + ?WSDL`.

Todos los endpoints deben ser HTTPS válidos, con host, sin credenciales ni
fragmentos. La URL base WSFE no admite query string.

## Separación de identidad WSAA

Este mapa NO define el valor de `service` usado en TRA/TA.
`WsaaAccessTicketRequest::service` sigue siendo identidad explícita y separada
de la URL física. No se infiere desde host, path ni WSDL.

## Producción

El mapa conoce la URL oficial de producción porque forma parte del contrato de
infraestructura, pero NO habilita producción. El hard-block existente permanece
intacto.

## Fuera de alcance

- `SoapClient`;
- carga WSDL;
- HTTP;
- TLS probe;
- certificado;
- clave privada;
- TRA;
- CMS/PKCS#7;
- LoginCms real;
- Token/Sign reales;
- FECAESolicitar real;
- parsing de response;
- CAE;
- retries;
- producción habilitada.

## Siguiente frontera

`WSFE_SOAP_SERIALIZATION_BOUNDARY_V1`.
