# ADR 125 — ARCA WSAA LoginCms Transport Boundary V1

Estado: aceptado.

Fecha: 2026-08-19.

## Contexto

SRCM ya dispone de TRA explícito, resolución efímera de material X.509,
signer CMS concreto, `WsaaSignedCms`, `WsaaAccessTicket` y mapa separado de
endpoints WSAA/WSFE.

El RECON `ARCA_WSAA_LOGIN_CMS_TRANSPORT_RECON_V1` confirmó sobre el runtime
real: PHP 8.3.30, extensión SOAP ausente, cURL/OpenSSL/DOM disponibles,
Guzzle 7.15.1 y un wire sintético GREEN usando sólo `127.0.0.1`.

No se usó material ARCA real ni se realizó una llamada real `LoginCms`.

## Evidencia oficial vigente

La especificación técnica WSAA publicada por ARCA define:

- SOAP 1.1;
- binding `document`;
- body `literal`;
- operación `loginCms`;
- request `loginCms/in0` `xsd:string`;
- response `loginCmsResponse/loginCmsReturn` `xsd:string`;
- `soapAction=""`;
- CMS Base64 como input;
- `LoginTicketResponse.xml` como retorno;
- errores mediante SOAP Fault.

También indica que `wsaa.*` y `wsn.unavailable` no deben provocar una nueva
solicitud de TA antes de 60 segundos.

La misma especificación documenta SHA1+RSA para la firma CMS. Esta ADR no
resuelve todavía `WsaaCmsDigestPolicy`: la aceptación provider-real continúa
sin validarse en homologación.

Fuentes oficiales de referencia:

- `https://www.arca.gob.ar/ws/documentacion/wsaa.asp`
- `https://www.arca.gob.ar/ws/WSAA/Especificacion_Tecnica_WSAA_1.2.2.pdf`
- `https://www.arca.gob.ar/ws/documentacion/arquitectura-general.asp`

## Decisión

### SOAP 1.1 explícito

`WsaaLoginCmsSoap11Call` construye provider-edge:

- `Content-Type: text/xml; charset=utf-8`;
- `SOAPAction: ""`;
- namespace SOAP 1.1;
- namespace WSAA;
- `loginCms/in0`;
- CMS Base64 sólo dentro del XML.

La llamada no es serializable y su debug redácta el CMS.

### Transporte Guzzle

`WsaaLoginCmsTransport` se enlaza a `GuzzleWsaaLoginCmsTransport`.

El transporte:

- sólo admite homologación;
- usa `OfficialArcaSoapEndpointMap`;
- POST;
- TLS verify activo;
- redirects deshabilitados;
- `http_errors=false`;
- connect/total timeout explícitos;
- response streaming;
- límite máximo SOAP de 1 MiB.

No existe retry automático.

### Parser DOM fail-closed

`WsaaLoginCmsResponseParser` se enlaza a
`DomWsaaLoginCmsResponseParser`.

El parser:

- rechaza vacío, sobredimensión, DOCTYPE y XML inválido;
- usa `LIBXML_NONET`;
- exige SOAP 1.1;
- reconoce SOAP Fault antes del status HTTP;
- exige `loginCmsResponse/loginCmsReturn`;
- parsea el `LoginTicketResponse.xml` interno;
- exige header, credentials, uniqueId, tiempos, Token y Sign;
- devuelve `WsaaAccessTicket` ligado al scope solicitado.

No persiste XML remoto, Token ni Sign.

### Faults sanitizados

`WsaaLoginCmsFaultException` conserva únicamente código ARCA sanitizado y
disposición:

- `wsaa.*` / `wsn.unavailable` →
  `TransientNotBefore60Seconds`;
- resto/desconocido →
  `ActionRequiredNoAutomaticRetry`.

No conserva `faultstring` ni descripción del proveedor.

### Provider completo y producción siguen bloqueados

No se enlaza `WsaaAccessTicketProvider`.

No se enlaza `WsaaCmsDigestPolicy`.

Por lo tanto todavía no existe orquestación automática completa
TRA → material → digest → CMS → LoginCms.

Producción falla antes de tocar el cliente HTTP.

## Invariantes

- Venta ≠ comprobante fiscal ≠ autorización fiscal.
- CMS, Token y Sign son provider-edge y efímeros.
- No hay logging ni serialización de secretos.
- Redirects no se siguen.
- TLS verify no se desactiva.
- XML remoto se trata como no confiable y acotado.
- No retry automático.
- No producción.
- No aceptación real de digest declarada.
- No integración ARCA declarada validada.
- No migración ni cambio de BD.

## Validación requerida

- focal;
- regresión fiscal/WSAA;
- suite completa;
- lint;
- `git diff --check`;
- BD real pre/post exacta;
- ningún HTTP ARCA real;
- commit funcional;
- sync de `docs/README.md`, `docs/06_ROADMAP.md` y
  `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`;
- segundo commit documental;
- verificación remota posterior.

## Próxima frontera

`ARCA_WSAA_ACCESS_TICKET_PROVIDER_RECON_V1`.

Debe reconstruir digest policy, reutilización/cache del TA, concurrencia,
clock, composición TRA → material → signer → transporte y reglas de
fault/retry, manteniendo homologación real y producción todavía separadas.
