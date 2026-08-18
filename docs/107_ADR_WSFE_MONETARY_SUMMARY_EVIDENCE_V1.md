# ADR 107 — WSFE Monetary Summary Evidence V1

Estado: aceptada para el corte posterior a WSFE Fiscal Voucher Date Evidence V1.

## Decisión

SRCM conserva como evidencia fiscal separada, explícita e inmutable los cinco importes del resumen monetario necesarios para una futura solicitud WSFE:

- `non_taxed_amount_minor` → `ImpTotConc`;
- `net_taxable_amount_minor` → `ImpNeto`;
- `exempt_amount_minor` → `ImpOpEx`;
- `tributes_amount_minor` → `ImpTrib`;
- `vat_amount_minor` → `ImpIVA`.

`FiscalDocument.total_minor` continúa siendo la verdad inmutable ya existente que una futura construcción de payload mapeará a `ImpTotal`; este corte no duplica ese total en otra columna.

## Consistencia

Los cinco importes deben ser explícitos y no negativos. Su suma exacta debe coincidir con `FiscalDocument.total_minor`.

El resumen requiere que la composición tributaria explícita del documento ya exista. La suma de `tax_amount_minor` de esa composición debe coincidir con `tributes_amount_minor + vat_amount_minor`.

La composición tributaria se usa únicamente como control de consistencia. SRCM no decide qué componente es IVA o tributo, no infiere alícuotas y no reconstruye el resumen a partir de la venta.

Una repetición exacta es idempotente. Una segunda composición monetaria diferente falla cerrada. La evidencia conserva organización, actor y momento de registro y queda cerrada antes del primer `FiscalAuthorizationAttempt`.

## Fuera de alcance

No se implementan en este corte:

- `MonId`, `MonCotiz` ni `CanMisMonExt`;
- `FchVtoPago`;
- `CbtesAsoc` ni `PeriodoAsoc`;
- construcción de payload WSFE;
- catálogos remotos ARCA;
- sincronización de numeración con `FECompUltimoAutorizado`;
- WSAA, WSFE, HTTP, secretos, CAE, CAEA o producción.

La venta comercial, los importes del documento, la composición tributaria, el resumen WSFE y la autorización fiscal continúan siendo verdades separadas.
