# ADR 74 — AccountCredit en diferencia positiva de cambio V1

Estado: Aceptada para P8.5.7

Checkpoint de partida:
`d4b139d243bd6a365dfe653003c237c5b82c160b`

## 1. Contexto

P8.5.6 convergió las dos fuentes append-only de saldo a favor y habilitó
`AccountCredit` en el checkout comercial normal.

La ejecución de cambio continuó deliberadamente fail-closed para ese medio.

El ledger P8.5.6 quedó inicialmente anclado a una venta y posición de pago.
P8.5.7 extiende ese mismo ledger; no crea un segundo saldo paralelo.

## 2. Decisión

`customer_credit_consumptions` conserva `commerce_sale_id` como contexto de
cliente y moneda, y agrega una identidad explícita de destino:

- `target_kind`;
- `target_id`;
- `commerce_post_sale_exchange_execution_id` cuando el destino es un cambio.

Los destinos admitidos son:

- `sale_payment`;
- `exchange_payment`.

La unicidad pasa a:
organización + tipo de destino + id de destino + posición.

Los consumos históricos P8.5.6 se backfillean como `sale_payment`.

## 3. Representación económica

Un saldo a favor consumido en una diferencia positiva de cambio se representa
por `CustomerCreditConsumption`.

No se crea una fila ficticia en
`commerce_post_sale_exchange_payments`, porque esa tabla exige una cuenta
financiera y el saldo interno no es una cuenta de cobro.

La ejecución puede combinar:

- uno o más cobros convencionales;
- uno o más consumos de saldo a favor;

y la suma total debe cubrir exactamente la diferencia positiva.

## 4. Atomicidad

El consumo ocurre dentro de la misma transacción de
`CommercePostSaleExchangeExecutionManager`.

Si falla:

- saldo disponible;
- inventario;
- segregación;
- importe exacto;
- cuenta/caja;
- persistencia;

se revierte la ejecución completa, incluidos movimiento de inventario,
consumos y allocations.

## 5. Cliente y moneda

`AccountCredit` exige que la venta original tenga `BusinessParty` identificado.

El crédito consumido debe pertenecer a:

- la misma organización;
- el mismo cliente;
- la misma moneda de la venta y de la ejecución.

## 6. Secuencia

La secuencia económica es única dentro de la ejecución incluso cuando mezcla
pagos convencionales y consumos internos.

La BD impide que un `commerce_post_sale_exchange_payment` reutilice una
posición ya tomada por un consumo de crédito y viceversa.

## 7. UI

La pantalla de ejecución:

- incorpora `Crédito en cuenta` entre los medios;
- muestra el saldo derivado del cliente y moneda;
- no pide cuenta financiera para `AccountCredit`;
- no pide referencia de operador para ese medio;
- mantiene hasta tres componentes para una diferencia.

El servidor continúa siendo autoritativo.

## 8. Auditoría y expediente

La ejecución carga sus `creditConsumptions` y el expediente muestra el importe
aplicado como saldo a favor consumido, separado de los cobros convencionales.

Los grants originales no se modifican.

## 9. No objetivos

P8.5.7 no:

- cambia el otorgamiento de créditos;
- introduce balance mutable;
- migra la BD real desde el runner;
- crea movimiento de caja por saldo a favor;
- crea `FinancialExternalMovement`;
- llama proveedores;
- modifica ADR 65 ni el gate degradado de refund Mercado Pago.

## 10. Cierre de Posventa

Con este corte, la misma verdad de saldo a favor puede:

1. nacer por resolución `customer_credit`;
2. nacer por diferencia negativa de cambio;
3. consumirse en una venta normal;
4. consumirse en una diferencia positiva de cambio.

Si checkpoint, regresiones y revisión integral quedan GREEN, la siguiente
actividad es una auditoría de cierre del submódulo Posventa, no otro motor
económico paralelo.
