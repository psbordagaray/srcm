# ADR 112 — WSFE Remote Sequence Authority Boundary V1

Estado: aceptada para el corte posterior a WSFE Associated Voucher / Period Evidence V1.

## Problema

SRCM ya posee `FiscalDocumentNumber` como hecho fiscal local, pero su asignación se
obtiene mediante `MAX(number) + 1` sobre la base local, particionada por el ID
interno del punto de venta y el ambiente.

Ese hecho local no constituye autoridad de secuencia de WSFE:

- no está particionado por `CbteTipo`;
- utiliza `fiscal_point_of_sale_id`, que es un identificador interno de SRCM;
- el contrato de ARCA opera sobre el número externo `PtoVta`;
- el adapter publicado estaba enviando la numeración local como número de
  comprobante hacia el boundary externo.

ARCA expone `FECompUltimoAutorizado`, cuya identidad de consulta es
`PtoVta + CbteTipo` y cuya respuesta contiene el último `CbteNro` registrado
para esa combinación.

## Decisión

Se introduce un boundary read-only explícito:

- `FiscalRemoteSequenceAuthority`;
- `FiscalRemoteSequenceQuery`;
- `FiscalRemoteSequenceState`.

La consulta está identificada por:

- organización;
- ambiente fiscal;
- `PtoVta` externo (`FiscalPointOfSale.point_number`);
- `CbteTipo` WSFE explícito (`FiscalDocumentClassification.voucher_code`).

La autoridad devuelve:

- ambiente;
- `PtoVta`;
- `CbteTipo`;
- último número autorizado.

La respuesta debe coincidir exactamente con la identidad consultada.

## Número a solicitar

`ArcaFiscalAuthorizationAdapter` deriva el candidato WSFE exclusivamente como:

`lastAuthorizedNumber + 1`

El último número remoto aceptado para derivar otro debe estar entre `0` y
`99.999.998`, por lo que el candidato queda entre `1` y `99.999.999`.

El valor cero es una representación de boundary para una secuencia sin
comprobantes previos. La futura implementación concreta de ARCA será responsable
de normalizar la respuesta real del proveedor a este contrato.

## Numeración local

`FiscalDocumentNumber` y `FiscalDocumentNumberManager` se conservan sin cambios.
Siguen siendo evidencia interna append-only de SRCM.

Queda prohibido usar:

- `FiscalDocumentNumber.number` como `CbteNro` WSFE;
- `fiscal_point_of_sale_id` como `PtoVta`.

El request de autorización externo deja de transportar `assignedNumber` y
`fiscalPointOfSaleId`. Transporta explícitamente:

- ambiente;
- `pointOfSaleNumber`;
- `voucherTypeCode`;
- `voucherNumber`.

## Precondiciones de autorización

Antes de consultar la secuencia remota, el adapter exige:

- punto fiscal;
- ambiente fiscal válido;
- `point_number` externo entre 1 y 99998;
- clasificación fiscal;
- `voucher_code` numérico entre 1 y 999;
- credenciales externas configuradas.

Este corte no altera los gates de completitud fiscal ya existentes.

## Concurrencia

Este V1 separa correctamente la autoridad remota de la numeración local, pero no
pretende reservar números de manera distribuida.

La futura ejecución real deberá definir el tratamiento de carreras entre
`FECompUltimoAutorizado` y `FECAESolicitar`, incluyendo rechazo, reconsulta,
idempotencia y retry cuando corresponda.

No se inventa una reserva local como sustituto de la autoridad de ARCA.

## Fuera de alcance

No se implementan:

- SOAP/HTTP real hacia ARCA;
- llamada concreta a `FECompUltimoAutorizado`;
- WSAA, certificados o secretos;
- payload completo de `FECAESolicitar`;
- persistencia de CAE;
- retries;
- reserva distribuida/concurrencia;
- producción;
- FCE específica;
- cambios de esquema o migraciones.

La revisión futura del manual enlazado por ARCA no se activa anticipadamente.
Este V1 depende únicamente del contrato histórico de `FECompUltimoAutorizado`.
