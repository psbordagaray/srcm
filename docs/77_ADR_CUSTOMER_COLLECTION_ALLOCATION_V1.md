# ADR 77 — Customer Collection + Allocation Foundation V1

Estado: Aceptada para P9.2

Checkpoint de partida:
`2519b3d7bec00298b99ed0ea1c45e1f757378ad1`

Fecha:
`2026-08-16`

## 1. Contexto

P9.1 incorporó `CustomerReceivable` como hecho append-only de deuda del cliente.
El relevamiento P9.2 confirmó que no existe todavía una entidad de cobranza ni
una entidad de aplicación de cobranza, mientras sí existen y están maduras las
fundaciones de caja, cuentas financieras, movimientos financieros externos,
conciliación y saldo a favor del cliente.

El roadmap exige para CxC:

- cobranzas parciales;
- un cobro aplicado a una o varias deudas;
- cuenta corriente derivada;
- saldos a favor y aging en cortes posteriores.

## 2. Decisión

P9.2 introduce dos hechos separados:

1. `CustomerCollection`: el dinero que el comercio declara haber recibido del
   cliente después de la venta.
2. `CustomerCollectionAllocation`: cómo ese cobro se aplica a una o varias
   `CustomerReceivable`.

El saldo de una deuda **no se persiste ni se edita**:

`pendiente = receivable.amount_minor - SUM(asignaciones de cobranzas confirmadas)`

Una cobranza puede ser parcial y puede distribuirse entre varias deudas del
mismo cliente y moneda.

## 3. Separación de verdades

P9.2 conserva las siguientes fronteras:

- `CommercePayment` continúa siendo un pago ocurrido dentro del checkout de la
  venta original; una cobranza posterior no fabrica un `CommercePayment`.
- `CustomerCredit` continúa representando dinero a favor del cliente contra el
  comercio; no se utiliza como medio de cobranza de CxC en este corte.
- `FinancialExternalMovement` continúa siendo verdad externa de banco,
  billetera o proveedor. Una cobranza electrónica no fabrica ese movimiento.
- `CashMovement` continúa siendo el libro físico esperado de caja y se reutiliza
  cuando la cobranza es en efectivo.

## 4. Contrato de cobranza

Una cobranza confirmada:

- pertenece a una organización;
- requiere cliente activo;
- requiere importe positivo;
- utiliza una cuenta financiera activa de la misma moneda;
- admite efectivo, débito, crédito, transferencia, billetera u otro medio;
- rechaza `AccountCredit`;
- para medios no efectivos exige referencia;
- para efectivo exige el turno de caja abierto propio y la cuenta de ese turno;
- conserva idempotencia, fingerprint, actor y tiempo de servidor;
- es inmutable después de confirmarse;
- debe quedar aplicada exactamente por el total cobrado.

No hay "cobro sin destino" en P9.2.

## 5. Contrato de aplicación

Cada aplicación:

- apunta a una `CustomerReceivable` del mismo tenant;
- exige el mismo cliente y moneda que la cobranza;
- posee importe positivo;
- es única por deuda dentro de la cobranza;
- no puede superar el saldo pendiente derivado;
- es append-only;
- participa en la confirmación atómica de la cobranza.

La suma de aplicaciones debe ser exactamente igual al importe de la cobranza.

## 6. Caja

Una cobranza en efectivo reutiliza `CashMovement`:

- dirección `in`;
- tipo `customer_collection`;
- vínculo único a `customer_collection_id`;
- mismo turno, caja, cuenta, moneda, importe y actor que la cobranza.

De esta forma el efectivo esperado del turno aumenta usando el mismo ledger
existente, sin un segundo saldo paralelo.

## 7. Cobranza electrónica

Una cobranza no efectiva registra el hecho operativo `CustomerCollection` en
la cuenta financiera destino, con referencia obligatoria.

P9.2 **no** crea `FinancialExternalMovement`. La verdad externa seguirá llegando
por proveedor, webhook, importación o carga manual. La reconciliación de esa
verdad externa contra cobranzas será un corte posterior.

## 8. Autoridad

