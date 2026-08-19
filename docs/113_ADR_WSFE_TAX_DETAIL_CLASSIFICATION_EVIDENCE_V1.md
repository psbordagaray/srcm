# ADR 113 — WSFE Tax Detail Classification Evidence V1

Estado: aceptada para el corte posterior al RECON de composición FECAE.

## Problema

SRCM ya conserva una composición tributaria fiscal inmutable por documento con:

- código tributario interno;
- base imponible;
- alícuota en basis points;
- importe.

Ese registro es verdad fiscal local, pero no alcanza para construir de manera
inequívoca los arrays `Iva` y `Tributos` de WSFE.

Un `tax_code` interno no prueba:

- a qué bucket WSFE pertenece;
- qué `Id` del catálogo ARCA corresponde;
- si una descripción opcional de tributo debe acompañar al componente.

Inferir cualquiera de esas decisiones desde nombres, tasas o convenciones
internas violaría la frontera fiscal explícita de SRCM.

## Contrato WSFE usado

El manual oficial WSFEv1 V4.6, revisión 01/08/2026, separa:

### IVA / AlicIva

- `Id`: código de tipo de IVA, referenciado por `FEParamGetTiposIva`;
- `BaseImp`;
- `Importe`.

### Tributos / Tributo

- `Id`: código de tributo, referenciado por `FEParamGetTiposTributos`;
- `Desc`: descripción opcional, hasta 80 caracteres;
- `BaseImp`;
- `Alic`;
- `Importe`.

Ambos identificadores están documentados en la estructura de request como
enteros de hasta dos dígitos.

Este V1 NO afirma que un Id concreto esté actualmente habilitado en ARCA.
La validez contra los catálogos remotos se comprobará cuando exista la
implementación concreta de los métodos paramétricos y homologación real.

## Decisión

Se agrega evidencia append-only separada de `fiscal_document_taxes`.

Cada componente tributario existente recibe exactamente una identidad WSFE:

- `IVA`, o
- `TRIBUTO`;

junto con:

- `arca_id`;
- `tribute_description` opcional sólo para `TRIBUTO`.

No se modifica ni reescribe `tax_code`, base, tasa ni importe de la composición
tributaria original.

## Atomicidad lógica

El manager exige un set completo en una sola operación:

- todos los componentes del documento;
- exactamente una identidad por componente;
- sin faltantes;
- sin duplicados;
- sin componentes de otro documento u organización.

La base impone unicidad por componente y fronteras tenant/documento. La
completitud del set se revalida explícitamente antes de permitir su uso por el
futuro assembler FECAE.

Si una escritura directa deja un set parcial, el documento queda fail-closed:
no se completa silenciosamente y no puede considerarse listo para componer el
request.

## Idempotencia e inmutabilidad

- el mismo set completo puede repetirse de manera idempotente;
- un segundo set diferente falla;
- una identidad existente no puede actualizarse ni eliminarse;
- no se puede crear evidencia nueva después de un intento de autorización;
- un set ya existente puede revalidarse sin mutación.

## No inferencia

Está prohibido deducir bucket o `arca_id` desde:

- `tax_code`;
- `rate_basis_points`;
- importe;
- tipo de venta;
- producto;
- servicio;
- datos comerciales.

La elección es evidencia fiscal explícita.

## Catálogos ARCA

`FEParamGetTiposIva` y `FEParamGetTiposTributos` son la autoridad remota de
catálogo. En este V1 no se ejecutan.

Por eso:

- se valida sólo la forma estructural del Id;
- no se hardcodea una tabla de IDs "vigentes";
- no se declara validación real contra ARCA.

## Siguiente corte

Con esta identidad cerrada, el siguiente corte seguro es:

`WSFE_FECAE_REQUEST_COMPOSITION_V1`

Ese assembler deberá consumir únicamente evidencias fiscales explícitas,
incluido este set tributario, y producir un DTO puro equivalente a
`FeCabReq + FECAEDetRequest`.

## Fuera de alcance

- WSAA;
- certificados y secretos;
- SOAP/HTTP ARCA;
- consulta real de catálogos;
- CAE;
- persistencia de respuesta;
- producción;
- retries y concurrencia;
- FCE específica;
- Opcionales;
- Compradores;
- Actividades.
