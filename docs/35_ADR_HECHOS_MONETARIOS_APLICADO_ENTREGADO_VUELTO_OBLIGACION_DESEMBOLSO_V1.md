# ADR 35 — Hechos monetarios: aplicado, entregado, vuelto, obligación y desembolso V1

Estado: **Aceptado como contrato de arquitectura para P4E/P4F y evolución P8/P9**

Checkpoint publicado de referencia:
`1b2187bf5e709f583e3ee79db5ad8df528751116`
— `feat(finance): harden cash operations and attention`

## 1. Decisión

SRCM no modelará “dinero” como un único importe ambiguo.

Los hechos monetarios que responden preguntas distintas deben conservar identidad,
tiempo, autoridad y evidencia propias.

Regla general:

`hecho comercial ≠ declaración monetaria ≠ movimiento físico ≠ movimiento externo ≠ conciliación`

En particular:

`aplicado ≠ entregado ≠ vuelto`

y

`recepción ≠ obligación ≠ autorización ≠ ejecución ≠ débito/acreditación verificada ≠ conciliación`.

## 2. Dinero entrante: venta y cobro

En una venta:

- `CommerceSale.total_minor` expresa el total comercial;
- `CommercePayment.amount_minor` expresa cuánto de ese total queda aplicado por ese medio;
- en efectivo, `tendered_amount_minor` expresa cuánto dinero físico entrega el cliente;
- en efectivo, `change_amount_minor` expresa cuánto se devuelve como vuelto;
- el vuelto no cambia el total vendido ni el importe aplicado;
- el vuelto no es descuento, devolución posventa, reembolso ni gasto.

Foundation P4E soporta vuelto en el mismo medio efectivo:

`entregado - vuelto = aplicado`

Ejemplo:

`venta 9.000 → entrega 10.000 → vuelto efectivo 1.000 → aplicado 9.000`.

En esa foundation, el libro de caja puede conservar el efecto neto retenido de
ARS 9.000 sin inflar temporalmente el esperado a ARS 10.000.

## 3. Futuro vuelto multimedio

El vuelto multimedio no puede simularse alterando `amount_minor`.

Ejemplo futuro:

`venta 9.000 → cliente entrega 10.000 efectivo → SRCM devuelve 1.000 por billetera`.

La caja física retuvo ARS 10.000, mientras una cuenta electrónica desembolsó
ARS 1.000. El importe aplicado a la venta sigue siendo ARS 9.000.

Por lo tanto, la evolución deberá representar explícitamente:

- efectivo bruto recibido;
- devolución/vuelto ejecutado desde el medio elegido;
- efecto neto por cada cuenta/caja;
- vínculo con la venta original;
- autoridad y evidencia del desembolso.

No se reescribirán los cobros históricos P4E para fingir esa granularidad.

## 4. Historia y evidencia faltante

La ausencia histórica de `tendered_amount_minor` o `change_amount_minor` es un
dato válido: significa que SRCM no capturó esa evidencia.

Reglas:

- no inferir retrospectivamente entregado = aplicado;
- no inventar vuelto cero;
- no usar notas para reconstruir importes como si fueran verdad estructurada;
- una migración nueva enriquece; no falsifica historia.

## 5. Dinero saliente: compra, obligación y pago

P4F parte de una recepción ya confirmada, pero jamás la convierte por sí sola en
un desembolso.

Cadena vinculante:

`recepción física → obligación económica → autorización → ejecución del pago → movimiento de la cuenta/caja → verificación externa → conciliación`

Cada etapa responde una pregunta distinta:

- **recepción:** qué llegó físicamente;
- **obligación:** qué importe debe la organización, a quién, por qué y con qué vencimiento/condición;
- **autorización:** quién habilitó pagar hechos exactos;
- **ejecución:** quién hizo efectivo el pago y desde qué origen;
- **movimiento de cuenta/caja:** qué saldo operativo fue afectado;
- **verificación externa:** qué informó banco/procesador/entidad;
- **conciliación:** qué hechos quedaron vinculados y qué diferencias existen.

## 6. Obligaciones de proveedor y logística

Mercadería y logística no se fusionan por comodidad.

Una recepción puede originar o preparar, según política:

- obligación de mercadería al proveedor;
- obligación de flete al transportista;
- otras obligaciones explícitas futuras.

El beneficiario puede coincidir con el proveedor o ser otra parte comercial.

Casos obligatorios de diseño:

