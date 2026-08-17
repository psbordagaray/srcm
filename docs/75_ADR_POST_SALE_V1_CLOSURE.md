# ADR 75 — Cierre de Posventa Comercial V1

Estado: Aceptada para P8.5.8

Checkpoint de partida:
`1070218a493bc7321cfef079622eb3bfc58173f0`

Fecha de cierre:
`2026-08-16`

## 1. Contexto

P8 implementó la posventa comercial completa sobre hechos append-only, sin
reescribir retrospectivamente la venta original.

La cadena documental aceptada comprende ADR 53–74 y cubre:

- apertura de caso desde venta original;
- recepción física parcial/total con condición real;
- resolución económica;
- saldo a favor;
- reembolso en efectivo;
- instrucción y evidencia de refund externo;
- selección y ejecución de cambio;
- diferencia positiva, cero y negativa;
- consumo convergente de `AccountCredit`;
- segregación de funciones;
- aislamiento por organización;
- idempotencia y guardas de base de datos.

P8.5.8 ejecutó una auditoría integral read-only sobre el checkpoint P8.5.7.

## 2. Evidencia de cierre P8.5.8

La auditoría integral registró:

- HEAD local y remoto idénticos en `1070218a493bc7321cfef079622eb3bfc58173f0`;
- repo limpio y staging vacío al inicio;
- cadena ADR 53–74 aceptada;
- 17 rutas HTTP de Posventa exactas;
- 21 clases requeridas presentes;
- intake/request: GREEN;
- recepción física: GREEN;
- resolución: GREEN;
- outcomes de dominio: GREEN;
- outcomes HTTP y ejecución final: GREEN;
- convergencia/consumo de crédito: GREEN;
- guard de refund externo: GREEN;
- suite completa: 715 tests / 5948 assertions;
- BD real byte-a-byte sin cambios;
- ninguna migración ejecutada sobre BD real;
- ninguna mutación del repo durante la auditoría;
- ningún commit/push durante la auditoría;
- ningún HTTP externo de proveedor;
- ningún smoke externo de refund repetido.

## 3. Decisión

Se declara:

`P8 — POSVENTA COMERCIAL V1 = CERRADO / GREEN`

El cierre significa que la capacidad V1 está completa en el repositorio y que
sus contratos de dominio, superficies HTTP, guardas, trazabilidad, idempotencia,
segregación y regresiones quedaron validados.

No significa despliegue operativo de migraciones sobre una instalación real.
La auditoría confirmó deliberadamente que la BD local real conserva las tablas
de Posventa aún no migradas. La aplicación de migraciones pertenece al proceso
de despliegue/control operacional correspondiente y no debe inferirse a partir
de este cierre documental.

## 4. Excepción controlada — Mercado Pago Refund

El cierre P8 no promueve artificialmente la salud de refund de Mercado Pago.

Permanece vigente ADR 65:

- clasificación externa:
  `AMBER_PROVIDER_SANDBOX_READ_MODEL_ANOMALY_PERSISTENT`;
- health Refund:
  `DEGRADED_REFUND_SMOKE_REQUIRED`;
- automation gate:
  `BLOCKED`.

La razón es externa al contrato de Posventa de SRCM: el sandbox aceptó la
simulación oficial pero no materializó el estado canónico `refunded`.

SRCM ya posee:

- contrato productivo de refund;
- adapter;
- instruction;
- durable dispatch;
- evidencia/polling provider-neutral;
- idempotencia;
- health gate fail-closed.

Por lo tanto, mientras el gate permanezca bloqueado, Posventa continúa cerrada
y segura: una condición externa no observada nunca se convierte en dinero ni
estado inventado dentro de SRCM.

La automatización real de refund sólo podrá habilitarse mediante una futura
evidencia compatible con el contrato de health existente. Esa reapertura será
un cambio de readiness del proveedor, no una reapertura automática de P8.

## 5. Límites de este cierre

Quedan explícitamente fuera de P8 V1:

- notas de crédito y débito fiscales, que pertenecen a P10;
- cuentas por cobrar y cuentas por pagar, que pertenecen a P9;
- despliegue/migración de una instalación productiva, que pertenece al proceso
  operacional;
- nuevas capacidades posventa no incluidas en el contrato V1;
- promoción del gate Mercado Pago Refund sin evidencia nueva.

## 6. Regla de continuidad

P8 sólo se reabre si aparece una brecha demostrable en sus contratos V1 o si una
nueva capacidad posventa exige modificar esos contratos.

Cambios de proveedor, fiscalidad, despliegue o capacidades de fases posteriores
se trabajan en su bloque correspondiente y no invalidan este cierre por
defecto.

## 7. Checkpoint de cierre

El commit documental de cierre debe:

1. partir exactamente de `1070218a493bc7321cfef079622eb3bfc58173f0`;
2. modificar sólo `docs/06_ROADMAP.md` y este ADR;
3. revalidar la superficie Posventa y la suite completa;
4. demostrar BD real sin cambios;
5. preservar el gate Mercado Pago Refund degradado/bloqueado;
6. stagear sólo los dos documentos;
7. crear y publicar el commit:
   `docs(commerce): close post-sale V1`;
8. finalizar con HEAD local/remoto idénticos y repo limpio.
