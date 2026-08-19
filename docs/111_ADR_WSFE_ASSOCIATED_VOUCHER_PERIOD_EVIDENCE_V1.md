# ADR 111 — WSFE Associated Voucher / Period Evidence V1

Estado: propuesto para validación local controlada.

## Contexto

La fundación de notas de crédito/débito ya permite crear un `FiscalDocument` de ajuste sin `CommerceSale`, pero mantiene bloqueado todo intento de autorización hasta disponer de evidencia fiscal de asociación. El RECON confirmó que SRCM no conserva hoy una identidad remota WSFE suficiente para reconstruir `CbteAsoc`: la numeración local no constituye autoridad remota y la respuesta de autorización existente tampoco registra una identidad remota reutilizable completa.

Por ello la asociación no puede inferirse desde una venta, desde una numeración local ni desde una autorización previa. Debe registrarse como evidencia fiscal explícita e inmutable.

## Decisión

Una nota de crédito/débito tendrá exactamente un modo de asociación:

- `VOUCHERS`: uno o más comprobantes WSFE explícitos, persistidos como una lista JSON canónica e inmutable.
- `PERIOD`: un `PeriodoAsoc` explícito con fecha desde y fecha hasta.

Los modos son excluyentes. No se permite mezclar `CbtesAsoc` y `PeriodoAsoc` en una misma evidencia.

La evidencia se persiste en `fiscal_document_association_evidence`, una relación uno-a-uno con `FiscalDocument`. La lista de vouchers vive dentro de la misma fila para impedir que una inserción posterior agregue asociaciones a una evidencia ya cerrada.

## Evidencia VOUCHERS

Cada comprobante asociado conserva explícitamente:

- `voucher_type_code` → futuro `CbteAsoc.Tipo`.
- `point_of_sale_number` → futuro `CbteAsoc.PtoVta`.
- `voucher_number` → futuro `CbteAsoc.Nro`.
- `issuer_cuit` opcional → futuro `CbteAsoc.Cuit`.
- `voucher_date` opcional → futura fecha del comprobante asociado.

Reglas estáticas de este V1:

- `Tipo`: 1..999.
- `PtoVta`: > 0 y < 99999.
- `Nro`: > 0 y < 99999999.
- CUIT, cuando se informa: exactamente 11 dígitos.
- no se repite una identidad `Tipo/PtoVta/Nro`.
- la lista se ordena canónicamente por esa identidad antes de calcular el fingerprint.

El catálogo remoto de tipos de comprobante y cualquier validación dinámica con ARCA quedan fuera de este corte.

## Evidencia PERIOD

`PERIOD` exige ambas fechas. Se valida que:

- no existan vouchers asociados;
- `period_from_date` sea posterior a 2006-01-01;
- `period_to_date >= period_from_date`;
- exista `FiscalDocumentIssueDate` explícito;
- `period_to_date <= issue_date`.

No se infiere el período desde la venta, los renglones ni el concepto fiscal.

## Inmutabilidad e idempotencia

La evidencia debe existir antes del primer intento de autorización. Una repetición exacta devuelve la fila existente; un segundo contenido distinto falla cerrado. Eloquent rechaza update/delete y SQLite agrega triggers equivalentes. El registro conserva actor y momento.

El fingerprint SHA-256 incluye el `public_id` del documento y la representación canónica completa de la asociación.

## Gate de autorización

La migración elimina el trigger temporal `fiscal_adjustment_authorization_block_insert` y crea `fiscal_adjustment_authorization_association_gate_insert`.

Desde este V1:

- nota sin asociación válida → autorización bloqueada;
- nota con asociación VOUCHERS/PERIOD válida → puede iniciar un intento de autorización fiscal;
- la autorización sigue siendo sólo registro de hechos en este alcance: no se ejecuta ARCA/WSAA/WSFE.

`FiscalAuthorizationFactManager` consulta `FiscalDocumentAssociationManager::assertCompleteForAuthorization()` para repetir el gate semántico en dominio antes de persistir el intento.

## FCE fuera de alcance

Las asociaciones de Factura de Crédito Electrónica tienen reglas adicionales y no se habilitan aquí. Los códigos FCE conocidos `202, 203, 207, 208, 212, 213` permanecen fail-closed tanto en dominio como en el trigger SQLite de autorización cuando una clasificación explícita los identifica.

No se implementan asociaciones múltiples/especiales propias de FCE ni sus validaciones específicas.

## Tenancy y permisos

Sólo un administrador de la organización activa puede registrar asociación. El documento debe pertenecer a la organización activa. SQLite valida también la correspondencia `organization_id` / `fiscal_document_id`.

## Fuera de alcance

- inferencia desde `CommerceSale` o postventa;
- inferencia desde `FiscalDocumentNumberAssignment`;
- inferencia desde `FiscalAuthorizationResponse`;
- consultas ARCA de catálogo o validación remota;
- armado/envío SOAP WSFE;
- asociaciones FCE;
- migración de la BD real;
- commit o push durante la implementación local.

## Consecuencia

La nota deja de tener un bloqueo temporal absoluto y pasa a una frontera de autorización explícita y verificable. SRCM conserva la identidad o el período que posteriormente deberá mapearse al payload WSFE sin inventar origen remoto.
