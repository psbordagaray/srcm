# ADR 87 — Supplier Advance Foundation V1

Estado: Aceptada para P9.7f

Checkpoint de partida:
`6652b6d8b23fcda1402367eb8162347d142fe6ce`

Fecha: 2026-08-17

## Contexto

P9.7c confirmó tres brechas de CxP: anticipos a proveedor,
pago agrupado y ejecución no-cash general. P9.7e ya separó
correctamente crédito documentado y aplicación a deuda.

Un anticipo de proveedor es otra verdad distinta:

`dinero efectivamente entregado antes de una obligación`

No es una factura, una recepción, una obligación ni una
aplicación automática de crédito.

## Decisión

P9.7f incorpora una cadena segregada y append-only:

`SupplierAdvanceRequest`
→ `SupplierAdvanceDecision`
→ `SupplierAdvance`

La solicitud y la decisión no mueven dinero. Sólo
`SupplierAdvance` representa la ejecución efectiva.

## Autoridad y segregación

1. Admin u Operator pueden solicitar un anticipo reutilizando
   la capacidad `request-payment`.
2. Sólo Admin puede aprobar o rechazar.
3. El solicitante no puede decidir su propia solicitud.
4. Admin u Operator pueden ejecutar una solicitud aprobada.
5. Quien aprobó no puede ejecutar.
6. El ejecutor sí puede ser el solicitante cuando existió una
   aprobación separada.

## Dinero y cuentas

1. La cuenta de origen queda fijada en la solicitud y la moneda
   deriva de esa cuenta.
2. Caja: la ejecución exige el turno abierto propio del
   ejecutor, la misma CashBox y efectivo esperado suficiente.
3. Caja genera exactamente un `CashMovement` OUT de tipo
   `supplier_advance`.
4. No-cash exige cuenta activa no efectiva y una referencia
   explícita.
5. No-cash no inventa `FinancialExternalMovement`; la verdad
   externa seguirá entrando por conciliación/importación.
6. `CashReserve` no se usa directamente en P9.7f.

## Efecto sobre CxP

Registrar el anticipo todavía no lo aplica a una obligación.

`SupplierAdvance ≠ SupplierCreditApplication`

P9.7g incorporará el anticipo como segunda fuente del saldo a
favor de proveedor y definirá su aplicación explícita a deuda,
sin reescribir el hecho monetario.

## Inmutabilidad e idempotencia

Solicitud, decisión y ejecución son hechos append-only.
Cada etapa posee idempotencia y fingerprint propios. La base
protege tenant, proveedor, cuenta, moneda, autoridad,
segregación y relaciones fundamentales.

## Portabilidad

La migración extiende el guard vigente de `cash_movements`
para el nuevo tipo `supplier_advance` y agrega un guard
específico de vínculo.

No introduce agregados sobre la misma tabla dentro de su
propio trigger MySQL/MariaDB.

## Fuera de alcance

- aplicación del anticipo a una obligación;
- neteo automático;
- agrupación de pagos;
- ejecución no-cash de obligaciones normales;
- movimiento financiero externo sintético;
- fiscalidad ARCA;
- migración de la BD real.

## Próximo corte

P9.7g — Supplier Advance Credit Convergence: incorporar
`SupplierAdvance` como segunda fuente de crédito de proveedor
y permitir su aplicación explícita a obligaciones.
