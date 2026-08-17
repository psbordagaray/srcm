# ADR 83 — Supplier Invoice Foundation V1

Estado: Aceptada para P9.7a

Checkpoint de partida:
`d96d4891c4dc9e2ab6a33f8b4fa2882d47f72f8b`

Fecha: 2026-08-17

## Contexto

P9 exige completar Cuentas por Pagar sin duplicar la foundation ya existente:

- `PurchaseOrder`;
- `PurchaseReceipt`;
- `PurchaseObligation`;
- `PurchasePaymentRequest`;
- `PurchasePaymentExecution`.

El reconnaissance P9.7 confirmó que orden, recepción, obligación y pago parcial ya existen, mientras que `SupplierInvoice`, anticipos, notas de crédito y 3-way match todavía no existen.

## Decisión

P9.7a incorpora `SupplierInvoice` como hecho documental económico privado, inmutable y separado de la recepción física y del pago.

La cadena queda preparada para:

`PurchaseOrder ↔ PurchaseReceipt ↔ SupplierInvoice`

sin materializar todavía un resultado de 3-way match.

## Contrato

1. Una factura/documento económico se registra sólo contra una orden emitida, parcialmente recibida o recibida.
2. Conserva proveedor, número documental, fecha de emisión, vencimiento opcional, moneda, líneas, logística, total, actor, idempotencia y fingerprint.
3. La moneda deriva de la orden y no puede ser alterada por el cliente HTTP.
4. Una línea puede vincularse a una línea de orden o quedar explícitamente **no vinculada**.
5. Una línea no vinculada conserva la evidencia real del documento y no inventa un `CatalogProduct`.
6. Cantidad o costo diferentes de la orden **se conservan como evidencia**. P9.7b calculará la diferencia; P9.7a no la corrige ni bloquea silenciosamente.
7. Registrar el documento no crea `PurchaseReceipt`, no mueve Inventario, no crea `PurchaseObligation`, no crea solicitud de pago, no ejecuta pago, no crea `CashMovement` y no crea `FinancialExternalMovement`.
8. `SupplierInvoice` y sus líneas son append-only.
9. El mismo idempotency key con igual fingerprint devuelve el mismo hecho; con contenido distinto falla cerrado.
10. La misma identidad documental para el mismo proveedor no puede duplicarse.
11. Operador y Administrador pueden registrar evidencia documental; Viewer permanece read-only.
12. La UI completa de matching/diferencias se incorpora con P9.7b. Esta foundation expone ya el endpoint HTTP estructurado y el dominio necesario.

## Fuera de alcance P9.7a

- 3-way match;
- resolución de diferencias;
- crear o modificar obligaciones desde la factura;
- anticipos a proveedores;
- notas de crédito;
- pago agrupado;
- ejecución no efectivo;
- fiscalidad ARCA;
- OCR/importación automática del documento.

## Próximo corte

P9.7b debe derivar un 3-way match progresivo:

`orden ↔ recepciones confirmadas ↔ SupplierInvoice`

con diferencias de cantidad, costo, logística, líneas no vinculadas y estado exacto/diferente, sin pagar ni corregir por sí mismo.
