# ADR 98 — Fiscal Document Numbering V1

P10.4 registra la numeración fiscal como hecho append-only separado de la venta y del documento. Es única por punto de venta, ambiente y número; `CommerceSale.sale_number` permanece interno. Este corte no asigna CAE, QR, CAEA ni contacta ARCA.
