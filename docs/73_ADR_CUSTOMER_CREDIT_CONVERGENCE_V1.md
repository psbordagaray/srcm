# ADR 73 — Customer Credit Convergence V1

Estado: Aceptada para P8.5.6

Checkpoint de partida:
`c03a1e8c4eceb110599ea4eb8827500c046ed88f`

## 1. Contexto

Posventa ya produce dos hechos económicos append-only que representan valor a
favor del cliente:

1. `customer_credit_grants`, derivados de una resolución explícita
   `customer_credit`;
2. `commerce_post_sale_exchange_credit_grants`, derivados de una ejecución de
   cambio cuya diferencia es negativa.

Ambos hechos son canónicos e inmutables, pero todavía no existe una verdad
común de consumo.

`CommercePaymentMethod::AccountCredit` ya forma parte del vocabulario comercial,
aunque hasta este corte no estaba respaldado por un ledger de crédito.

## 2. Decisión

P8.5.6 no reescribe ni convierte grants históricos.

Agrega un ledger append-only compuesto por:

- `customer_credit_consumptions`;
- `customer_credit_consumption_allocations`.

Cada consumo identifica:

- organización;
- cliente;
- venta;
- posición del pago;
- moneda;
- importe;
- actor;
- instante;
- idempotencia y fingerprint.

Cada imputación señala exactamente una fuente histórica:

- `customer_credit_grant_id`; o
- `commerce_post_sale_exchange_credit_grant_id`.

## 3. Balance

El saldo visible es derivado:

`grants posventa + grants por cambio - allocations consumidas`

No existe una columna mutable `balance`.

La proyección converge ambas fuentes sin perder su procedencia.

## 4. Política de asignación

El consumo usa FIFO global por `granted_at`.

Ante igualdad temporal, el orden se estabiliza por tipo de fuente e id.

El dominio bloquea las fuentes elegibles dentro de la transacción y calcula su
remanente contra todas las allocations confirmadas.

## 5. Checkout comercial

P8.5.6 habilita `account_credit` en `CommerceCheckoutManager`.

Condiciones:

- la venta debe tener `BusinessParty` identificado;
- moneda del crédito y venta deben coincidir;
- el importe aplicado debe ser positivo;
- el saldo conjunto debe alcanzar;
- el pago no usa `financial_account_id`;
- no admite tender/vuelto;
- no admite evidencia de tarjeta o proveedor;
- la referencia del pago es generada por servidor con el `public_id` del
  consumo.

El consumo se crea dentro de la misma transacción de checkout.

Si falla stock, precio, pago exacto, saldo o persistencia, se revierte también
el consumo.

## 6. Guard de base de datos

Además de las invariantes de modelo y dominio, la base impide:

- modificar o borrar consumos;
- modificar o borrar allocations;
- allocations sin fuente o con dos fuentes simultáneas;
- sobregiro de cualquier grant;
- sobregiro del consumo;
- `commerce_payment` de tipo `account_credit` sin consumo coincidente y
  completamente asignado.

Por lo tanto un pago de crédito no puede fabricarse insertando únicamente una
fila en `commerce_payments`.

## 7. Autoridad

Consumir crédito en una venta usa la misma autoridad que confirmar la venta:

`canRecordCommerceSale()`.

No agrega una decisión administrativa nueva. El otorgamiento del crédito
continúa protegido por las autoridades de Posventa ya existentes.

## 8. Interfaz

La terminal de venta:

- muestra el saldo disponible por cliente y moneda;
- requiere cliente vinculado para elegir `Crédito en cuenta`;
- no pide cuenta financiera ni referencia;
- limita operativamente el importe visible al saldo disponible;
- permite combinar crédito con otros medios para cancelar exactamente el total.

La validación del servidor sigue siendo autoritativa.

## 9. Efectos financieros

Consumir saldo a favor:

- no mueve caja;
- no crea `FinancialExternalMovement`;
- no llama proveedores;
- no modifica el pago original que originó el grant.

Es la aplicación de un pasivo/valor interno ya reconocido contra una nueva
venta.

## 10. Cambio

P8.5.6 no habilita todavía `AccountCredit` para pagar una diferencia positiva
durante `CommercePostSaleExchangeExecutionManager`.

Ese manager conserva expresamente su fail-closed actual.

La razón es mantener una sola frontera de integración transaccional durante el
primer corte del ledger convergente.

## 11. Migración

P8.5.6 incluye migración de esquema en código y la valida sobre la base efímera
de tests.

El runner de implementación no ejecuta `artisan migrate` contra la BD real.

## 12. Mercado Pago

No se modifica ADR 65 ni el health/gate de refund.

P8.5.6 no ejecuta HTTP externo.

## 13. Continuidad

P8.5.7 debe reutilizar este mismo `CustomerCreditConsumer` para permitir, de
forma explícita y testeada, que una diferencia positiva de cambio pueda
cancelarse total o parcialmente con `AccountCredit`.

Sólo después de esa reutilización corresponde evaluar el cierre integral del
submódulo Posventa.
