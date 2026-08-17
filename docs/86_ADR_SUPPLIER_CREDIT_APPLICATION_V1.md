# ADR 86 — Supplier Credit Application V1

Estado: Aceptada para P9.7e

Checkpoint de partida:
`28e0a4402a66bed20c5be9652d002c86caf4c6cd`

Fecha: 2026-08-17

## Contexto

P9.7d incorporó `SupplierCreditNote` como evidencia
documental inmutable. Quedó deliberadamente separado:

`crédito emitido por proveedor ≠ crédito aplicado a deuda`

P9.7e incorpora la imputación explícita sin inventar un pago.

## Decisión

Se incorpora `SupplierCreditApplication` como hecho
append-only entre una `SupplierCreditNote` y una
`PurchaseObligation`.

Saldo de obligación:

`obligación - pagos ejecutados - crédito aplicado`

Saldo de crédito del proveedor:

`notas de crédito - aplicaciones`

No existe campo mutable de saldo.

## Reglas

1. Registrar nota documental sigue habilitado para Operator.
2. Aplicar crédito reutiliza autoridad Admin de obligaciones.
3. Nota y obligación deben ser de la misma organización,
   proveedor y moneda.
4. El beneficiario de la obligación debe ser la propia
   identidad comercial del proveedor.
5. Una nota puede aplicarse parcialmente y a varias
   obligaciones.
6. Una obligación puede recibir varias aplicaciones.
7. Nunca puede aplicarse más que el saldo de la nota.
8. Pagos ejecutados + crédito aplicado nunca pueden superar
   la obligación.
9. Una obligación con solicitud de pago `pending` o
   `approved` no admite nueva aplicación de crédito.
10. Solicitudes de pago nuevas calculan su máximo contra el
    saldo derivado ya reducido por crédito.
11. Aplicar crédito no crea `PurchasePaymentExecution`,
    `CashMovement` ni `FinancialExternalMovement`.
12. No existe neteo automático ni aplicación silenciosa.
13. Las aplicaciones son inmutables, idempotentes y
    auditables.
14. Puede aplicarse crédito entre órdenes distintas siempre
    que proveedor, beneficiario y moneda coincidan.

## MySQL / MariaDB

El trigger MySQL/MariaDB no ejecuta un agregado sobre
`supplier_credit_applications` desde el INSERT trigger de la
misma tabla. Los topes acumulados permanecen protegidos por
manager/model dentro de transacción hasta un hardening
aislado que valide una estrategia portable.

SQLite sí mantiene los topes acumulados también en trigger y
es la frontera probada por la suite local.

## Fuera de alcance

- anticipos a proveedor;
- pago agrupado;
- devolución monetaria de crédito;
- compensación automática;
- fiscalidad ARCA;
- migración de la BD real.

## Próximo corte

P9.7f: Supplier Advance como segunda fuente del saldo a favor
del proveedor, reutilizando esta misma aplicación explícita.
