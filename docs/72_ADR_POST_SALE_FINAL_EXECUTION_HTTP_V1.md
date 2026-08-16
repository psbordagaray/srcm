# ADR 72 — Post-Sale Final Execution HTTP V1

Estado: Aceptada para P8.5.5

Checkpoint de partida:
`84f6692999d195bb5d24681f9a32edf4229f34ba`

## 1. Contexto

P8.5.4 expuso de forma segregada:

- materialización de saldo a favor;
- ejecución de reembolso de caja;
- instrucción local de reembolso externo;
- selección económica de reemplazo.

Quedaron deliberadamente fuera dos acciones finales:

1. ejecutar el cambio seleccionado;
2. despachar al proveedor una instrucción externa ya creada.

Los motores de dominio para ambas acciones ya existen y son autoritativos.

## 2. Decisión

P8.5.5 agrega dos superficies HTTP distintas.

### 2.1 Ejecución de cambio

Gate:
`execute-commerce-post-sale-exchange`

Autoridad:
`UserRole::canExecuteCommercePostSaleExchange()`

Motor:
`CommercePostSaleExchangeExecutionManager::execute(...)`

La UI exige para cada línea seleccionada:

- ubicación física de origen;
- condición física real;
- saldo suficiente visible en una sola dimensión.

La visualización de saldo es orientativa. El manager vuelve a bloquear y validar
ubicaciones, productos y movimiento de inventario dentro de la transacción.

## 3. Diferencia positiva

Si `replacement - recognized > 0`, la UI permite hasta tres medios explícitos.

La suma debe ser exactamente igual a la diferencia.

El manager conserva la autoridad sobre:

- cuenta financiera;
- moneda;
- sesión de caja propia;
- método de pago;
- referencia no efectiva;
- tender/change de efectivo;
- prohibición de `account_credit`.

Los pagos no efectivos permanecen como hechos locales de cobro de diferencia.
P8.5.5 no fabrica `FinancialExternalMovement` ni llama proveedores para ellos.

## 4. Diferencia cero

La ejecución sólo materializa la salida real de inventario.

No se crean pagos.

## 5. Diferencia negativa

El motor existente crea el crédito específico de cambio por el valor absoluto
de la diferencia.

No se entrega efectivo automáticamente.

El consumo convergente de saldos a favor continúa fuera de este corte.

## 6. Segregación

El ejecutor del cambio debe ser distinto de:

- quien resolvió económicamente el caso;
- quien seleccionó el reemplazo.

La UI muestra la acción sólo bajo el Gate correspondiente.

El manager vuelve a imponer la segregación dentro de la transacción.

## 7. Confirmación explícita

La ejecución HTTP requiere confirmación positiva del operador porque puede:

- descontar inventario;
- registrar cobro;
- crear movimiento de caja;
- crear crédito a favor.

La clave idempotente se conserva en el navegador y el motor controla reintentos.

## 8. Dispatch de reembolso externo

Gate:
`dispatch-commerce-post-sale-external-refund`

Autoridad:
`UserRole::canExecuteCommercePostSaleExternalRefund()`

Motor:
`CommercePostSaleExternalRefundSubmissionManager::submit(...)`

La acción sólo existe sobre una instrucción externa previamente confirmada.

Antes del primer despacho el manager vuelve a exigir:

- conexión y cuenta activas;
- operación externa original;
- identidad financiera consistente;
- `FinancialProviderAutomationGate` habilitado para `refund`.

## 9. Mercado Pago degradado

P8.5.5 no cambia ADR 65.

Mientras la capability refund de Mercado Pago permanezca degradada o bloqueada,
`assertCanAutomate(...)` falla cerrado antes de crear un nuevo dispatch y antes
de llamar al adapter.

No se repite automáticamente el smoke sandbox.

## 10. Idempotencia y resultado incierto

El dispatch usa la clave provider-neutral estable:

`srcm-refund:{instruction_public_id}`

Si existe evidencia previa, `submit(...)` la devuelve sin reenviar.

Si el transporte deja resultado desconocido, el dispatch durable permanece y la
UI indica reintentar la misma instrucción. El reintento conserva la misma clave.

## 11. Evidencia

El expediente carga y muestra:

- dispatch;
- proveedor;
- evidencia acumulada;
- movimiento financiero externo;
- source;
- status;
- importe.

Una respuesta `posted` no modifica el pago original.

## 12. No objetivos

P8.5.5 no:

- migra la BD real;
- cambia contratos de dominio P8.4;
- consume saldos a favor;
- fuerza el gate de Mercado Pago;
- crea polling nuevo;
- modifica pagos originales;
- automatiza medios no efectivos del cambio contra proveedores.

## 13. Continuidad

Con P8.5.5 el expediente HTTP cubre intake, recepción, resolución,
materialización y ejecución de los resultados económicos principales.

El siguiente corte debe revisar la convergencia de saldos a favor
(`CustomerCreditGrant` y crédito específico de cambio) antes de declarar
cerrada la superficie posventa.