- pago total contra entrega;
- pago parcial;
- pago pendiente;
- anticipo previamente entregado;
- beneficiario distinto del proveedor;
- transportista autorizado;
- flete separado;
- diferencia entre orden y recepción;
- retenciones/comisiones/impuestos futuros;
- cancelación o corrección mediante hechos compensatorios, no borrado.

P4F debe ser la foundation operativa. P9 podrá generalizar cuentas por pagar,
vencimientos, aging, aplicaciones múltiples, estados de cuenta y contabilidad
auxiliar sin romper estos hechos.

## 7. Autoridad

Autorizar no mueve dinero.

Ejecutar no equivale a autorizar.

SRCM debe poder aplicar capacidades, alcance y umbrales por importe. Cuando una
política exija segregación:

`solicitante ≠ aprobador`

y, cuando el riesgo lo requiera:

`aprobador ≠ ejecutor`.

Los actores y timestamps se conservan por separado.

La autorización debe quedar vinculada mediante fingerprint a los hechos
sensibles: organización, obligación, beneficiario, importe, moneda, origen,
motivo y contexto. Si cambia un hecho autorizable, la autorización anterior no
puede reutilizarse silenciosamente.

## 8. Atención Operativa

Operational Attention es proyección de trabajo, no segunda verdad monetaria.

Debe poder proyectar, entre otros:

- obligación pendiente de autorización;
- pago autorizado pendiente de ejecución;
- pago rechazado/cancelado que requiere conocimiento del solicitante;
- diferencia que exige resolución;
- vencimiento o bloqueo que requiere intervención.

La campana y Dashboard deben llevar por deep-link al workflow dueño del hecho.

## 9. Origen del pago

`FinancialAccount` identifica la cuenta/destino financiero.

Para efectivo:

`usuario → turno abierto → CashRegister → cash_box → ejecución del pago → CashMovement out`

Reglas:

- obligación pendiente no reduce efectivo esperado;
- autorización pendiente/aprobada no reduce efectivo esperado;
- sólo la ejecución física válida crea el egreso de caja;
- un pago parcial reduce caja sólo por el importe ejecutado;
- no puede ejecutarse efectivo desde una caja sin turno válido compatible.

Para banco, billetera u otro medio:

- la ejecución declarada no inventa un `CashMovement`;
- puede existir un hecho financiero saliente propio;
- el débito externo verificado y la conciliación permanecen separados.

## 10. Inmutabilidad, idempotencia y compensación

Los hechos confirmados de cobro, obligación, autorización y ejecución deben ser
trazables e idempotentes según corresponda.

No se “corrige” dinero confirmado editando filas históricas.

Las correcciones se modelan con:

- rechazo/cancelación antes de ejecutar;
- reversa;
- devolución;
- nota de crédito/débito;
- ajuste autorizado;
- hecho compensatorio explícito.

El mecanismo exacto depende del dominio futuro, pero nunca del borrado de
historia para hacer cuadrar una pantalla.

## 11. Conciliación

Ni un cobro electrónico declarado ni un pago saliente declarado prueban por sí
solos que la entidad financiera acreditó o debitó el dinero.

La conciliación enlaza evidencia externa con los hechos esperados y conserva:

- bruto;
- neto;
- comisión;
- retención;
- diferencia;
- timestamps;
- proveedor;
- external ID;
- revisión y resolución.

## 12. Invariantes transversales

- monto y moneda siempre explícitos;
- tenant isolation;
- beneficiario y origen estructurados cuando existan;
- tiempos operativos normales fijados por servidor;
- no PAN/CVV;
- no cross-tenant;
- no pago automático por confirmar stock;
- no autoaprobación cuando la política exige segregación;
- no movimiento silencioso para “cuadrar”;
- no inferencia retrospectiva presentada como evidencia;
- no reutilizar `notes` como sustituto de un hecho financiero estructurado.

## 13. Relación con otros bloques

- **P4E:** aplicado/entregado/vuelto en cobro efectivo;
- **P4F:** obligaciones y pagos operativos de proveedor/flete;
- **P5–P7:** adapters, movimientos externos, conciliación e importadores;
- **P8:** devoluciones/reembolsos, distintos del vuelto del momento de venta;
- **P9:** cuentas por cobrar/pagar generalizadas;
- **P20:** capacidades, alcances y superficies;
- **Operational Attention:** distribución del trabajo pendiente según autoridad.

Este ADR es el puente monetario para que esos bloques evolucionen sin volver a
fusionar hechos que SRCM ya decidió mantener separados.
