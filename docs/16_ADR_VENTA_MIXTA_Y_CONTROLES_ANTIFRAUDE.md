# ADR 16 — Venta mixta y controles antifraude

Fecha: 03/08/2026

Estado: aceptada por Dirección

Checkpoint de partida:

`77ebc8ada1f3318a3f7fc2a16f0097e7e4197a3e`

## 1. Contexto

La entrega de una reparación puede combinarse en mostrador con productos de
venta: funda, glass, auriculares, teclado u otros artículos. El cobro debe
conservar ambos hechos sin confundir la intervención técnica con mercadería.

Esa separación también es un control interno. Un empleado no debe poder cobrar
una limpieza de software y registrarla como auriculares para apropiarse del
dinero o del producto. La operación tiene que demostrar simultáneamente qué
servicio fue aprobado, qué mercadería salió y cómo ingresó el dinero.

## 2. Decisión

Se incorpora una confirmación comercial atómica con tres hechos inmutables:

1. venta;
2. líneas de servicio o producto;
3. pagos que cancelan exactamente el total.

La venta puede ser de servicio, minorista o mixta. Permanece internamente en
`building` mientras se insertan sus evidencias dentro de una única transacción
y sólo resulta operativa cuando pasa a `confirmed`.

## 3. Servicio no editable

Cuando la venta incluye una reparación, SRCM exige que la orden esté entregada
y toma la última revisión presupuestada. Esa revisión debe poseer una decisión
aprobada y la moneda debe coincidir.

Las líneas de servicio se copian automáticamente desde la alternativa
aprobada, manteniendo concepto, cantidad, precio e identificador original. El
usuario que cobra no introduce ni modifica el precio técnico. La base de datos
comprueba que estén todas las líneas y que su subtotal sea exactamente el de
la alternativa.

## 4. Productos y salida física

Cada producto agregado identifica catálogo, ubicación, condición, cantidad y
precio. El nombre mostrado se obtiene del catálogo; no puede reemplazarse por
una descripción engañosa.

Antes de confirmar la venta se crea y confirma una salida del libro de
inventario. Cada línea comercial queda vinculada con su línea de movimiento.
Si no existe saldo suficiente, toda la transacción se revierte y no quedan ni
venta, ni pagos, ni un movimiento parcial.

## 5. Pagos exactos

La venta admite pagos combinados y conserva medio, importe, referencia, notas,
receptor y fecha. Los medios distintos de efectivo requieren referencia. La
suma de los pagos debe cancelar exactamente el total; no se aceptan diferencias
silenciosas.

Este hecho comercial no es todavía un comprobante fiscal ni una conciliación
de caja. Facturación electrónica, cuentas corrientes, cierres y anulaciones
requieren bloques propios sin reescribir la venta confirmada.

## 6. Control de sustitución

Una venta asociada a reparación sólo se confirma si incluye la totalidad del
presupuesto aprobado. Las líneas de mercadería no pueden ocupar ese lugar
porque requieren evidencia de salida de inventario y utilizan otro tipo de
línea.

Además, si un cliente identificado posee una orden entregada sin liquidar,
SRCM rechaza registrar para él una venta minorista aislada. La orden queda
consultable mediante `unsettledDelivered`, por lo que tampoco desaparece si
un usuario intenta omitirla del circuito comercial.

Las ventas anónimas continúan admitidas para mostrador. Su riesgo residual se
controlará con caja, supervisión y reportes de excepciones; ningún sistema
puede inferir por sí solo que un comprador anónimo es el receptor de una
reparación si el operador oculta deliberadamente esa identidad.

## 7. Integridad y seguridad

Administradores y operadores activos pueden confirmar ventas; consulta sólo
puede verlas. Claves compuestas, restricciones únicas y triggers equivalentes
en SQLite y MySQL rechazan:

- vincular evidencia de otra organización;
- liquidar una orden no entregada o una revisión no vigente;
- alterar los conceptos aprobados o disfrazar un producto;
- confirmar subtotales, líneas o pagos incompletos;
- usar movimientos no confirmados o que no sean una salida de esa venta;
- actualizar o eliminar venta, líneas o pagos confirmados.

La clave de idempotencia y la huella de contenido impiden duplicar una
operación por reintentos y detectan su reutilización con datos diferentes.

## 8. Consecuencias

SRCM puede representar en una sola liquidación la reparación entregada, los
artículos agregados y uno o más medios de pago, conservando la procedencia de
cada importe y el movimiento físico de cada unidad.

El siguiente avance operativo debe exponer este núcleo mediante pantallas de
ventas y reparaciones, junto con el tablero de órdenes entregadas pendientes de
liquidación. Facturación fiscal y caja se mantienen como etapas explícitas.
