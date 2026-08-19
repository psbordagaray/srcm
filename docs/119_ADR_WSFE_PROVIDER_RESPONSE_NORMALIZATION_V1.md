# ADR 119 — WSFE Provider Response Normalization V1

Estado: aceptada para el corte posterior a WSFE SOAP Serialization Boundary V1.

## Contexto

SRCM ya preserva el resultado completo de `FECAESolicitar` mediante
`WsfeFecaeSoapResultData`, pero `FiscalAuthorizationTransportResult` continúa
siendo deliberadamente grueso: outcome + resultCode.

Antes de conectar un transport SOAP real es necesario fijar una normalización
provider-specific que no pierda evidencia y que no convierta códigos de error u
observación en reglas de dominio inventadas.

## Evidencia oficial estable

El manual WSFEv1 V4.6 publicado por ARCA documenta en respuestas de
`FECAESolicitar`:

- `FeCabResp.Resultado`;
- `FeDetResp/FECAEDetResponse.Resultado`;
- `CAE`;
- `CAEFchVto`;
- observaciones;
- `Events`;
- `Errors`.

El manual define para `Resultado`:

- `A` = aprobado;
- `R` = rechazado;
- `P` = parcial en la cabecera.

También documenta que un comprobante aprobado recibe CAE y fecha de vencimiento,
incluso cuando existen observaciones no excluyentes; una validación excluyente
rechaza la solicitud.

La superficie pública del servicio de homologación confirma la presencia de
`CAE`, `CAEFchVto`, `Events` y `Errors` en `FECAESolicitarResult`.

La página general de ARCA sigue publicando manual V4.6 y homologación externa
lista V4.7. Este V1 no incorpora ninguna diferencia V4.7 no demostrada.

## Alcance V1

SRCM compone actualmente `CantReg=1`. Por eso este normalizador acepta como
máximo un `FECAEDetResponse` efectivo.

Se introducen:

- `WsfeFecaeProviderResponseNormalizerContract`;
- `WsfeFecaeProviderResponseNormalizer`;
- `WsfeFecaeNormalizedResponseData`.

## Regla de outcome

La normalización usa únicamente evidencia explícita del proveedor:

### Authorized

Sólo si simultáneamente:

- cabecera `Resultado = A`;
- detalle `Resultado = A`;
- CAE no vacío y numérico;
- `CAEFchVto` es una fecha `YYYYMMDD` válida.

Las observaciones no invalidan por sí mismas una aprobación, porque ARCA
documenta aprobación con observaciones no excluyentes.

### Rejected

Sólo si simultáneamente:

- cabecera `Resultado = R`;
- detalle `Resultado = R`;
- no hay CAE.

### Unknown

Todo estado incompleto, contradictorio o no demostrado queda `Unknown`:

- cabecera `P` en esta frontera de un solo registro;
- códigos nuevos/desconocidos;
- A sin CAE;
- A sin vencimiento válido;
- mezcla A/R;
- R con CAE;
- respuesta que sólo trae `Errors`;
- ausencia de cabecera o detalle suficiente.

No se interpreta el significado de ningún `Err.Code`, `Evt.Code` u
`Obs.Code`.

## Preservación

La DTO normalizada conserva:

- resultado de cabecera;
- resultado de detalle;
- CAE;
- vencimiento;
- observaciones;
- Events;
- Errors;
- resultado provider completo, incluyendo campos futuros desconocidos.

Normalizar no significa descartar el payload original.

## No convergencia prematura

Este V1 NO modifica `FiscalAuthorizationTransportResult`.

El próximo corte decidirá cómo la normalización provider-specific converge con
el resultado provider-neutral y con los hechos de autorización, sin perder CAE,
vencimiento ni evidencia.

## Fuera de alcance

- persistir CAE;
- persistir vencimiento;
- interpretar códigos de errores/observaciones;
- `SoapClient`;
- XML wire;
- WSDL;
- HTTP;
- certificado;
- CMS;
- LoginCms;
- producción;
- homologación real.

## Continuidad documental

Después de publicar y verificar este cambio funcional, los tres documentos
maestros deben sincronizarse y publicarse antes de abrir cualquier siguiente
frontera.

## Siguiente frontera

Después del checkpoint funcional y del sync obligatorio de los tres maestros:

`WSFE_PROVIDER_RESULT_CONVERGENCE_V1`
