# ADR 116 — WSAA Access Ticket Boundary V1

Estado: aceptada para el corte posterior a WSFE FECAE Transport Input Boundary V1.

## Contexto

SRCM ya entrega al `FiscalAuthorizationTransport`:

- organización;
- ambiente fiscal;
- identidad WSFE externa;
- candidato remoto de `CbteNro`;
- `FeCAEReq` canónico.

El transport todavía no tiene implementación SOAP y no contiene secretos.

La frontera siguiente es autenticación efímera.

## Contrato oficial estable

ARCA documenta que el acceso a los Web Services de Negocio está regulado por
WSAA.

La aplicación solicita un Ticket de Acceso (TA) para un WSN específico. El TA
tiene validez temporal y el cliente extrae de él `Token` y `Sign`, que luego
presenta al WSN junto con los datos de negocio.

Los ambientes de testing y producción de WSAA son distintos.

Este V1 no implementa esa obtención.

## Decisión

Se introducen tres piezas puras:

- `WsaaAccessTicketRequest`;
- `WsaaAccessTicket`;
- `WsaaAccessTicketProvider`.

El request liga de manera explícita:

- `organizationId`;
- `environment`;
- `service`;
- `issuerCuit`.

`service` permanece como identidad explícita suministrada por la futura capa de
configuración. Este V1 no hardcodea silenciosamente el valor del TRA.

El `issuerCuit` liga el Ticket con la identidad que posteriormente deberá
aparecer en `Auth.Cuit`. No se afirma que CUIT sea un elemento XML propio del
TA de WSAA.

## Secreto efímero

`Token` y `Sign`:

- son privados dentro del objeto;
- sólo se exponen mediante métodos explícitos;
- no forman parte de propiedades públicas;
- no pueden serializarse con `serialize()`;
- aparecen redactados en `var_dump()`.

El objeto puede conservar metadatos de scope y vigencia, pero no debe persistirse
como hecho fiscal ni viajar en jobs, modelos o `FiscalAuthorizationTransportRequest`.

## Vigencia y scope

Un Ticket sólo es usable si coincide exactamente en:

- organización;
- ambiente;
- servicio;
- CUIT emisor;

y si el instante de uso está dentro de:

`generationTime <= now < expirationTime`.

No se corrige ni reutiliza silenciosamente un TA de otro scope.

## Relación con certificados

Certificado X.509, clave privada, TRA y firma CMS siguen fuera de este corte.

El futuro proveedor concreto podrá resolver/cachear el TA a partir de material
de credencial encapsulado, sin exponer ese material al dominio.

## Relación con WSFE transport

Este V1 NO agrega `Token`, `Sign` ni `Cuit` a
`FiscalAuthorizationTransportRequest`.

La futura implementación concreta de transport deberá recibir o inyectar un
`WsaaAccessTicketProvider`, solicitar el TA usando el scope de la operación y
construir `Auth` inmediatamente antes de serializar SOAP.

Así los secretos quedan en el borde de proveedor y no en los DTO fiscales
durables.

## Producción

La existencia de este contrato no habilita homologación ni producción.

Sigue siendo obligatorio:

- certificado válido;
- autorización del certificado al WSN;
- implementación CMS/WSAA;
- prueba efectiva de LoginCms;
- validación de ambiente;
- prueba de WSFE real.

## Siguiente frontera

`WSFE_ENVIRONMENT_ENDPOINT_MAP_V1`

Después:

1. serializer/client SOAP;
2. response envelope/normalization;
3. material de credenciales + CMS/WSAA concreto;
4. homologación controlada con credenciales reales.

El orden concreto podrá ajustarse si el próximo RECON demuestra una dependencia
más segura.

## Fuera de alcance

- endpoint WSFE;
- endpoint WSAA dentro de código productivo;
- `SoapClient`;
- HTTP;
- certificado;
- clave privada;
- TRA XML;
- CMS/PKCS#7;
- LoginCms;
- cache persistente de TA;
- CAE;
- producción;
- retries/concurrencia.
