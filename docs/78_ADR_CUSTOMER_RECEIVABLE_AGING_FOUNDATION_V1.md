# ADR 78 — Customer Receivable Aging Foundation V1

Estado: Aceptada para P9.3

Checkpoint de partida:
`d0dadb5ff40bf37d8ca68dbd3dd965f136b05059`

Fecha:
`2026-08-16`

## 1. Contexto

P9.1 incorporó la cuenta por cobrar append-only y P9.2 incorporó la cobranza
posterior con aplicación parcial o multi-deuda. El relevamiento P9.3 confirmó
que:

- `due_on` ya forma parte de `CustomerReceivable`;
- existe una marca booleana derivada de vencido en la cuenta corriente;
- no existe un `CustomerReceivableAgingReader`;
- no existen snapshots o tablas de aging;
- no existe todavía una política ni límite de crédito;
- el saldo a favor del cliente ya posee una foundation separada mediante
  `CustomerCreditGrant` y `CustomerCreditConsumption`.

## 2. Decisión

P9.3 implementa aging como **read model derivado**. No agrega columnas de saldo,
no agrega snapshots persistentes y no modifica la deuda original.

La exposición continúa siendo:

`pendiente = deuda original - SUM(aplicaciones de cobranzas confirmadas)`

El aging clasifica ese pendiente según `due_on` y una fecha de corte.

## 3. Buckets Foundation

La foundation utiliza una convención operativa fija:

- Al día;
- Vencido 1–30 días;
- Vencido 31–60 días;
- Vencido 61–90 días;
- Vencido 91+ días;
- Sin vencimiento;
- Cancelado, sólo para lectura histórica de una deuda sin saldo.

Estos buckets son clasificación informativa. No autorizan, bloquean ni cambian
condiciones comerciales.

## 4. Fecha de corte

La UI usa la fecha local de aplicación al abrir el reporte.

El reader admite una fecha de corte explícita para pruebas y futuros reportes
históricos, sin materializar snapshots.

## 5. Exposición por cliente

El reporte organizacional agrega por cliente y moneda:

- pendiente total;
- pendiente vencido;
- cantidad de deudas abiertas;
- mayor cantidad de días de atraso.

Las monedas nunca se mezclan.

## 6. Cuenta corriente individual

La pantalla de cuenta corriente conserva:

- deuda original;
- cobrado;
- pendiente;
- vencimiento;
- historial de cobranzas.

P9.3 agrega la clasificación aging por deuda y un acceso al reporte global.

Una deuda totalmente cancelada sigue visible en la cuenta individual como
hecho histórico, pero deja de integrar la exposición abierta del reporte aging.

## 7. Autoridad

P9.3 es estrictamente read-only y reutiliza la autoridad vigente de lectura de
cuenta corriente. Administrador, Operador y Consulta pueden leer la exposición
de su organización.

No se incorpora una capacidad de mutación nueva.

## 8. Fuera de alcance

P9.3 no define todavía:

- límite de crédito;
- bloqueo duro o advertencia por límite;
- override de límite;
- cupo por moneda;
- cuotas propias;
- anticipos/señas;
- intereses o mora;
- saldo a favor por sobrepago;
- compensación automática entre deuda y `CustomerCredit`;
- snapshot histórico persistido.

En particular, elegir política hard/soft de límite y sus overrides es una
decisión comercial distinta del aging y no se infiere silenciosamente.

## 9. Integridad

P9.3 no agrega migraciones.

`CustomerReceivableAgingReader` centraliza la derivación de:

- cobrado acumulado;
- pendiente;
- atraso;
- bucket.

`CustomerReceivableBalanceReader` consume esa misma verdad para evitar dos
fórmulas divergentes.

## 10. Criterio de aceptación

P9.3 es GREEN si:

1. cada deuda abierta cae exactamente en un bucket determinista;
2. una cobranza parcial reduce la exposición sin reescribir la deuda;
3. una deuda cancelada sale del aging abierto pero queda auditable;
4. el reporte agrega por cliente y moneda sin mezclar monedas;
5. la cuenta corriente individual muestra el mismo aging derivado;
6. Consulta puede leer y no obtiene nuevas capacidades de mutación;
7. P9.1, P9.2, crédito, clientes y checkout quedan GREEN;
8. la suite integral queda GREEN;
9. la BD real permanece byte-a-byte intacta;
10. no hay migración real, HTTP externo ni commit/push en el corte de
    implementación.

## 11. Continuidad

Después de aceptar P9.3, el próximo slice de CxC que requiere política explícita
es límites de crédito. Esa decisión debe construirse sobre la exposición
derivada de P9.1–P9.3 y nunca sobre un saldo manual mutable.

## 12. Aceptación P9.3

P9.3 quedó validado GREEN antes de su checkpoint de publicación:

- aging implementado como read model derivado, sin snapshots ni campos de saldo persistidos;
- buckets Al día / 1–30 / 31–60 / 61–90 / 91+ / Sin vencimiento validados;
- clasificación Cancelado conservada para auditoría histórica individual;
- cobranza parcial reduce exposición sin reescribir la deuda;
- exposición agregada por cliente y moneda validada;
- `CustomerReceivableBalanceReader` reutiliza la misma verdad de aging;
- Consulta conserva lectura sin nuevas capacidades de mutación;
- focal P9.3 aging: 6 tests / 39 assertions;
- suite completa: 734 tests / 6081 assertions;
- regresiones de P9.1, P9.2, crédito, clientes y checkout: GREEN;
- BD real byte-a-byte sin cambios;
- ninguna migración real ejecutada;
- ningún HTTP externo de proveedor ejecutado;
- política/límite de crédito continúa explícitamente fuera de este corte.

El checkpoint debe publicar exactamente los 8 paths P9.3 sobre el parent
`d0dadb5ff40bf37d8ca68dbd3dd965f136b05059` con el commit:

`feat(commerce): add customer receivable aging foundation`
