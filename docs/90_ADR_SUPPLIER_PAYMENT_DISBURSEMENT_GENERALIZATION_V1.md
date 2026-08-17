# ADR 90 — Supplier Payment Disbursement Generalization V1

Estado: Aceptada

Checkpoint de partida:
`0cef2c1db1108e5bdd3a816f91e6dd60bb4a8ab3`

Fecha: 2026-08-17

## Contexto

P9.7h dejó una autorización agrupada capaz de fijar dos o
más obligaciones, pero sin ejecución monetaria.

La ejecución histórica P4F.3 posee otra forma:

`PurchasePaymentRequest`
→ `PurchasePaymentExecution`
→ `CashMovement`

Esa forma es válida para una autorización individual cash,
pero no representa naturalmente:

- un único desembolso aplicado a varias obligaciones;
- un pago non-cash sin inventar movimiento de caja;
- la separación entre la verdad local del desembolso y la
  evidencia externa usada después para conciliación.

El RECON P9.7i confirmó además que no existe hoy un parent
`PurchasePaymentDisbursement` ni una allocation equivalente.

## Decisión

P9.7i introduce como nueva verdad canónica:

`PurchasePaymentDisbursement`
→ `PurchasePaymentDisbursementAllocation`

El parent representa **una salida física/económica única**.
Las allocations indican exactamente qué obligaciones extingue.

La autorización consumida puede ser exactamente una de:

- `PurchasePaymentRequest` individual;
- `PurchasePaymentGroupRequest` agrupada.

Nunca ambas.

## Canal cash

Si la cuenta de origen es `CashBox`:

- el ejecutor necesita turno abierto propio sobre esa caja;
- el saldo esperado del turno debe alcanzar;
- se crea exactamente **un** `CashMovement` por desembolso;
- el nuevo tipo de caja es
  `purchase_payment_disbursement`;
- el movimiento apunta al parent
  `purchase_payment_disbursement_id`;
- un grupo de N obligaciones no genera N salidas de caja.

## Canal non-cash

Para BankAccount, DigitalWallet, CardProcessor u Other:

- no se requiere turno;
- se requiere referencia de ejecución;
- no se crea `CashMovement`;
- no se crea un `FinancialExternalMovement` falso;
- la evidencia bancaria/provider continúa siendo verdad
  externa separada y se conciliará posteriormente.

`CashReserve` no se usa como cuenta de pago directo en este
corte: el efectivo de tesorería conserva su propio flujo
operativo.

## Imputaciones

Cada allocation es append-only e inmutable.

Individual:

- una allocation;
- obligación e importe iguales a la autorización individual.

Agrupada:

- una allocation por item autorizado;
- misma obligación e importe que cada item;
- todas se ejecutan atómicamente;
- no existe ejecución parcial del grupo en P9.7i.

## Saldo de obligación

La lectura convergente queda:

`pagado =
 PurchasePaymentExecution legacy
 + PurchasePaymentDisbursementAllocation canónica`

y luego:

`saldo =
 obligación
 - pagado
 - notas de crédito aplicadas
 - anticipos aplicados`

Los hechos P4F.3 existentes no se reescriben ni duplican.

## Compatibilidad legacy

`PurchasePaymentExecution` permanece como verdad histórica
append-only para ejecuciones cash P4F.3 existentes.

P9.7i no migra ni reescribe esos registros.

El nuevo manager canónico puede ejecutar autorizaciones
individuales o agrupadas. Una autorización consumida queda
`Executed`, por lo que no puede ejecutarse por ambos motores.

La adaptación HTTP de la superficie histórica al nuevo
manager queda para el corte operativo siguiente; el contrato
legacy se conserva mientras tanto para evitar una ruptura
accidental.

## Segregación

- la autorización debe estar `Approved`;
- quien aprobó no puede ejecutar;
- Admin u Operator con capacidad `execute-payment` pueden ser
  ejecutores distintos del aprobador;
- idempotencia y fingerprint evitan doble desembolso.

## Integridad

La BD protege:

- parent e allocations inmutables;
- exactamente una fuente de autorización;
- una sola ejecución canónica por autorización;
- caps por obligación sumando legacy + canónico + créditos;
- transición `Approved → Executed` sólo si existe evidencia
  canónica completa;
- un solo movimiento cash por desembolso;
- ausencia de source-link cash incorrecto;
- futuras solicitudes/aplicaciones no pueden ignorar
  allocations ya ejecutadas.

## MySQL / MariaDB

La migración no agrega triggers que agreguen sobre la misma
tabla que está siendo insertada en un BEFORE INSERT cuando
eso pueda violar la restricción de mutating/self-reference.

Los guards cross-table protegen el cruce entre autorización,
desembolso, allocation, deuda y caja.

La BD real local no se migra durante P9.7i.

## Fuera de alcance

- conciliación automática del desembolso non-cash contra
  movimiento bancario/provider;
- comisión/retención bancaria del pago a proveedor;
- ejecución parcial de un grupo ya aprobado;
- pago agrupado multi-proveedor, multi-beneficiario o
  multi-moneda;
- pago directo desde CashReserve;
- fiscalidad ARCA;
- migración de la BD real;
- reemplazo HTTP de la ruta legacy P4F.3.

## Próximo corte

P9.7j — Supplier Payment Operational Convergence:
surface HTTP/UI canónica para ejecutar individual o agrupado,
cash o non-cash, y preparar matching de verdad externa sin
doble ledger.
