# ADR 106 — WSFE Fiscal Voucher Date Evidence V1

Estado: aceptada para el corte posterior a WSFE Recipient Fiscal Evidence V1.

## Decisión

SRCM conserva `CbteFch` como una fecha fiscal explícita, separada e inmutable por `FiscalDocument`.

La fecha fiscal del comprobante no se deriva de `CommerceSale.sold_at`, `CommerceSale.confirmed_at`, `FiscalDocument.documented_at`, `created_at` ni de la fecha de una futura autorización. Debe declararse expresamente y queda cerrada antes del primer `FiscalAuthorizationAttempt`.

Una repetición exacta es idempotente. Una segunda fecha diferente falla cerrada. La evidencia conserva organización, actor y momento de registro, con guardas de tenant e inmutabilidad tanto en modelo como en SQLite.

## Semántica

`issue_date` representa exclusivamente la fecha fiscal que una futura construcción de payload mapeará a `CbteFch`.

Este corte no decide todavía la ventana temporal aceptable por ARCA. Esa validación depende del concepto del comprobante, del momento de solicitud y de las reglas vigentes del servicio externo; por tanto pertenece a un gate de preparación/autorización posterior y no debe alterar retroactivamente la evidencia registrada.

## Fuera de alcance

No se implementan en este corte:

- resumen monetario WSFE (`ImpTotConc`, `ImpNeto`, `ImpOpEx`, `ImpTrib`, `ImpIVA`);
- `MonId`, `MonCotiz` ni `CanMisMonExt`;
- `FchVtoPago`;
- `CbtesAsoc` ni `PeriodoAsoc`;
- sincronización de numeración con `FECompUltimoAutorizado`;
- catálogos remotos ARCA;
- WSAA, WSFE, HTTP, secretos, CAE, CAEA o producción.

La venta comercial, la fecha operativa, el documento fiscal y la autorización fiscal siguen siendo verdades separadas.
