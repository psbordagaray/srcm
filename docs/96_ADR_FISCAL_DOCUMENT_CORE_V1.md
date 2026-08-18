# ADR 96 — Fiscal Document Core V1

## Estado

Aceptado para P10.2. No implica autorización ARCA ni emisión legal final.

## Decisión

La venta comercial, el documento fiscal y una futura autorización externa son hechos separados. P10.2 introduce `FiscalDocument` y `FiscalDocumentLine` como evidencia append-only originada exclusivamente en una `CommerceSale` confirmada.

Cada documento guarda snapshots inmutables del emisor (perfil y punto de venta), receptor, moneda, importes y líneas. Por tanto no consulta datos editables para reinterpretar un documento ya creado. El estado se deriva: mientras no exista un hecho de autorización, es `pending`; no se persiste un campo de estado mutable.

## Límites explícitos

P10.2 no asigna numeración fiscal, tipo ARCA A/B/C/M, IVA por alícuota, CAE/CAEA, QR, credenciales, WSAA, adaptadores, HTTP ni autorizaciones. `invoice` es el único tipo que el manager permite crear; crédito y débito son vocabulario reservado para una futura relación postventa explícita.

## Consecuencias

Una venta no recibe campos fiscales ni depende de un comprobante para estar confirmada. Se permite a futuro incorporar intentos y respuestas externas como hechos independientes, sin mutar el documento ni sus snapshots.
