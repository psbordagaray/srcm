# ADR 85 — Supplier Credit Note Foundation V1

Estado: Aceptada para P9.7d

Checkpoint de partida:
`1986abe59758f6a3bcc9177b3add0342362703c4`

Fecha: 2026-08-17

## Contexto

P9.7c confirmó que, después de `SupplierInvoice` y del 3-way match derivado, las brechas reales de CxP son:

- anticipos a proveedores;
- notas de crédito;
- pago agrupado.

La foundation vigente de `PurchaseObligation`, `PurchasePaymentRequest` y `PurchasePaymentExecution` ya resuelve obligación, vencimiento, autorización y pago parcial. No debe duplicarse.

## Decisión

P9.7d incorpora `SupplierCreditNote` como **evidencia económica documental inmutable** emitida por el proveedor y vinculada a una `SupplierInvoice` existente.

Este corte registra la fuente de crédito, pero todavía **no la aplica a una obligación**.

## Contrato

1. La nota de crédito pertenece a la misma organización, orden, proveedor y moneda que la factura vinculada.
2. Conserva número documental, fecha, importe, motivo, notas opcionales, actor, idempotencia y fingerprint.
3. La fecha no puede preceder a la factura vinculada.
4. El importe debe ser positivo.
5. La suma de notas de crédito vinculadas a una factura no puede superar el total documental de esa factura.
6. La identidad documental es única por organización + proveedor.
7. El mismo idempotency key con igual fingerprint devuelve el mismo hecho; con otros datos falla cerrado.
8. Operator y Admin pueden registrar la evidencia reutilizando la capacidad documental de Compras; Viewer permanece read-only.
9. `SupplierCreditNote` es append-only.
10. Registrar la nota no modifica `SupplierInvoice`, `PurchaseReceipt`, `PurchaseObligation`, `PurchasePaymentRequest` ni `PurchasePaymentExecution`.
11. Registrar la nota no mueve Inventario, Caja ni cuentas financieras.
12. El cliente HTTP no elige proveedor, orden ni moneda: esos datos derivan de la factura y del route scope.

## Por qué todavía no reduce CxP

Una nota de crédito puede quedar sin aplicar, aplicarse parcialmente o compensar una o varias obligaciones futuras del mismo proveedor. Reducir una deuda por el mero hecho de registrar el documento mezclaría dos verdades:

`crédito disponible del proveedor ≠ aplicación del crédito a una obligación`

P9.7e construirá la convergencia de crédito de proveedor y su aplicación explícita a obligaciones, preparada para sumar después los anticipos sin duplicar un segundo motor de saldo.

## Fuera de alcance P9.7d

- aplicación de crédito a obligaciones;
- saldo de crédito de proveedor;
- anticipos;
- pago agrupado;
- ejecución monetaria;
- movimiento de Caja;
- movimiento financiero externo;
- resolución humana de diferencias del 3-way match;
- fiscalidad ARCA.

## Próximo corte

P9.7e debe introducir un saldo derivado de crédito del proveedor y una aplicación explícita a `PurchaseObligation`, usando `SupplierCreditNote` como primera fuente y dejando el contrato listo para incorporar anticipos como segunda fuente.
