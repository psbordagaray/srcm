# ADR 81 — Customer Advance + Credit Convergence V1

Estado: Aceptada para P9.6a

Checkpoint de partida:
`f2a01a73a778fc535855aac63646652ca545c6c4`

Fecha:
`2026-08-17`

## 1. Contexto

P9.1–P9.5 ya separan:

- deuda del cliente;
- cobranza aplicada a deuda;
- aging;
- política de crédito;
- cuotas propias.

P8.5.6/P8.5.7 ya poseen un ledger convergente de saldo a favor compuesto por
fuentes append-only y consumos posteriores.

El relevamiento P9.6 confirmó dos hechos importantes:

1. no existe todavía una foundation de `CustomerAdvance`, `CustomerDeposit`,
   `CustomerPrepayment` ni reserva/seña;
2. `CustomerCreditGrant` no es una fuente genérica: su procedencia exige una
   resolución de posventa.

Por lo tanto un anticipo real recibido antes de una venta no puede fingirse como
`CustomerCreditGrant`, `CommercePayment`, `CustomerCollection` ni venta futura.

## 2. Decisión

P9.6a incorpora `CustomerAdvance` como hecho monetario append-only de dinero
recibido de un cliente **sin venta asociada todavía**.

Un anticipo confirmado:

- pertenece a organización, cliente y moneda;
- identifica el medio y la cuenta financiera de destino;
- registra responsable y hora de servidor;
- es idempotente;
- es inmutable;
- en efectivo produce exactamente un `CashMovement::customer_advance`;
- en medio no efectivo no inventa `FinancialExternalMovement`;
- entra al saldo a favor convergente del cliente;
- puede consumirse luego mediante el `CustomerCreditConsumer` ya existente.

## 3. No es una venta ni una cobranza

`CustomerAdvance` no crea:

- `CommerceSale`;
- `CommercePayment`;
- `CustomerReceivable`;
- `CustomerCollection`;
- aplicación a deuda;
- movimiento de inventario;
- reserva de stock.

La venta posterior, cuando ocurra, puede consumir el saldo mediante
`CommercePaymentMethod::AccountCredit`, respaldado por
`CustomerCreditConsumption`.

## 4. Convergencia de saldo a favor

El saldo disponible continúa siendo una lectura derivada:

`fuentes de crédito confirmadas - consumos imputados`

P9.6a agrega una tercera fuente a las dos ya existentes:

1. `CustomerCreditGrant`;
2. `CommercePostSaleExchangeCreditGrant`;
3. `CustomerAdvance`.

No se crea una tabla de “saldo actual”.

`CustomerCreditBalanceReader` suma las tres fuentes y resta
`CustomerCreditConsumptionAllocation`.

## 5. Consumo FIFO

`CustomerCreditConsumer` mantiene el contrato FIFO transversal.

Las fuentes se ordenan por el instante en que quedaron disponibles y luego por
tipo e ID estable. Para un anticipo ese instante es `received_at`.

Una venta o diferencia positiva de cambio puede consumir un anticipo junto con
otros saldos a favor sin conocer cuál fue el origen contable de cada peso.

La imputación agrega `customer_advance_id` como tercera fuente posible en
`customer_credit_consumption_allocations`.

Cada imputación debe apuntar exactamente a una de las tres fuentes.

## 6. Efectivo

Si el anticipo se recibe en efectivo:

- el usuario debe poder operar Caja;
- debe existir un turno propio abierto;
- la cuenta destino debe ser la caja activa de ese turno y misma moneda;
- el anticipo confirmado genera un único `CashMovement` de entrada;
- tipo: `customer_advance`;
- el movimiento queda ligado por `customer_advance_id`.

La misma idempotencia no puede duplicar el ingreso.

## 7. Medios no efectivo

Transferencia, tarjeta, billetera u otro medio:

- requieren cuenta financiera activa de la organización y misma moneda;
- no pueden apuntar a `cash_box` ni `cash_reserve`;
- requieren referencia;
- no crean `CashMovement`;
- no crean por sí solos `FinancialExternalMovement`.

La verdad externa continúa perteneciendo al subsistema de movimientos externos
y conciliación.

## 8. Anticipo a cuenta vs. seña/reserva

