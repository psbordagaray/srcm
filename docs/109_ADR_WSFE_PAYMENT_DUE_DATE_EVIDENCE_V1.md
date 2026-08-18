# ADR 109 — WSFE Payment Due Date Evidence V1

Estado: aceptada para el corte posterior a WSFE Currency & Quotation Evidence V1.

## Decisión

SRCM conserva `FchVtoPago` como una evidencia fiscal separada, explícita e inmutable por `FiscalDocument`.

El dato persistido es `payment_due_date`. No se deriva de:

- `CommerceSale`;
- condiciones comerciales de cobro;
- cuentas por cobrar o pagar;
- `PurchaseObligation.due_date`;
- fecha de emisión;
- período de prestación;
- timestamps operativos.

El dominio Purchase puede tener fechas de vencimiento propias. Son una verdad operativa distinta y no son evidencia fiscal WSFE.

## Alcance de este subcorte

Este V1 admite `FchVtoPago` únicamente cuando el concepto fiscal explícito es:

- `services`;
- `products_and_services`.

Para esos conceptos:

1. debe existir previamente `conceptRecord`;
2. el concepto debe conservar `service_period_from` y `service_period_to`;
3. debe existir previamente `issueDateRecord` (`CbteFch`);
4. `payment_due_date` debe ser igual o posterior a `issue_date`;
5. la evidencia debe quedar registrada antes del primer `FiscalAuthorizationAttempt`.

Para `products` este subcorte rechaza el registro. No agrega ni infiere un vencimiento.

## Motivo

El RECON confirmó que SRCM ya posee concepto/período de prestación y `CbteFch` explícitos, pero no una evidencia fiscal dedicada de vencimiento.

La búsqueda genérica del RECON encontró `due_date` en el dominio Purchase. Esos matches no constituyen implementación fiscal de `FchVtoPago` y no deben reutilizarse ni acoplarse.

## ARCA y compatibilidad temporal

La matriz normativa revisada para este corte establece como regla segura ya vigente que `FchVtoPago` es requerido para Concepto 2 (Servicios) y 3 (Productos y Servicios) y que no puede preceder a `CbteFch`.

La documentación actualmente enlazada por ARCA contiene además reglas particulares para Factura de Crédito MiPyME y una revisión con fecha futura. Este V1 no activa anticipadamente esas reglas.

## Inmutabilidad e idempotencia

Una repetición exacta devuelve la misma evidencia.

Una segunda fecha diferente falla cerrada.

El modelo y la base SQLite impiden actualización y eliminación de la evidencia, además de proteger tenant, concepto/período, dependencia de `CbteFch` y orden cronológico.

## Fuera de alcance

No se implementan en este corte:

- Factura de Crédito MiPyME / FCE;
- `CbtesAsoc`;
- `PeriodoAsoc`;
- reglas de cuenta corriente o financiación comercial;
- construcción de payload WSFE;
- sincronización de numeración remota;
- consultas de catálogos;
- WSAA, WSFE, HTTP, secretos, CAE, CAEA o producción.

La fecha fiscal de vencimiento permanece separada de la venta, de Purchase y de la autorización externa.
