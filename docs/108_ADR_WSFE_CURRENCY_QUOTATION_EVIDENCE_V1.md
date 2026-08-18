# ADR 108 — WSFE Currency & Quotation Evidence V1

Estado: aceptada para el corte posterior a WSFE Monetary Summary Evidence V1.

## Decisión

SRCM conserva por `FiscalDocument` una evidencia separada, explícita e inmutable para la moneda que una futura construcción de payload WSFE mapeará a:

- `MonId`;
- `MonCotiz`;
- `CanMisMonExt`.

La evidencia registra simultáneamente:

- `source_currency_code`: código comercial ya congelado en `FiscalDocument.currency_code`;
- `arca_currency_code`: `MonId` declarado explícitamente, sin inferirlo desde el código comercial;
- `quotation_micros`: `MonCotiz` con escala exacta de 6 decimales;
- `same_currency_settlement`: decisión explícita que una futura construcción mapeará a `CanMisMonExt`.

Ejemplo: `quotation_micros = 1000000` representa exactamente `1.000000`.

## Reglas estáticas de este corte

La moneda fuente declarada debe coincidir exactamente con `FiscalDocument.currency_code`.

`arca_currency_code` debe ser un identificador explícito de tres caracteres. Este corte no asume que un código comercial determinado corresponda silenciosamente a un código del catálogo ARCA.

`quotation_micros` debe ser mayor a cero y no superar `9999.999999`, respetando la precisión `4+6` de WSFE.

Para `MonId = PES`:

- `MonCotiz` debe ser exactamente `1.000000`;
- `CanMisMonExt` no puede ser `S`.

Para moneda distinta de `PES`, la decisión de cancelación en la misma moneda puede ser `S` o `N`, pero la cotización se conserva explícitamente en ambos casos. La validación contra la cotización oficial de ARCA pertenece a un gate dinámico posterior, no a esta persistencia.

## Catálogo y versión ARCA

La documentación de homologación consultada el 18/08/2026 publica WSFEv1 como manual V4.7, mientras el PDF enlazado contiene cabecera/revisiones con fechas futuras. Este ADR usa únicamente reglas de moneda ya incorporadas desde la versión 4.0 de 2025 (`CanMisMonExt`, `MonCotiz` y regla especial `PES=1`) y no activa anticipadamente adecuaciones fechadas para septiembre o diciembre de 2026.

La compatibilidad de `arca_currency_code` con `FEParamGetTiposMonedas` y la validación de `MonCotiz` contra `FEParamGetCotizacion` quedan para el futuro gate de preparación/autorización. Este corte no hace HTTP.

## Inmutabilidad y autorización

Una repetición exacta es idempotente. Una segunda evidencia distinta falla cerrada.

La evidencia conserva organización, actor y momento de registro y debe quedar cerrada antes del primer `FiscalAuthorizationAttempt`.

## Fuera de alcance

No se implementan en este corte:

- consulta remota `FEParamGetTiposMonedas`;
- consulta remota `FEParamGetCotizacion`;
- inferencia `ARS -> PES` ni otras tablas locales de equivalencias;
- `FchVtoPago`;
- `CbtesAsoc` ni `PeriodoAsoc`;
- construcción de payload WSFE;
- sincronización de numeración con `FECompUltimoAutorizado`;
- WSAA, WSFE, HTTP, secretos, CAE, CAEA o producción.

La moneda comercial, la evidencia fiscal de moneda y la autorización externa permanecen como verdades separadas.
