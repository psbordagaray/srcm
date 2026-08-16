# ADR 67 — Post-Sale Exchange Execution Foundation V1

Estado: Propuesta para P8.4.5

Checkpoint de partida:
`e7174131a980fec1430cf64fa1ef2de8a1839d33`

## 1. Contexto

ADR 66 fijó una selección de cambio inmutable y una diferencia firmada:

`replacement_amount_minor - recognized_amount_minor`

P8.4.4 deliberadamente no entregó inventario ni movió dinero.

P8.4.5 materializa esa selección ya autorizada sin reescribir la venta original.

## 2. Decisión

Una ejecución de cambio:

1. revalida la selección, resolución `exchange` y venta confirmada;
2. exige un ejecutor distinto de quien resolvió y de quien seleccionó;
3. exige origen y condición física explícitos para cada línea seleccionada;
4. usa el motor estándar `InventoryMovementCreator` + `InventoryMovementConfirmer`;
5. confirma una salida `issue` exacta del reemplazo;
6. materializa la diferencia económica según su signo;
7. conserva vínculos append-only entre selección, salida, líneas y hechos monetarios.

## 3. Diferencia cero

Si la diferencia es cero:

- se entrega el reemplazo;
- no se crea cobro;
- no se crea crédito;
- no se crea movimiento financiero externo.

## 4. Diferencia positiva

Si la diferencia es positiva, la ejecución exige uno o más pagos cuya suma sea
exactamente la diferencia.

Se admite:

- efectivo;
- débito;
- crédito;
- transferencia bancaria;
- billetera digital;
- otro medio explícitamente referenciado.

No se admite todavía `account_credit`, porque consumir saldo a favor requiere un
ledger de aplicación de crédito que no existe aún.

El efectivo exige turno propio abierto y registra una entrada inmutable en
`cash_movements` de tipo `post_sale_exchange_difference`.

Los medios no efectivos registran el hecho esperado del cobro, pero P8.4.5 no
crea `FinancialExternalMovement`, no concilia y no llama proveedores externos.

## 5. Diferencia negativa

Si la diferencia es negativa:

- la venta original debe poseer cliente identificado;
- se crea `CommercePostSaleExchangeCreditGrant`;
- el importe es el valor absoluto de la diferencia;
- no se devuelve dinero silenciosamente;
- no se reutiliza `CustomerCreditGrant`, porque ese hecho exige una resolución
  `customer_credit` y tiene otra semántica.

El crédito de cambio queda disponible para un futuro ledger común de saldos a
favor y aplicaciones.

## 6. Inventario

Cada línea de ejecución referencia:

- la línea de selección;
- la línea de `InventoryMovement` confirmada;
- ubicación de origen;
- condición física.

La cantidad y producto provienen de la selección inmutable.

P8.4.5 usa confirmación ordinaria. Si el stock no alcanza, falla cerrado y no
consume automáticamente una autorización de stock negativo.

## 7. Segregación

La selección económica sigue siendo administrativa.

La ejecución requiere permiso de comercio + salida de inventario y puede ser
realizada por Admin u Operador, pero nunca por:

- el usuario que resolvió económicamente la posventa;
- el usuario que confirmó la selección de reemplazo.

Para efectivo, además, el ejecutor debe ser dueño del turno abierto.

## 8. Idempotencia y atomicidad

Existe como máximo una ejecución por selección.

La clave idempotente es única por organización.

La huella liga selección, importes, actor, orígenes, condiciones, pagos y notas.

Inventario, ejecución, pagos/crédito y movimiento de caja ocurren dentro de una
misma transacción. Una falla posterior revierte el conjunto.

## 9. Inmutabilidad

Son append-only:

- ejecución;
- líneas de ejecución;
- pagos de diferencia;
- crédito por diferencia;
- `cash_movements` asociado;
- movimiento de inventario confirmado.

No se modifica la venta original ni su `CommercePayment`.

## 10. Lo que P8.4.5 no hace

Este corte no:

- reserva stock antes de ejecutar;
- fuerza stock negativo;
- consume saldo a favor existente;
- crea cuentas por cobrar;
- crea `FinancialExternalMovement` para cobros electrónicos;
- concilia proveedores;
- llama APIs externas;
- automatiza devoluciones de una diferencia negativa.

## 11. Continuidad

Después de P8.4.5, P8 dispone de los cuatro resultados económicos principales
materializados: saldo a favor, reembolso en efectivo, reembolso externo y cambio
con entrega y diferencia explícita.

Los siguientes cortes deberán revisar superficie HTTP/operativa y la convergencia
de saldos a favor antes de declarar completo el bloque de posventa.
