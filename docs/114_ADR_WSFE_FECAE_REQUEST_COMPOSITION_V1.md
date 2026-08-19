# ADR 114 — WSFE FECAE Request Composition V1

Estado: aceptada para el corte posterior a WSFE Tax Detail Classification Evidence V1.

## Objetivo

Componer por primera vez un request WSFE `FECAESolicitar` de un solo comprobante
a partir exclusivamente de hechos fiscales explícitos e inmutables ya cerrados
en SRCM.

Este V1 no abre red, no autentica y no serializa SOAP. Su producto es un DTO
canónico equivalente a:

- `FeCabReq`;
- un único `FECAEDetRequest`.

## Fuente oficial

Contrato base: manual oficial ARCA WSFEv1 V4.6, revisión 01/08/2026.

El manual documenta:

- `FeCabReq`: `CantReg`, `PtoVta`, `CbteTipo`;
- `FECAEDetRequest`: `Concepto`, receptor, secuencia, fecha, seis importes,
  fechas de servicio/vencimiento, moneda, condición IVA del receptor,
  asociaciones, `Tributos` e `Iva`.

La página general de WS de factura electrónica publica V4.6. La página de
homologación externa puede anunciar una revisión distinta. Este V1 usa sólo la
estructura estable ya documentada y no activa anticipadamente cambios de una
revisión futura o no cerrada en el proyecto.

## Fronteras de verdad

El compositor no recibe una venta ni deriva evidencia comercial.

Fuentes:

- `PtoVta`: `FiscalPointOfSale.point_number`;
- `CbteTipo`: clasificación fiscal explícita;
- `CbteDesde/CbteHasta`: candidato remoto entregado al compositor;
- `Concepto`: `FiscalDocumentConcept`;
- `DocTipo/DocNro/CondicionIVAReceptorId`: evidencia fiscal de receptor;
- `CbteFch`: evidencia de fecha fiscal;
- importes: documento + resumen monetario;
- servicio/vencimiento: concepto + período + `FchVtoPago`;
- moneda: evidencia `MonId/MonCotiz/CanMisMonExt`;
- asociaciones: evidencia `CbtesAsoc` o `PeriodoAsoc`;
- `Iva/Tributos`: composición tributaria + identidad WSFE explícita.

No se consulta ni usa `FiscalDocumentNumber.number`.

## Precisión

El DTO no usa `float` para dinero ni cotizaciones.

- importes minor -> string decimal exacto con 2 decimales;
- `quotation_micros` -> string decimal exacto con 6 decimales;
- basis points de tributo -> porcentaje decimal exacto con 2 decimales.

La futura capa SOAP será responsable de adaptar estos escalares al tipo WSDL sin
reintroducir redondeos binarios en la lógica fiscal.

## IVA

ARCA exige que el `Id` de `AlicIva` no se repita y que se totalice por alícuota.

Por eso el compositor:

- agrupa componentes `IVA` por `arca_id`;
- suma base e importe;
- exige que un mismo `arca_id` no esté asociado internamente a tasas distintas;
- exige que la suma de importes coincida exactamente con `ImpIVA`.

Si `ImpIVA` es cero, el objeto `Iva` se omite.

## Tributos

Cada componente `TRIBUTO` conserva:

- `Id`;
- `Desc` cuando fue declarada;
- `BaseImp`;
- `Alic`;
- `Importe`.

La suma debe coincidir exactamente con `ImpTrib`.

Si `ImpTrib` es cero, el objeto `Tributos` se omite.

La vigencia real de los `Id` contra `FEParamGetTiposIva` y
`FEParamGetTiposTributos` sigue deliberadamente fuera de este V1.

## Concepto y fechas

Mapping:

- Productos -> `Concepto=1`;
- Servicios -> `Concepto=2`;
- Productos y Servicios -> `Concepto=3`.

Los conceptos con servicios requieren las tres fechas explícitas:

- `FchServDesde`;
- `FchServHasta`;
- `FchVtoPago`.

Productos no transporta esas fechas en este corte.

Todas las fechas salen como `YYYYMMDD`.

## Asociaciones

Factura estándar no admite evidencia de asociación de nota.

Notas de crédito/débito deben superar de nuevo
`FiscalDocumentAssociationManager::assertCompleteForAuthorization`.

Se transporta exactamente uno de:

- `CbtesAsoc`;
- `PeriodoAsoc`.

## FCE y regímenes específicos

Los códigos FCE 201/202/203, 206/207/208 y 211/212/213 permanecen fail-closed.

También siguen fuera de alcance las estructuras régimen-específicas:

- `Opcionales`;
- `Compradores`;
- `Actividades`.

No se inventan valores para ellas.

## Serialización

`WsfeFecaeRequestData::toWsfeArray()` expone una forma canónica con nombres de
campo WSFE y un solo detalle.

Esto NO es todavía:

- envelope SOAP;
- `Auth`;
- WSDL binding;
- HTTP;
- endpoint;
- credential handling.

El serializer/transport real será un corte posterior y podrá adaptar la forma
canónica al cliente SOAP concreto sin modificar las reglas del compositor.

## Siguiente frontera

Después de publicar este V1 corresponde relevar y cerrar la frontera:

`WSFE_FECAE_TRANSPORT_SERIALIZATION_BOUNDARY`

incluyendo WSDL, `Auth`, endpoint de homologación, serialización y parseo de
respuesta, todavía sin declarar integración real validada hasta disponer de
credenciales y prueba efectiva.

## Fuera de alcance

- WSAA real;
- certificados/secretos;
- SOAP/HTTP;
- homologación efectiva;
- producción;
- CAE persistence;
- retry/concurrencia;
- validación remota de catálogos;
- FCE;
- Opcionales/Compradores/Actividades.
