# ADR 105 — WSFE Recipient Fiscal Evidence V1

Estado: aceptada para el corte posterior al `P10 — Fiscal Payload Completeness RECON`.

## Decisión

SRCM conserva como evidencia fiscal separada, explícita e inmutable del receptor:

- código de tipo de documento fiscal (`DocTipo`);
- número de documento fiscal (`DocNro`);
- código de condición IVA del receptor (`CondicionIVAReceptorId`).

La evidencia pertenece a un único `FiscalDocument`, conserva tenant y actor, y no puede reescribirse ni eliminarse. Una repetición exacta es idempotente; una repetición con valores distintos falla cerrada.

## Frontera

Este corte no convierte `CommerceSale.customer_document_snapshot` en autoridad fiscal. El número comercial puede servir como evidencia operativa, pero el `DocTipo`, `DocNro` y la condición IVA deben declararse explícitamente para el documento fiscal.

Cuando existe un `FiscalBusinessPartyProfile` para la contraparte vinculada a la venta, los valores explícitos deben ser consistentes con su `tax_id` y `vat_condition_code`. El perfil se usa como control de consistencia, no como inferencia silenciosa.

La evidencia debe cerrarse antes del primer `FiscalAuthorizationAttempt`. Una autorización ya intentada no puede recibir retrospectivamente otra identidad fiscal del receptor.

## Fuera de alcance

No se implementan en este corte:

- `CbteFch` ni semántica de fecha fiscal;
- resumen monetario WSFE (`ImpTotConc`, `ImpNeto`, `ImpOpEx`, `ImpTrib`, `ImpIVA`);
- `MonId`, `MonCotiz` ni `CanMisMonExt`;
- `FchVtoPago`;
- `CbtesAsoc` ni `PeriodoAsoc`;
- catálogos remotos ARCA;
- WSAA, WSFE, HTTP, secretos, CAE, CAEA o producción.

La venta comercial, el documento fiscal y la autorización fiscal continúan siendo verdades separadas.
