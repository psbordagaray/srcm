# ADR 82 — Collection Overpayment → Customer Credit V1

Estado: Aceptada para P9.6b

Checkpoint de partida:
`a3f0513a509677ff6630ca1a0f8417956aac74af`

Fecha:
`2026-08-17`

## 1. Contexto

P9.2 estableció una cobranza aplicada exactamente a deuda.
P9.6a incorporó `CustomerAdvance` como tercera fuente convergente de saldo a favor.

El recon P9.6b confirmó dos verdades:

1. `CustomerCollection.amount_minor` representa el dinero efectivamente recibido y retenido;
2. en efectivo, un único `CashMovement::customer_collection` registra ese importe completo.

Crear además un `CustomerAdvance` monetario por el excedente duplicaría el ingreso de Caja.

## 2. Decisión comercial

Dirección elige política **controlada**:

> Si una cobranza recibe más dinero que el importe aplicado a deuda, el excedente sólo puede quedar como saldo a favor cuando Admin u Operador lo confirma explícitamente.

Sin confirmación explícita, SRCM bloquea la cobranza.

## 3. Un solo hecho de recepción de dinero

El sobrepago no crea una segunda recepción monetaria.

`CustomerCollection` continúa siendo el único hecho del dinero recibido:

`importe recibido = aplicado a CxC + excedente retenido como saldo a favor`

En efectivo:

- `CashMovement::customer_collection.amount_minor = CustomerCollection.amount_minor`;
- no existe segundo `CashMovement`;
- el dinero entregado antes del vuelto continúa en `tendered_amount_minor`;
- el vuelto continúa en `change_amount_minor`;
- el vuelto nunca se transforma en saldo a favor.

## 4. Fuente convergente derivada

No se crea una tabla adicional de “saldo de sobrepago”.

Una cobranza confirmada es fuente de saldo a favor sólo si:

- `retain_excess_as_credit = true`;
- `amount_minor > SUM(customer_collection_allocations.amount_minor)`.

El importe fuente es derivado:

`collection_credit_minor = amount_minor - SUM(aplicaciones a deuda)`

Esa diferencia queda inmutable porque cobranza y aplicaciones confirmadas ya son inmutables.

## 5. CustomerCredit

El ledger convergente suma cuatro familias de fuentes:

1. `CustomerCreditGrant`;
2. `CommercePostSaleExchangeCreditGrant`;
3. `CustomerAdvance`;
4. excedente explícitamente retenido de `CustomerCollection`.

`CustomerCreditBalanceReader` sigue calculando:

`fuentes confirmadas - consumos imputados`

No existe un campo mutable de saldo actual.

## 6. Consumo FIFO

`CustomerCreditConsumer` incorpora la cobranza con excedente como cuarta fuente.

El instante FIFO es `CustomerCollection.collected_at`.

`customer_credit_consumption_allocations` agrega `customer_collection_id` como cuarta fuente exclusiva.

Cada imputación debe apuntar exactamente a una de las cuatro fuentes.

## 7. No neteo silencioso

El hecho de que un cliente tenga deuda y saldo a favor no autoriza compensación automática.

La cobranza aplica únicamente los importes seleccionados a deuda.

El excedente retenido queda disponible para una operación futura y sólo disminuye cuando un flujo explícito consume `account_credit`.

## 8. Medios no efectivo

Una cobranza bancaria/tarjeta/billetera con sobrepago:

- conserva una sola `CustomerCollection`;
- no crea `CashMovement`;
- no inventa `FinancialExternalMovement`;
- el excedente puede quedar como saldo a favor con confirmación explícita.

La verificación externa permanece separada.

## 9. Autoridad

Se reutiliza `canRecordCustomerCollections()`:

- Administrador: puede confirmar;
- Operador: puede confirmar;
- Viewer: sólo lectura.

La confirmación no concede crédito comercial financiado por la empresa; reconoce que una parte de dinero real recibido quedó sin aplicar a deuda y pasa a ser obligación a favor del cliente.

## 10. Integridad DB

SQLite y MySQL/MariaDB deben exigir:

- al menos una aplicación a deuda;
- aplicaciones nunca superiores al importe recibido;
- si recibido = aplicado, `retain_excess_as_credit = false`;
- si recibido > aplicado, `retain_excess_as_credit = true`;
- cobranza y aplicaciones inmutables tras confirmación;
- la fuente de consumo por cobranza exige organización, cliente, moneda y estado confirmados;
- el máximo consumible de esa fuente es exactamente `recibido - aplicado`;
- una imputación de saldo a favor apunta exactamente a una de cuatro fuentes;
- no existe sobreconsumo.

## 11. UI

La Cuenta Corriente debe:

- mostrar que una cobranza puede generar saldo a favor;
- exigir checkbox explícito para retener excedente;
- explicar que vuelto y saldo a favor son conceptos diferentes;
- mostrar en el historial el saldo a favor generado por cada cobranza.

## 12. Fuera de alcance

P9.6b no implementa:

- devolución/cancelación posterior del saldo a favor;
- compensación automática deuda ↔ crédito;
- aplicación automática del excedente a otras deudas no seleccionadas;
- seña/reserva/hold;
- conciliación externa;
- fiscalidad del sobrepago.

## 13. Criterio de aceptación

P9.6b es GREEN si:

1. sobrepago sin confirmación falla cerrado;
2. sobrepago confirmado conserva una única cobranza;
3. efectivo genera un único ingreso de Caja por el total retenido;
4. `tendered - change = amount_minor` sigue separado del excedente a crédito;
5. no efectivo no inventa Caja ni movimiento externo;
6. saldo a favor aumenta exactamente por `recibido - aplicado`;
7. una venta puede consumir esa fuente mediante `account_credit`;
8. FIFO puede mezclar fuentes previas y sobrepago;
9. DB bloquea fuente cruzada y sobreconsumo;
10. UI exige confirmación explícita;
11. P9.1–P9.6a y Caja continúan GREEN;
12. suite integral GREEN;
13. BD real local permanece byte-a-byte intacta y sin migración real;
14. no hay HTTP externo.
