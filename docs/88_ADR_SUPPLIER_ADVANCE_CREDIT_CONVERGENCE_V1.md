# ADR 88 — Supplier Advance Credit Convergence V1

Estado: Aceptada para P9.7g

Checkpoint de partida:
`9e9595f7b501668c7b22ebb9d3b20cf7b06d3a97`

Fecha: 2026-08-17

## Contexto

P9.7f incorporó el hecho monetario `SupplierAdvance` con
solicitud, autorización y ejecución segregadas. Ese corte
deliberadamente no reducía ninguna obligación.

P9.7e ya había establecido otra fuente de saldo a favor:
`SupplierCreditNote` aplicada mediante
`SupplierCreditApplication`.

Ambas fuentes son económicamente distintas:

- nota de crédito: documento del proveedor;
- anticipo: dinero ya entregado al proveedor.

Pero ambas pueden extinguir deuda futura cuando existe una
aplicación explícita.

## Decisión

P9.7g agrega `SupplierAdvanceApplication` como hecho
append-only e inmutable.

La lectura convergente queda:

`saldo a favor proveedor =
 notas de crédito + anticipos ejecutados
 - aplicaciones explícitas`

La deuda pendiente queda:

`obligación
 - pagos ejecutados
 - aplicaciones de notas de crédito
 - aplicaciones de anticipos`

No se materializa ningún campo mutable de saldo.

## Reglas

1. Ejecutar un anticipo no lo aplica automáticamente.
2. Sólo Admin puede aplicar anticipo, reutilizando la autoridad
   de reconocimiento/ajuste de obligación de P9.7e.
3. Anticipo y obligación deben pertenecer a la misma
   organización, proveedor y moneda.
4. El beneficiario de la obligación debe ser la identidad
   comercial del proveedor.
5. Un anticipo puede aplicarse parcialmente y a varias
   obligaciones.
6. Una obligación puede recibir aplicaciones de varias fuentes.
7. Nunca puede aplicarse más que el saldo disponible del
   anticipo.
8. Pagos ejecutados + aplicaciones de notas + aplicaciones de
   anticipos nunca pueden superar la obligación.
9. Una obligación con solicitud de pago `pending` o `approved`
   no admite nueva aplicación de anticipo.
10. Una solicitud de pago nueva usa el saldo derivado ya
    reducido por ambas fuentes.
11. Aplicar anticipo no crea `CashMovement`,
    `FinancialExternalMovement` ni
    `PurchasePaymentExecution`.
12. No existe FIFO automático ni neteo silencioso.
13. La aplicación es idempotente, auditable e inmutable.

## Compatibilidad con P9.7e

`SupplierCreditBalanceReader` conserva sus campos agregados
existentes y agrega detalle de anticipos.

`PurchaseObligationBalanceReader` conserva
`supplier_credit_applied_minor` como total convergente y
expone además el desglose por notas y anticipos.

Los managers de solicitud y ejecución de pago ya consumen ese
reader, por lo que el nuevo saldo entra en CxP sin duplicar
lógica.

## MySQL / MariaDB

Los triggers nuevos no realizan agregados sobre
`supplier_advance_applications` desde el INSERT trigger MySQL
de esa misma tabla.

Los topes acumulados de una misma fuente permanecen
transaccionalmente protegidos por manager/model y por el lock
del anticipo. Los cruces entre aplicaciones de anticipo y
aplicaciones de nota se protegen además mediante guards
cross-table.

SQLite mantiene también los topes acumulados en trigger y es
la frontera probada por la suite local.

## Fuera de alcance

- neteo automático;
- selección FIFO automática de fuentes;
- pago agrupado;
- devolución de un anticipo al proveedor;
- ejecución no-cash general de obligaciones;
- fiscalidad ARCA;
- migración de la BD real.

## Próximo corte

P9.7h — Grouped Supplier Payment Foundation.