P9.6a implementa **anticipo a cuenta**, es decir dinero fungible a favor del
cliente para una operación futura.

No implementa todavía una **seña que reserve mercadería**.

Una seña comercial que reduzca disponibilidad necesita además:

- objeto reservado;
- cantidades;
- reglas de expiración/liberación;
- autoridad para vender el reservado;
- concurrencia/hold;
- efecto explícito sobre disponibilidad.

Inventar esos efectos dentro de P9.6a degradaría la separación entre dinero,
venta e inventario. La foundation de reserva/hold permanece fuera de este corte.

Cuando se implemente la reserva formal, podrá vincular su seña con un hecho
monetario real sin reescribir `CustomerAdvance`.

## 9. Política de crédito y CxC

El anticipo/saldo a favor no reduce automáticamente la exposición usada por
`CustomerCreditPolicy`.

La exposición de crédito continúa derivándose de cuentas por cobrar.

El saldo a favor sólo reduce el importe de una futura venta cuando se lo consume
explícitamente como `account_credit`.

No existe neteo silencioso deuda ↔ saldo a favor.

## 10. Autoridad

P9.6a reutiliza la capacidad vigente de registrar cobranzas de clientes:

- Administrador: puede registrar anticipo;
- Operador: puede registrar anticipo;
- Viewer: lectura únicamente.

La recepción de dinero no requiere una aprobación Administrador adicional
porque no concede crédito de la empresa: registra dinero efectivamente recibido.

## 11. Integridad de base

SQLite y MySQL/MariaDB deben exigir:

- cliente activo de la misma organización;
- cuenta activa de la misma organización y moneda;
- forma de efectivo coherente con turno/caja;
- referencia obligatoria en no efectivo;
- actor Administrador u Operador;
- transición única `building -> confirmed`;
- inmutabilidad y no borrado;
- un `CashMovement` de anticipo sólo puede respaldar el mismo anticipo efectivo;
- `customer_advance_id` no puede aparecer en otro tipo de movimiento;
- una imputación de crédito apunta exactamente a una fuente;
- no puede consumirse más que el importe original del anticipo;
- cliente y moneda del anticipo deben coincidir con el consumo.

## 12. Superficie operativa

La Cuenta Corriente del cliente muestra:

- saldo a favor convergente por moneda;
- historial de anticipos confirmados;
- importe originalmente recibido;
- importe ya consumido;
- remanente derivado;
- formulario “Registrar anticipo a cuenta”.

La interfaz debe explicar expresamente que este corte **no reserva mercadería**.

## 13. Fuera de alcance

P9.6a no implementa:

- reserva/hold de stock;
- seña vinculada a producto concreto;
- vencimiento automático del anticipo;
- devolución/cancelación de anticipo;
- sobrecobro de CxC transformado automáticamente en crédito;
- neteo automático de deuda contra saldo a favor;
- intereses;
- fiscalidad del anticipo;
- automatización de conciliación bancaria.

## 14. Criterio de aceptación

P9.6a es GREEN si:

1. un anticipo efectivo crea un único hecho + un único ingreso de Caja;
2. un anticipo no efectivo crea el hecho sin movimiento de Caja ni movimiento
   externo inventado;
3. el saldo a favor derivado aumenta exactamente por el anticipo;
4. una venta puede consumir ese saldo mediante `account_credit`;
5. el consumo FIFO puede mezclar anticipo y fuentes históricas;
6. una diferencia positiva de cambio puede reutilizar la misma fuente;
7. la DB bloquea sobreconsumo, fuente cruzada, mutación y borrado;
8. Viewer no puede registrar anticipos;
9. la UI deja explícito que el anticipo no reserva mercadería;
10. P9.1–P9.5, CustomerCredit, Caja, checkout y posventa permanecen GREEN;
11. suite integral GREEN;
12. BD real local permanece byte-a-byte intacta y sin migración real;
13. no hay HTTP externo.

## 15. Continuidad

Después de P9.6a quedarán dos decisiones separadas:

- sobrepago de cobranza y eventual materialización de saldo a favor;
- seña/reserva formal con disponibilidad/hold.

Ninguna debe reescribir los hechos monetarios ya confirmados.
