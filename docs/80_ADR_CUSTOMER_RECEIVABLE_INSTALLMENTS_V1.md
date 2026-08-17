# ADR 80 — Customer Receivable Installments V1

Estado: Aceptada para P9.5

Checkpoint de partida:
`5d214bcc9b4dacc112d7fbffcfd55a6528096300`

Fecha:
`2026-08-17`

## 1. Contexto

P9.1 incorporó una cuenta por cobrar económica única por venta. P9.2 separó
cobranza de aplicación y mantuvo el saldo como lectura derivada. P9.3 agregó
aging. P9.4 agregó política de crédito y excepción administrativa.

El relevamiento P9.5 confirmó que SRCM no posee todavía una foundation de
**cuotas propias**. Los campos `installments` ya existentes corresponden a
metadata de pagos con tarjeta y no representan deuda financiada por el comercio.

Dirección definió para V1:

- cuotas mensuales iguales;
- se informa cantidad de cuotas y primer vencimiento;
- cualquier diferencia de centavos se incorpora a la última cuota;
- las cobranzas se imputan FIFO: primero a la cuota pendiente más antigua.

## 2. Decisión

La deuda económica continúa siendo un único `CustomerReceivable`.

P9.5 agrega hechos hijos append-only:

- `CustomerReceivableInstallmentPlan`;
- `CustomerReceivableInstallment`.

No se crean varias cuentas por cobrar para simular cuotas.

El plan se reconoce únicamente para una venta todavía en estado `building`, en
la misma transacción de checkout y antes de confirmar la venta.

## 3. Regla de importes

Para una deuda `D` y `N` cuotas:

`base = floor(D / N)`

Las primeras `N - 1` cuotas poseen `base`.

La última cuota posee:

`D - base × (N - 1)`

Por lo tanto la suma de cuotas siempre coincide exactamente con la deuda,
incluidos los centavos de diferencia.

Cada cuota debe poseer al menos una unidad monetaria mínima; por eso
`D >= N` en unidades menores.

## 4. Regla de vencimientos

El primer vencimiento es informado por la operación.

Las cuotas siguientes vencen mensualmente usando la misma fecha nominal con
regla **sin overflow de mes**. Ejemplo:

- 31/01
- 28/02 o 29/02
- 31/03
- 30/04

El `due_on` histórico de `CustomerReceivable` conserva el primer vencimiento
para compatibilidad. Aging y política de crédito dejan de tratar el importe
completo como si venciera ese primer día y pasan a leer el cronograma.

## 5. Cobranza FIFO

`CustomerCollectionAllocation` continúa aplicándose contra la cuenta por cobrar,
no contra una cuota manual seleccionada.

La distribución por cuota es una lectura determinista:

1. sumar aplicaciones de cobranzas `confirmed` a la deuda;
2. consumir primero la cuota de menor `sequence`;
3. continuar con la siguiente sólo cuando la anterior quedó cubierta.

No se persiste un saldo mutable por cuota.

## 6. Read model canónico

P9.5 incorpora dos vistas derivadas:

- `customer_receivable_collection_totals`;
- `customer_receivable_installment_balances`.

La segunda produce, por deuda:

- una línea sintética para cuentas sin plan; o
- una línea por cuota propia reconocida.

Cada línea deriva:

- importe original;
- importe cobrado FIFO;
- pendiente;
- vencimiento;
- secuencia.

Ni el pendiente de deuda ni el pendiente de cuota se persisten como verdad
editable.

## 7. Aging

La Cuenta Corriente mantiene una fila agregada por deuda para no romper la
aplicación de cobranzas existente.

Esa fila incorpora el cronograma derivado y resume:

- próximo vencimiento pendiente;
- importe vencido exacto de las cuotas;
- mayor atraso;
- pendiente total.

El reporte de aging, en cambio, clasifica cada cuota abierta individualmente en
los buckets ya aceptados de P9.3. De esta forma una deuda puede tener parte
vencida y parte todavía al día sin inflar el vencido.

## 8. Política de crédito

P9.4 continúa siendo la política vigente:

- deuda vencida bloquea al Operador;
- sobrelímite bloquea al Operador;
- Administrador puede autorizar excepción con motivo.

P9.5 cambia únicamente la precisión del read model de vencido: para una deuda
con cuotas se computa sólo el pendiente de las cuotas cuyo vencimiento ya pasó.

Las guardas SQLite y MySQL/MariaDB de P9.4 se reemplazan por versiones que leen
el mismo read model de cuotas, evitando divergencia entre aplicación y DB.

## 9. Integridad

El plan y sus cuotas son append-only.

La base exige:

- 2 a 120 cuotas para un plan;
- deuda y venta en la misma organización;
- venta todavía `building`;
- primer vencimiento igual al `due_on` de la deuda;
- actor habilitado para crear CxC;
- número exacto de cuotas;
- suma exacta igual a la deuda;
- secuencias 1..N;
- importes iguales con ajuste sólo en la última;
- vencimientos estrictamente crecientes;
- prohibición de modificar o borrar plan/cuotas;
- prohibición de confirmar una venta con cuotas huérfanas o cronograma
  incompleto.

El modelo PHP agrega la validación mensual exacta `no overflow`.

## 10. Diferencia con cuotas de tarjeta

`CommercePayment.installments` sigue siendo evidencia del medio de pago
(tarjeta/procesador).

`CustomerReceivableInstallment` representa deuda financiada por el comercio.

Una venta pagada con tarjeta en 3 cuotas no crea un plan CxC. Una venta con
saldo pendiente en 3 cuotas propias sí lo crea.

## 11. Superficie operativa

En Venta, el saldo pendiente incorpora:

- cantidad de cuotas propias;
- primer vencimiento;
- explicación FIFO;
- vista previa de cuota base y última cuota cuando corresponde.

Cantidad `1` conserva el comportamiento de deuda simple.

En Cuenta Corriente:

- la deuda continúa siendo una sola fila aplicable;
- debajo se muestra su cronograma;
- se identifica cada cuota, vencimiento, cobrado y pendiente;
- se explica que la cobranza se aplica a la cuota más antigua primero.

En Aging:

- cada cuota abierta aparece como línea propia;
- se mantiene el conteo de deudas únicas separado del número de líneas de
  cuotas.

## 12. Fuera de alcance

P9.5 no implementa:

- cronogramas manuales con importes o fechas arbitrarias;
- elección manual de cuota al cobrar;
- intereses;
- punitorios;
- refinanciación;
- reprogramación;
- cancelación/reversión de plan;
- anticipo/seña;
- saldo a favor generado por sobrecobro.

## 13. Criterio de aceptación

P9.5 es GREEN si:

1. una deuda puede generar 2–120 cuotas mensuales;
2. la suma de cuotas coincide exactamente con la deuda;
3. los centavos residuales quedan en la última cuota;
4. las fechas mensuales usan no-overflow;
5. una cobranza se distribuye FIFO en lectura;
6. aging sólo considera vencida la porción efectivamente vencida;
7. P9.4 bloquea por una cuota vencida usando ese importe exacto;
8. Administrador conserva override con snapshot exacto;
9. cuotas de tarjeta no crean cuotas propias;
10. deuda simple sin plan conserva semántica previa;
11. plan/cuotas son inmutables en modelo y DB;
12. P9.1–P9.4 y checkout permanecen GREEN;
13. suite integral GREEN;
14. BD real local byte-a-byte intacta y sin migración real.

## 14. Continuidad

El siguiente corte de CxC podrá abordar anticipos/señas y su convergencia con
`CustomerCredit` sin modificar la verdad económica de deuda, cobranza o cuotas
definida hasta P9.5.
