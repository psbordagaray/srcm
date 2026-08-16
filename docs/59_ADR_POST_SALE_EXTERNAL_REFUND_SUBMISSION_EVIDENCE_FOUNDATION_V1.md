# ADR 59 — Post-Sale External Refund Submission + Evidence Foundation V1

Estado: Aceptada para P8.4.3.2

Checkpoint de partida:
`0e2edcd7284474a08afd7fc0552a11105f65180e`

## 1. Contexto

P8.4.3 ya dejó una instrucción durable, append-only y provider-neutral para
reintegrar valor a un medio externo original.

Esa instrucción todavía no llama al proveedor y no afirma que el reembolso
haya ocurrido.

P8.4.3.2 agrega el puente seguro entre esa instrucción local y la verdad
financiera externa.

## 2. Regla principal

Una llamada HTTP y una transacción SQL no son una operación atómica.

Por eso SRCM nunca debe:

- marcar un reembolso como exitoso antes de evidencia externa;
- reintentar una operación externa con una clave nueva;
- crear un segundo ledger para reembolsos;
- persistir payloads crudos, tokens o secretos.

## 3. Despacho durable previo a la llamada

Antes de llamar al adapter se crea
`CommercePostSaleExternalRefundDispatch`.

El despacho conserva:

- organización;
- instrucción P8.4.3;
- conexión financiera;
- cuenta financiera;
- provider key;
- clave provider-neutral idempotente estable;
- fingerprint;
- hora de servidor.

La clave es:

`srcm-refund:{instruction_public_id}`

El mismo despacho se reutiliza después de una caída o resultado incierto.

Una instrucción sólo posee un despacho.

## 4. Contrato del adapter

Se agrega `FinancialProviderRefundAdapter`.

Todo adapter que implemente este contrato DEBE utilizar
`providerIdempotencyKey` como clave idempotente nativa del proveedor, o un
mecanismo equivalente que garantice que repetir la misma solicitud no genere
un segundo reembolso.

El adapter recibe:

- conexión;
- identidad de la instrucción;
- operación externa original;
- importe;
- moneda;
- clave idempotente estable.

El adapter devuelve una `ExternalFinancialProviderObservation` normalizada.

No devuelve ni persiste el payload crudo.

## 5. Registry provider-neutral

`FinancialProviderRefundAdapterRegistry` resuelve un adapter exclusivamente por
`provider_key`.

Si no existe adapter registrado, SRCM falla cerrado antes de crear el despacho.

P8.4.3.2 no registra ningún adapter productivo.

Mercado Pago sigue sin adapter Refund porque su capability vigente continúa
bloqueada hasta validar contrato, compatibilidad y health específicos.

## 6. Gate antes del despacho

El despacho vuelve a ejecutar
`FinancialProviderAutomationGate::assertCanAutomate(..., Refund)`.

Además, el trigger de inserción vuelve a validar:

- conexión activa;
- cuenta activa;
- identidad de instrucción/conexión/cuenta;
- provider key;
- binding actual sin sucesor;
- snapshot no retirado;
- sin migración pendiente;
- Refund compatible;
- último health Refund healthy;
- clave idempotente exacta derivada de la instrucción.

Un insert directo no puede saltar el gate.

## 7. Recuperación después de resultado incierto

El despacho se confirma en DB ANTES de llamar al adapter.

Si el proceso cae o el resultado de red es incierto antes de registrar
evidencia:

- el despacho permanece;
- no existe movimiento financiero inventado;
- un nuevo `submit()` usa el mismo `providerIdempotencyKey`;
- el adapter debe obtener/repetir la operación con semántica idempotente.

No hay retry ciego con una identidad nueva.

## 8. Evidencia externa

Cada estado financiero observado se materializa mediante el motor existente:

`ExternalFinancialProviderIngestor`
→ `ExternalFinancialMovementRecorder`
→ `FinancialExternalMovement`.

Para un reembolso:

- direction = Debit;
- gross amount = importe instruido;
- currency = moneda instruida;
- external operation id obligatorio;
- source = API / Webhook / Polling;
- status = Pending / Posted / Failed / Reversed.

Después del movimiento se crea
`CommercePostSaleExternalRefundEvidence`, que lo vincula al despacho.

No se crea un segundo ledger.

## 9. Estados sucesivos

Un provider puede informar:

Pending → Posted

o:

Pending → Failed

o estados posteriores equivalentes.

Cada estado nuevo entra como un nuevo `FinancialExternalMovement` append-only,
con la deduplicación ya existente por operación + estado + dinero.

La evidencia P8.4.3.2 también es append-only.

Volver a ejecutar `submit()` cuando ya existe evidencia no vuelve a llamar al
proveedor.

Los estados posteriores deben incorporarse por API, webhook o polling mediante
`recordObservation()`.

## 10. Límites de este corte

P8.4.3.2 no:

- implementa Mercado Pago Refund;
- hace HTTP real en tests;
- registra secrets;
- modifica `CommercePayment`;
- crea `CashMovement`;
- crea ni consume `CustomerCreditGrant`;
- entrega reemplazos;
- interpreta un ACK como éxito sin una observación financiera normalizada.

## 11. Próximo corte

P8.4.3.3 debe validar un contrato real de refund para un proveedor concreto
antes de habilitarlo.

Para Mercado Pago, ese trabajo requiere documentación primaria actual,
harness específico, idempotencia verificada, estados reales y una nueva
compatibility snapshot antes de cualquier ejecución productiva.