La autoridad se separa de la autorización de crédito:

- crear una `CustomerReceivable` continúa siendo sólo Administrador según P9.1;
- registrar una cobranza es operación diaria y puede hacerla Administrador u
  Operador;
- Consulta puede leer la cuenta corriente pero no registrar cobranzas.

## 9. Superficie operativa

El expediente de cliente enlaza a una pantalla propia de Cuenta Corriente.

La pantalla muestra:

- deuda original;
- cobrado acumulado;
- pendiente derivado;
- vencimiento y condición vencida;
- historial de cobranzas;
- formulario de cobranza con aplicaciones a una o varias deudas.

No se renombran contratos históricos de Venta/Cobro existentes.

## 10. Fuera de alcance

P9.2 no implementa todavía:

- saldo a favor por sobrepago;
- compensación automática con `CustomerCredit`;
- cuotas propias;
- límites de crédito;
- intereses o recargos;
- anticipos/señas;
- conciliación electrónica de `CustomerCollection`;
- aging agregado operativo;
- documentos fiscales.

El sobrepago falla cerrado: el dinero recibido debe aplicarse exactamente a
deudas existentes.

## 11. Integridad y despliegue

La migración soporta SQLite y MySQL/MariaDB, agrega guardas de base para
cobranzas, aplicaciones y vínculo de caja, y extiende de forma exacta el
vocabulario del guard vigente de `cash_movements`.

El runner P9.2 no migra la BD local real. Toda validación de esquema ocurre en
las bases aisladas de tests. La BD real debe quedar byte-a-byte idéntica.

## 12. Criterio de aceptación

P9.2 es GREEN si:

1. una cobranza parcial reduce sólo el saldo derivado de la deuda;
2. una cobranza puede aplicarse a varias deudas del mismo cliente y moneda;
3. sobreaplicación, cliente ajeno, moneda ajena y suma no exacta fallan cerrado;
4. efectivo exige turno propio y aumenta `CashMovement` esperado una sola vez;
5. cobranza electrónica no crea `CashMovement` ni `FinancialExternalMovement`;
6. cobranzas y aplicaciones son inmutables y guardadas también por DB;
7. Administrador y Operador cobran; Consulta sólo lee;
8. el expediente y la cuenta corriente muestran la nueva operación;
9. P9.1, checkout, caja, reconciliación, crédito y suite completa quedan GREEN;
10. la BD real no cambia.

## 13. Continuidad

Los próximos cortes deben consumir estas verdades append-only. Aging, saldos a
favor, cuotas, anticipos y conciliación no deben convertir el pendiente en un
campo mutable ni reescribir cobranzas históricas.

## 14. Aceptación P9.2

P9.2 quedó validado GREEN antes de su checkpoint de publicación:

- `CustomerCollection` append-only implementado como hecho de cobranza posterior a la venta;
- `CustomerCollectionAllocation` append-only implementado para aplicar una cobranza a una o varias deudas;
- cobranzas parciales y multi-deuda validadas;
- saldo de `CustomerReceivable` derivado, nunca mutable;
- `CommercePayment` no se reutiliza para fabricar cobranzas posteriores;
- efectivo reutiliza `CashMovement` y exige turno/cuenta de caja propios;
- cobranzas no efectivas no fabrican `FinancialExternalMovement`;
- `CustomerCredit` no se consume como medio de cobranza en este corte;
- Administrador y Operador pueden cobrar; Consulta conserva lectura;
- focal P9.2: 7 tests / 57 assertions;
- suite completa: 728 tests / 6042 assertions;
- regresiones de CxC, clientes, checkout, crédito, caja y conciliación: GREEN;
- BD real byte-a-byte sin cambios;
- `customer_receivables`, `customer_collections` y `customer_collection_allocations` continúan ausentes en la BD real local;
- ninguna migración real ejecutada;
- ningún HTTP externo de proveedor ejecutado.

El checkpoint debe publicar exactamente los 22 paths P9.2 sobre el parent
`2519b3d7bec00298b99ed0ea1c45e1f757378ad1` con el commit:

`feat(commerce): add customer collection foundation`
