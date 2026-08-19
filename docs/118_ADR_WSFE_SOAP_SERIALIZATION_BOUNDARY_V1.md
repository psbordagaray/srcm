# ADR 118 — WSFE SOAP Serialization Boundary V1

Estado: aceptada para el corte posterior a ARCA Environment Endpoint Map V1.

## Contexto

SRCM ya tiene publicados:

- `FeCAEReq` canónico;
- secuencia remota explícita;
- `FiscalAuthorizationTransportRequest` con `FeCAEReq` y sin secretos;
- Ticket WSAA efímero con `Token` y `Sign`;
- mapa oficial de endpoints por ambiente.

Todavía no existe cliente SOAP real.

El RECON previo confirmó además:

- no existe serializer FECAE;
- el resultado actual del transport no preserva CAE, vencimiento, Events ni
  Errors;
- `dom`, `libxml`, `xml`, `xmlreader`, `xmlwriter`, `simplexml` y `openssl`
  están disponibles localmente;
- la extensión PHP `soap` y `SoapClient` NO están disponibles todavía.

## Contrato oficial estable utilizado

El servicio oficial de homologación publica la operación:

`FECAESolicitar`

Para SOAP 1.1 publica:

- namespace de servicio:
  `http://ar.gov.afip.dif.FEV1/`;
- namespace de envelope:
  `http://schemas.xmlsoap.org/soap/envelope/`;
- Content-Type:
  `text/xml; charset=utf-8`;
- SOAPAction:
  `http://ar.gov.afip.dif.FEV1/FECAESolicitar`.

La operación contiene:

- `Auth.Token`;
- `Auth.Sign`;
- `Auth.Cuit`;
- `FeCAEReq`.

La respuesta contiene:

- `FeCabResp`;
- `FeDetResp`;
- uno o más `FECAEDetResponse`;
- `CAE`;
- `CAEFchVto`;
- `Events`;
- `Errors`.

El servicio publica también SOAP 1.2, pero este V1 fija sólo el contrato de
llamada SOAP 1.1. Elegir un protocolo de forma explícita evita que el transporte
real cambie de forma implícita según runtime.

## Discrepancia documental vigente

La página general de ARCA continúa enlazando el manual WSFEv1 V4.6, mientras la
página de homologación externa lista V4.7.

Este V1 usa únicamente la forma estructural estable demostrada por la operación
publicada y por la superficie V4.6 ya usada por SRCM.

No se convierte ninguna diferencia no demostrada de V4.7 en una regla de
dominio.

## Decisión — llamada

Se introduce `WsfeFecaeSoap11Call`.

Es un objeto efímero de borde de proveedor que une:

- `FiscalAuthorizationTransportRequest`;
- `WsaaAccessTicketRequest`;
- `WsaaAccessTicket`;
- instante de uso.

Antes de exponer parámetros:

1. exige organización y ambiente idénticos entre ticket request y transport;
2. valida scope y vigencia del TA;
3. vuelve a comprobar que `PtoVta`, `CbteTipo` y `CbteNro` del `FeCAEReq`
   coincidan con el transport request;
4. exige `CantReg = 1`, coherente con el compositor V1.

`operationParameters()` produce exactamente dos raíces:

- `Auth`;
- `FeCAEReq`.

`Token` y `Sign` sólo se materializan en esa llamada explícita.

## Secreto

`WsfeFecaeSoap11Call`:

- conserva sus dependencias como propiedades privadas;
- no expone Token/Sign como propiedades;
- prohíbe `serialize()`;
- redacta Token/Sign en debug;
- no persiste ni transporta secretos en jobs/modelos.

El `FiscalAuthorizationTransportRequest` permanece sin secretos.

## Decisión — respuesta

Se introduce `WsfeFecaeSoapResultData`.

No decide todavía `AUTHORIZED`, `REJECTED` ni `INDETERMINATE`.

Su responsabilidad es preservar el resultado completo del proveedor antes de
mapearlo al `FiscalAuthorizationTransportResult` grueso.

Expone vistas de:

- `FeCabResp`;
- `FeDetResp`;
- `Events`;
- `Errors`;

pero conserva también todos los campos desconocidos del resultado original.

Esto evita perder:

- `CAE`;
- `CAEFchVto`;
- observaciones;
- nuevos campos compatibles que ARCA pueda agregar.

## Lo que este corte NO hace

No construye XML manual.

No usa:

- `SoapClient`;
- DOM para generar wire XML;
- `XMLWriter`;
- HTTP;
- carga de WSDL;
- endpoints reales;
- certificado;
- clave privada;
- TRA;
- CMS/PKCS#7;
- `LoginCms`;
- Token/Sign reales;
- CAE real.

Tampoco modifica `FiscalAuthorizationTransportResult`.

## Por qué no se genera XML todavía

La representación definitiva del wire debe verificarse contra el WSDL/runtime
real que vaya a utilizarse. Fabricar XML manual antes de tener ese runtime
habilitado introduciría una segunda fuente de verdad y riesgos de namespace,
tipos XSD y wrappers.

Este V1 fija el contrato de operación y parámetros, no una implementación de
red.

## Siguiente frontera

`WSFE_PROVIDER_RESPONSE_NORMALIZATION_V1`

Ese corte podrá mapear el resultado preservado a hechos de autorización sin
perder CAE, vencimiento, observaciones, Events ni Errors.

Luego:

1. material de credenciales + CMS/WSAA concreto;
2. habilitación controlada de PHP SOAP;
3. transport SOAP concreto;
4. homologación real con credenciales válidas.

## Fuera de alcance

- producción habilitada;
- retry/concurrency;
- persistencia de CAE;
- diagnóstico de códigos reales;
- validación real contra WSDL;
- cualquier afirmación de integración ARCA validada.
