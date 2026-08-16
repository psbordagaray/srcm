# ADR 57 — Post-Sale Cash Refund Execution Foundation V1

Estado: Aceptada para P8.4.2

Checkpoint de partida:
`aacb83e817bc71f6c7b19027cd7620a4914420c2`

## 1. Contexto

P8.1 registra la solicitud.
P8.2 confirma la devolución física.
P8.3 reconoce el valor y autoriza un resultado.
P8.4.1 materializa saldo a favor.

P8.4.2 ejecuta únicamente el caso en el que una resolución P8.3 `refund`
debe producir una salida real del ledger de Caja.

## 2. Decisión

Se agrega `CommercePostSaleCashRefundExecution`, append-only.

La ejecución:

- consume exactamente una resolución P8.3 `refund`;
- exige `preferred_original_payment_id`;
- exige que ese pago original sea `cash`;
- deriva el importe de la suma de `recognized_amount_minor`;
- usa la misma `FinancialAccount` CashBox del cobro original;
- exige un turno abierto propio del ejecutor sobre esa caja;
- crea exactamente un `CashMovement` OUT de tipo `post_sale_refund`;
- no modifica `CommercePayment`;
- no modifica la venta, solicitud, recepción ni resolución.

## 3. Separación resolución / ejecución

La resolución económica sigue siendo Admin-only.

La ejecución de caja puede hacerla Admin u Operator que pueda operar Caja,
pero **nunca la misma persona que registró la resolución P8.3**.

Así el reembolso conserva separación entre decisión y desembolso.

## 4. Medio original

P8.4.2 no elige automáticamente otro medio.

Para un reembolso efectivo:

- el pago preferido debe pertenecer a la venta original;
- debe ser un pago `cash`;
- la cuenta de ese pago debe seguir siendo una CashBox activa;
- el turno del ejecutor debe operar sobre esa misma cuenta.

Si esas condiciones no existen, P8.4.2 falla cerrado. Otro medio requiere su
propio ejecutor, no una sustitución silenciosa.

## 5. Límite contra el cobro original

El acumulado de ejecuciones de reembolso que referencien un mismo pago
original nunca puede superar `CommercePayment.amount_minor`.

Esto se controla en dominio y DB.

La regla es adicional al límite de cantidad/valor reconocido ya aplicado en
P8.3.

## 6. Caja disponible

Antes de ejecutar se calcula el efectivo esperado del turno con el ledger
existente:

`opening_amount + entradas - salidas`.

El reembolso no puede superar ese efectivo esperado.

No se crea un segundo libro de caja.

## 7. Extensión segura de CashMovement

`CashMovementType` incorpora `post_sale_refund`.

`cash_movements` obtiene un vínculo nullable y único
`post_sale_cash_refund_execution_id`.

La migración no reescribe manualmente las ramas históricas del guard principal:
lee el trigger vigente, exige reconocer exactamente la lista
`sale_payment / security_drop / purchase_payment`, la extiende con
`post_sale_refund` y agrega un segundo guard específico para validar el vínculo
P8.4.2.

Si el trigger preexistente no coincide inequívocamente, la migración falla
cerrado.

## 8. Inmutabilidad e idempotencia

Una resolución sólo admite una ejecución cash.

La ejecución conserva:

- organización;
- resolución;
- pago original;
- CashBox;
- turno y caja;
- ejecutor;
- importe y moneda;
- referencia/nota opcionales;
- idempotency key;
- fingerprint;
- hora de servidor.

Ejecución y movimiento de caja son inmutables y no borrables.

## 9. Fuera de alcance

P8.4.2 no:

- ejecuta reembolsos bancarios, tarjeta o billetera;
- llama Mercado Pago ni otro provider;
- crea `FinancialExternalMovement`;
- muta `CommercePayment`;
- crea o consume saldo a favor;
- entrega reemplazos;
- calcula diferencia de un cambio.

## 10. Próximo corte

P8.4.3 implementará el reembolso externo provider-neutral con evidencia de
ejecución separada de la orden/autorización, sin asumir éxito antes de contar
con verdad verificable del proveedor.
