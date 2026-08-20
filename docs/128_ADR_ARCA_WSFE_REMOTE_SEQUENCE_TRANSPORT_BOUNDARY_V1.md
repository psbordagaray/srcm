# ADR 128 — ARCA WSFE Remote Sequence Transport Boundary V1

Estado: aceptada.

## Contexto

ADR 112 separó la numeración local de la autoridad remota y fijó que el próximo
`CbteNro` se deriva exclusivamente de `FECompUltimoAutorizado + 1`. El corte
anterior publicó el wire de `FECAESolicitar`, pero `FECompUltimoAutorizado`
continuaba sin implementación SOAP concreta.

## Contrato oficial utilizado

`FECompUltimoAutorizado` recibe `Auth(Token, Sign, Cuit)`, `PtoVta` y
`CbteTipo`. Su respuesta devuelve `PtoVta`, `CbteTipo`, `CbteNro` y puede
incluir `Errors` y `Events`.

La identidad de respuesta debe coincidir exactamente con la consulta. El valor
boundary `0` representa una secuencia sin comprobantes previos y permite derivar
el primer comprobante como `1`.

## Decisión

Se publica una frontera wire separada:

- `WsfeCompUltimoAutorizadoSoap11Call`;
- serializer DOM explícito;
- parser DOM fail-closed;
- transporte Guzzle homologación-only.

El call valida scope WSAA, servicio `wsfe`, vigencia del TA y rangos
`PtoVta=1..99998`, `CbteTipo=1..999`. Token y Sign sólo se exponen en el borde
provider, quedan redactados en debug y el objeto no es serializable.

El parser:

- exige SOAP 1.1 y namespace WSFE;
- usa `LIBXML_NONET` y rechaza DOCTYPE;
- limita la respuesta a 1 MiB;
- rechaza errores provider sin exponer `Msg`;
- valida `Events` estructuralmente sin interpretarlos;
- exige identidad remota exacta;
- acepta `CbteNro` entre 0 y 99.999.999;
- normaliza ausencia de `CbteNro`, sin `Errors`, a `0` en este boundary.

El transporte replica los controles ya publicados para FECAE: TLS verify,
redirects deshabilitados, `http_errors=false`, timeouts 5/15 s, streaming
acotado y cero retry automático.

## Deliberadamente no enlazado todavía

Este corte **no** enlaza `FiscalRemoteSequenceAuthority`. La consulta neutral no
contiene `issuer_cuit` ni `service`, y esa identidad no debe inferirse dentro del
wire. El siguiente corte de runtime resolverá identidad/credential readiness y
compondrá `WsaaAccessTicketProvider + FECompUltimoAutorizado + FECAESolicitar`.

Tampoco se enlaza `FiscalAuthorizationTransport`.

## Seguridad y alcance

- producción falla antes de HTTP;
- no se usa CUIT/certificado/clave real;
- no se ejecuta WSASS, CMS, LoginCms ni ARCA real;
- no hay migrate ni cambio de schema;
- no hay reserva distribuida ni retry de carreras;
- homologación externa real permanece diferida.
