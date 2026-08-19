# ADR 127 — ARCA WSFE FECAE Transport Boundary V1

Estado: Aceptada.

## Contexto

SRCM ya publica:

- composición determinística `WsfeFecaeRequestComposer`;
- contrato provider-edge `WsfeFecaeSoap11Call`;
- normalización `WsfeFecaeProviderResponseNormalizer`;
- convergencia a `FiscalAuthorizationTransportResult`;
- WSAA Access Ticket Provider con cache/lock cifrado.

El Runtime Review V1 confirmó que faltaba la frontera wire de
`FECAESolicitar`: serializer SOAP 1.1, parser XML y transporte HTTP
concreto. También confirmó que la autoridad remota de secuencia y el
binding final del runtime son fronteras separadas.

## Decisión

Se incorpora una frontera wire específica para `FECAESolicitar`.

### Serializer

`WsfeFecaeSoapSerializer` recibe `WsfeFecaeSoap11Call` y produce
headers + XML SOAP 1.1.

`DomWsfeFecaeSoapSerializer`:

- serializa `Auth` y `FeCAEReq`;
- usa el namespace publicado por WSFEv1;
- preserva wrappers y colecciones del mapa canónico;
- escapa valores mediante DOM;
- no persiste Token ni Sign.

### Parser

`WsfeFecaeSoapResponseParser` recibe HTTP status + XML y devuelve
`WsfeFecaeSoapResultData`.

`DomWsfeFecaeSoapResponseParser`:

- rechaza DOCTYPE;
- usa `LIBXML_NONET`;
- exige SOAP 1.1 y `FECAESolicitarResponse`;
- limita respuesta a 2 MiB;
- preserva secciones conocidas y campos desconocidos;
- materializa como listas los elementos repetibles conocidos.

### Transporte

`WsfeFecaeSoapTransport` es la frontera HTTP específica de FECAE.

`GuzzleWsfeFecaeSoapTransport`:

- sólo permite `Homologation`;
- producción falla antes de HTTP;
- redirects deshabilitados;
- TLS verification habilitado;
- `http_errors=false`;
- connect timeout 5 s;
- timeout total 15 s;
- respuesta streaming y acotada;
- no reintenta automáticamente;
- sanitiza fallas de transporte.

## Separación deliberada

Este corte **no** implementa `FiscalAuthorizationTransport`.

Tampoco:

- consulta `FECompUltimoAutorizado`;
- obtiene por sí mismo un Access Ticket;
- resuelve issuer CUIT;
- habilita un perfil fiscal real;
- habilita homologación externa;
- habilita producción.

La integración:

`WsaaAccessTicketProvider`
→ `WsfeFecaeSoap11Call`
→ `WsfeFecaeSoapTransport`
→ normalizer
→ convergence

se enlazará únicamente en
`ARCA_WSFE_AUTHORIZATION_RUNTIME_BINDING_V1`, después de publicar la
autoridad remota de secuencia.

## Seguridad

- Token/Sign continúan encapsulados por `WsfeFecaeSoap11Call`.
- El objeto con secretos sigue sin ser serializable.
- El serializer y transport no registran payloads.
- Los errores de red se sanitizan.
- Los SOAP Fault no exponen el body completo.
- No hay retry automático.
- Producción permanece fail-closed.

## Validación

La prueba focal demuestra:

- bindings de serializer/parser/transport wire;
- XML SOAP exacto para un request sintético;
- round-trip sintético parser → normalizer → convergence;
- endpoint de homologación;
- opciones Guzzle seguras;
- producción bloqueada antes de HTTP;
- rechazo de DOCTYPE/malformed/oversize;
- ausencia de retry automático;
- `FiscalAuthorizationTransport` y
  `FiscalRemoteSequenceAuthority` todavía sin binding.

## Siguiente frontera

`ARCA_WSFE_REMOTE_SEQUENCE_BOUNDARY_V1`.
