# ADR 58 — Post-Sale External Refund Instruction Foundation V1

Estado: Aceptada para P8.4.3

Checkpoint de partida:
`47fb3687dcf17049ec1aa15198463dc389f0af02`

## 1. Contexto

P8.1 registra solicitud.
P8.2 confirma devolución física.
P8.3 reconoce valor y resultado.
P8.4.1 materializa saldo a favor.
P8.4.2 ejecuta reembolso efectivo sobre el ledger de Caja.

Falta el reembolso cuyo medio original es externo: billetera, adquirente,
cuenta bancaria u otro proveedor financiero conectado.

## 2. Riesgo principal

Una llamada externa no puede quedar mezclada con una transacción SQL como si
ambas fueran una única operación atómica.

Si el proveedor ejecuta dinero y luego falla el commit local, SRCM podría
perder trazabilidad. Si SRCM marca éxito antes de evidencia externa, podría
inventar una devolución que nunca ocurrió.

Por eso P8.4.3 comienza por un hecho local recuperable:
**la instrucción de reembolso externo**.

## 3. Decisión

Se agrega `CommercePostSaleExternalRefundInstruction`, append-only.

La instrucción:

- consume exactamente una resolución P8.3 `refund`;
- exige `preferred_original_payment_id`;
- exige un pago original no efectivo;
- exige `external_operation_id` del pago original;
- usa exactamente la `FinancialAccount` del pago original;
- usa su `FinancialProviderConnection` activa;
- deriva importe y moneda de la resolución/venta;
- exige que el solicitante sea distinto del Admin que resolvió P8.3;
- reserva el importe contra el pago original;
- exige que la capacidad provider-neutral `Refund` esté compatible y healthy.

La instrucción no ejecuta HTTP.

## 4. Gate de compatibilidad y salud

P8.4.3 reutiliza `FinancialProviderAutomationGate` con
`FinancialProviderCapability::Refund`.

Para una instrucción nueva se requiere:

- conexión activa;
- binding de compatibilidad actual;
- snapshot no retirado;
- sin migración pendiente;
- capacidad Refund = Compatible;
- health Refund = Healthy para ese binding.

El manager aplica `FinancialProviderAutomationGate` y el trigger de inserción
vuelve a comprobar el mismo contrato dinámico: binding actual sin sucesor,
snapshot operativo/no retirado, Refund compatible y último health Refund
Healthy para ese binding. Así un insert directo no puede saltar el gate.

Un replay idempotente de una instrucción ya creada no se invalida si luego la
salud del proveedor cambia, porque no vuelve a insertar ni a ejecutar dinero.

## 5. Mercado Pago en el checkpoint actual

El registry de referencia actual declara la capacidad Mercado Pago `Refund`
como `Unknown`.

Por lo tanto P8.4.3 **no habilita reembolsos reales de Mercado Pago**.

Antes de cualquier adaptador de escritura se deberá validar el contrato
externo específico de refund, registrar un nuevo snapshot compatible, migrar
el binding y obtener health Refund confiable.

## 6. Segregación

La decisión económica P8.3 sigue siendo Admin-only.

La instrucción externa puede ser emitida por Admin u Operator habilitado para
usar cuentas financieras, pero nunca por el mismo usuario que resolvió P8.3.

Esto conserva separación entre autorización y ejecución.

## 7. Límite acumulado

La suma de instrucciones asociadas a un mismo
`CommercePayment` nunca puede superar `CommercePayment.amount_minor`.

Este límite se controla en dominio y DB.

Una instrucción emitida reserva su importe. P8.4.3 no libera reservas
silenciosamente ante fallos futuros; cualquier recuperación deberá ser un hecho
append-only explícito para evitar dobles reembolsos.

## 8. Idempotencia e inmutabilidad

La instrucción conserva:

- organización;
- resolución;
- pago original;
- cuenta financiera;
- conexión de proveedor;
- solicitante;
- importe y moneda;
- clave idempotente;
- fingerprint;
- hora de servidor.

Una resolución admite una sola instrucción externa.

La instrucción no se actualiza ni se elimina.

## 9. Sin efecto monetario todavía

P8.4.3 no:

- llama API externa;
- crea `FinancialExternalMovement`;
- crea `CashMovement`;
- modifica `CommercePayment`;
- crea ni consume saldo a favor;
- entrega reemplazos;
- marca el reembolso como exitoso.

## 10. Próximo corte

P8.4.3.2 deberá implementar el ciclo provider-neutral de
**submission + evidence**:

1. tomar una instrucción P8.4.3;
2. enviar mediante un adapter de refund validado;
3. nunca asumir éxito por el mero ACK;
4. registrar identidad de operación externa segura;
5. incorporar estados por API/webhook/polling;
6. materializar la verdad financiera en el ledger
   `FinancialExternalMovement` existente;
7. permitir recuperación explícita sin reintento ciego.

Mercado Pago sólo podrá incorporarse cuando su capability Refund deje de ser
Unknown mediante evidencia de contrato y harness específicos.
