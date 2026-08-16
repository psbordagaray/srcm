# ADR 56 — Customer Credit Grant Foundation V1

Estado: Aceptada para P8.4.1

Checkpoint de partida:
`a46f13faa8ff2c187fd32e005d17eaf89f383376`

## 1. Contexto

P8.1 registra la solicitud.
P8.2 registra la recepción física y confirma `CustomerReturn`.
P8.3 reconoce el valor económico y puede resolver el resultado como
`customer_credit`.

Hasta P8.3 el saldo a favor sigue siendo una decisión comercial, no un hecho
financiero materializado.

## 2. Decisión

P8.4 se divide en cortes de ejecución separados para no mezclar riesgos.

P8.4.1 materializa únicamente **saldo a favor de cliente**.

Se agrega `CustomerCreditGrant`, un hecho append-only que:

- pertenece a una organización;
- pertenece al cliente identificado de la venta original;
- referencia exactamente una resolución P8.3 `customer_credit`;
- conserva moneda e importe;
- registra actor y hora de servidor;
- posee idempotencia y fingerprint;
- es inmutable y no borrable.

## 3. Importe exacto

El importe del grant no se ingresa nuevamente.

Se deriva de la suma de `recognized_amount_minor` de las líneas de la
resolución P8.3.

La DB vuelve a comprobar la misma igualdad.

Una resolución con importe reconocido cero no se materializa como saldo.

## 4. Un grant por resolución

`commerce_post_sale_resolution_id` es único.

Repetir exactamente la misma ejecución con la misma clave devuelve el mismo
hecho.

Intentar volver a materializar la misma resolución con otra operación falla
cerrado.

## 5. Cliente

El `business_party_id` se deriva de `CommerceSale.customer_business_party_id`.

No puede elegirse otro cliente.

La DB verifica que:

- la resolución sea `customer_credit`;
- solicitud, venta, resolución, cliente y grant pertenezcan a la misma
  organización;
- la venta siga confirmada;
- la moneda coincida;
- el importe sea exactamente el reconocido;
- el actor tenga membresía Admin activa.

## 6. Qué significa y qué no significa

El grant representa que SRCM ya reconoce una obligación monetaria a favor
del cliente.

P8.4.1 todavía no implementa:

- consumo/redención del saldo;
- cuenta corriente completa;
- compensación contra una nueva venta;
- movimiento de caja;
- reembolso bancario o de billetera;
- API de proveedor;
- entrega de producto de cambio.

El consumo y la proyección de cuenta corriente podrán reutilizar este hecho
en P9 sin reescribirlo.

## 7. Siguientes cortes P8.4

- P8.4.2: reembolso efectivo contra Caja usando el ledger de caja existente;
- P8.4.3: reembolso externo provider-neutral con evidencia de ejecución;
- P8.4.4: cambio con reemplazo explícito y diferencia de precio.

Cada efecto tendrá su propia idempotencia, trazabilidad y guardas.
