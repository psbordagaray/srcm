# ADR 76 — Customer Receivable Foundation V1

Estado: Aceptada para P9.1

Checkpoint de partida:
`d9cdb76c598f8f525710395bca0846c085419268`

Fecha:
`2026-08-16`

## 1. Contexto

P8 quedó formalmente cerrado. El relevamiento P9.0 confirmó una asimetría
deliberada entre ambos lados de las cuentas corrientes:

- CxP ya posee hechos de obligación, solicitud/autorización y ejecución de pago
  construidos durante P4F;
- CxC no posee todavía un hecho propio de deuda del cliente;
- `CommercePayment` representa dinero efectivamente recibido y no debe
  transformarse en una deuda ficticia;
- el saldo a favor del cliente (`CustomerCreditGrant` y su ledger convergente)
  representa una obligación del comercio hacia el cliente y no debe confundirse
  con dinero que el cliente adeuda al comercio.

## 2. Decisión

P9.1 introduce `CustomerReceivable` como hecho append-only de una venta
confirmada con saldo diferido.

La identidad contable queda separada:

**venta comercial = pagos recibidos + cuenta por cobrar reconocida**

No se agrega un supuesto medio de pago "fiado" ni se fabrica un
`CommercePayment` para completar el total.

La venta continúa siendo atómica con inventario, pagos y saldo pendiente.

## 3. Contrato P9.1

Una cuenta por cobrar:

- pertenece a una organización;
- requiere un `BusinessParty` con rol de cliente activo;
- referencia exactamente una `CommerceSale`;
- usa la misma moneda de la venta;
- posee importe positivo y nunca superior al total de la venta;
- es única por venta en esta foundation;
- puede llevar `due_on` opcional;
- conserva UUID público, idempotencia, fingerprint, actor y tiempo de servidor;
- es inmutable a nivel de modelo y base de datos;
- no crea `CommercePayment`, `CashMovement` ni `FinancialExternalMovement`;
- no consume `CustomerCredit`;
- no modifica retrospectivamente la venta una vez confirmada.

La confirmación comercial exige ahora:

`SUM(commerce_payments.amount_minor) + customer_receivable.amount_minor = commerce_sales.total_minor`

cuando existe una cuenta por cobrar; sin ella se conserva el contrato anterior
de cobro exacto.

## 4. Autoridad fail-closed

P9.1 no amplía silenciosamente la autoridad de un Operador para otorgar crédito.

Durante esta foundation, sólo `Administrador` puede autorizar una venta con
saldo pendiente. Operador conserva su capacidad normal de venta totalmente
cancelada.

Una fase posterior podrá introducir límites, políticas y delegación explícita
sin reescribir los hechos P9.1.

## 5. Superficie operativa

La Terminal de Cobro incorpora para Administrador un bloque explícito
"Saldo pendiente / cuenta corriente".

El saldo pendiente:

- no se presenta como cobro;
- requiere cliente vinculado;
- puede cubrir todo o parte de la venta;
- puede convivir con cobros inmediatos;
- se revisa junto con los medios recibidos antes de confirmar;
- queda visible en el comprobante interno de la venta.

## 6. Fuera de alcance de P9.1

No se implementan aún:

- cobranza posterior;
- aplicación de una cobranza a una o varias deudas;
- aging operativo;
- límites de crédito;
- cuotas propias;
- anticipos/señas;
- compensación con saldo a favor;
- intereses;
- documentos fiscales;
- notas de débito/crédito;
- cambios sobre la foundation CxP existente.

Esas capacidades deben consumir el ledger de hechos sin convertir el
`CustomerReceivable` en un saldo mutable.

## 7. Persistencia y despliegue

El corte incluye migración de esquema para entornos nuevos y tests.

El runner P9.1 no ejecuta migraciones sobre la BD local real. La BD real debe
permanecer byte-a-byte igual antes y después de la validación.

## 8. Criterio de aceptación

P9.1 es GREEN si:

1. una venta totalmente a cuenta se confirma sólo para cliente identificado y
   Administrador;
2. una venta puede combinar pago inmediato + saldo pendiente y la suma exacta
   coincide con el total;
3. Operador no puede otorgar crédito y el intento no mueve stock ni crea venta;
4. la BD rechaza receivables forjados, mutaciones y borrados;
5. el flujo HTTP muestra y registra el saldo pendiente sólo con autoridad;
6. checkout, crédito a favor, Posventa y CxP existentes no regresan;
7. la suite completa queda GREEN;
8. la BD real queda físicamente sin cambios.

## 9. Continuidad

El siguiente slice P9 debe construir sobre este hecho append-only, no
reemplazarlo por un campo de saldo editable.
## 10. Aceptación P9.1

P9.1 quedó validado GREEN antes de su checkpoint de commit/publicación:

- `CustomerReceivable` append-only implementado sin `CommercePayment` ficticio;
- venta a cuenta total y pago inmediato + saldo pendiente validados;
- autorización fail-closed: sólo Administrador en esta foundation;
- contratos UI históricos preservados;
- focal P9.1: 6 tests / 37 assertions;
- checkout HTTP: 9 tests / 127 assertions;
- checkout dominio: 7 tests / 58 assertions;
- crédito convergente: 6 tests / 39 assertions;
- Posventa y foundation CxP regresaron GREEN;
- suite completa: 721 tests / 5985 assertions;
- BD real byte-a-byte sin cambios;
- `customer_receivables` continúa ausente en la BD real local;
- ninguna migración real ejecutada;
- ningún HTTP externo de proveedor ejecutado.

El checkpoint debe publicar exactamente los 14 paths P9.1 sobre el parent
`d9cdb76c598f8f525710395bca0846c085419268` con el commit:

`feat(commerce): add customer receivable foundation`
