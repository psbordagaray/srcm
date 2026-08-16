# ADR 55 — Post-Sale Value Resolution Foundation V1

Estado: Aceptada para P8.3

Checkpoint de partida:
`3ba91daf72fe52584b383a3a1e895ae43b93ed5e`

## 1. Contexto

P8.1 separó la solicitud del cliente.
P8.2 separó y confirmó la recepción física con `CustomerReturn`.

La mercadería físicamente recibida todavía no debe implicar por sí sola
reembolso, saldo a favor o cambio.

## 2. Decisión P8.3

P8.3 agrega un hecho append-only de **resolución económica/comercial de valor**:

- `CommercePostSaleResolution`;
- `CommercePostSaleResolutionLine`.

Cada resolución consume únicamente evidencia de recepción física confirmada
y determina cuánto valor se reconoce sobre una cantidad recibida.

Resultados posibles:

- `refund`;
- `customer_credit`;
- `exchange`.

En P8.3 esos resultados son **instrucciones comerciales confirmadas**, no la
ejecución del dinero ni la entrega del reemplazo.

## 3. Valor base y valor reconocido

El valor base de cada línea se deriva exclusivamente de:

`cantidad resuelta × precio unitario original de CommerceSaleLine`.

Se usa la misma exactitud de centavos que Checkout. Si la multiplicación
produce fracción de centavo, se falla cerrado.

El valor reconocido:

- nunca puede ser negativo;
- nunca puede superar el valor base;
- si es menor al valor base exige motivo explícito.

Así la condición real constatada en P8.2 puede justificar una reducción sin
reescribir la venta original ni el movimiento de devolución.

## 4. Límite acumulado

Una línea físicamente recibida puede resolverse en más de un hecho.

La suma de cantidades resueltas nunca puede superar la cantidad confirmada
en `CommercePostSaleReceiptLine`.

El límite se controla en dominio y DB.

## 5. Reembolso y medio original

Una resolución `refund` puede señalar un pago original preferido.

Ese pago:

- debe pertenecer a la venta original;
- no se modifica;
- sólo actúa como instrucción para el futuro ejecutor;
- si se selecciona, su importe original debe poder cubrir el valor reconocido
  por esa resolución.

No se invoca API bancaria, billetera, tarjeta ni Mercado Pago en P8.3.

La asignación/ejecución real del reembolso permanece separada.

## 6. Saldo a favor

`customer_credit` exige que la venta original tenga cliente identificado.

P8.3 no crea todavía un ledger de cuenta corriente ni saldo:
registra la resolución que autoriza ese resultado.

La materialización del saldo a favor será un hecho separado y luego podrá
integrarse con P9 CxC sin reescritura retrospectiva.

## 7. Cambio

`exchange` reserva el valor reconocido para continuar el flujo de cambio.

P8.3 no selecciona silenciosamente mercadería reemplazante, no emite una nueva
salida de inventario y no calcula una diferencia de precio sin conocer el
reemplazo concreto.

La selección del reemplazo y la diferencia quedan para el siguiente corte.

## 8. Autoridad

La resolución económica es Admin-only.

Solicitud y recepción pueden haber sido operativas, pero decidir el valor
económico reconocido no se delega implícitamente al operador.

La DB vuelve a exigir membresía activa con rol `admin`.

## 9. Inmutabilidad e idempotencia

Cada resolución conserva:

- organización;
- solicitud;
- resultado;
- moneda heredada de la venta;
- pago original preferido opcional;
- motivo y nota;
- actor y hora de servidor;
- clave idempotente;
- fingerprint.

Resoluciones y líneas son inmutables y no borrables.

## 10. Sin ejecución monetaria

P8.3 no:

- crea `CashMovement`;
- modifica `CommercePayment`;
- crea `FinancialExternalMovement`;
- concilia;
- llama proveedores;
- crea saldo de cliente;
- entrega producto de cambio;
- modifica venta, solicitud o recepción.

## 11. Próximo corte

P8.4 debe materializar la ejecución del resultado ya resuelto:

- saldo a favor como hecho financiero de cliente;
- reembolso efectivo con salida de Caja cuando corresponda;
- reembolso externo como instrucción/ejecución verificable provider-neutral,
  sin asumir éxito antes de evidencia;
- cambio con reemplazo explícito y diferencia de precio.
