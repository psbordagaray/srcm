# ADR 14 — Repuestos y compras afectados a la orden

Fecha: 02/08/2026

Estado: aceptada por Dirección

Checkpoint de partida:

`57f25e3c52c937ec180f57208a783824fea55dfd`

## 1. Contexto

Un repuesto instalado en una reparación puede provenir del stock propio o
comprarse específicamente para una orden. En este segundo caso suele pasar
directamente del proveedor al banco de trabajo y luego al activo del cliente,
sin convertirse en mercadería disponible para otras ventas.

El caso real del Motorola E22i distingue claramente el módulo comprado a
Daniel de Word Cell, el costo de mensajería y la mano de obra propia de cambio
de pantalla y limpieza de software. Mezclar esos hechos inflaría el stock,
ocultaría el proveedor real o confundiría costo con precio presupuestado.

## 2. Decisión

Cada repuesto aprobado se representa mediante una necesidad inmutable que
vincula:

- orden y trabajo en el que se instalará;
- línea de repuesto de la alternativa aprobada;
- producto de catálogo, condición, unidad y cantidad exacta;
- origen previsto: stock propio o compra directa para la orden;
- actor, fecha, idempotencia y huella canónica.

La cantidad requerida debe coincidir con la línea aprobada por el cliente y
respetar la precisión del producto. Una misma línea presupuestada no puede
imputarse dos veces.

## 3. Repuesto desde stock propio

El consumo desde stock crea y confirma atómicamente un movimiento de salida.
La línea del libro conserva producto, condición, cantidad y ubicación de
origen, mientras el movimiento referencia la orden de servicio.

Si el saldo es insuficiente, la confirmación y el consumo completo se
revierten. No queda un consumo técnico sin su salida física ni una salida sin
su vínculo con la reparación. El circuito excepcional de stock negativo sigue
siendo explícito y no se concede implícitamente por tratarse de una reparación.

## 4. Compra directa para la orden

Planificar una compra directa lleva la orden a `awaiting_parts`. La compra
afectada conserva:

- proveedor de la organización;
- moneda y fecha real;
- documento y notas;
- costo de cada repuesto;
- costo logístico separado;
- total exacto en unidad monetaria menor;
- usuario que registró la operación.

Una compra puede contener varias líneas y abastecer varios repuestos de la
misma orden. Cuando todas las necesidades de compra directa quedan cubiertas,
la orden vuelve a `in_progress`.

El consumo de una compra directa referencia su línea de compra y no crea un
movimiento de recepción ni una disponibilidad general. Así, el repuesto que
pasa del proveedor al equipo del cliente no infla existencias.

## 5. Separación económica

El precio aprobado por el cliente permanece en el presupuesto. El costo real
del repuesto y la mensajería permanecen en la compra. La mano de obra propia o
tercerizada permanece en los trabajos. Ninguno de esos hechos sobrescribe a
los demás.

Esta separación permite calcular margen y conciliación en bloques posteriores
sin perder la procedencia de cada importe.

## 6. Finalización del trabajo

Un trabajo no puede registrar resultado `completed` mientras alguna necesidad
de repuesto asociada no esté consumida completamente. La regla se aplica en el
servicio de dominio y también mediante trigger de base de datos.

La condición evita declarar una reparación terminada con una pieza pendiente,
comprada pero no instalada o retirada de stock sin trazabilidad.

## 7. Seguridad e integridad

Administradores y operadores activos pueden planificar, comprar y consumir
repuestos. El rol de consulta no puede alterar el circuito.

Las claves foráneas compuestas y los triggers de SQLite y MySQL rechazan:

- repuestos fuera de la alternativa aprobada;
- cruces entre organizaciones, órdenes, trabajos y proveedores;
- compras que exceden la cantidad requerida;
- consumos superiores a lo requerido o comprado;
- consumos con dos fuentes o sin fuente;
- movimientos de stock no confirmados o incompatibles;
- finalización con repuestos pendientes;
- actualización o eliminación de los hechos registrados.

## 8. Consecuencias

SRCM puede demostrar qué repuesto se aprobó, dónde se compró, cuánto costó,
qué logística requirió, quién lo registró, en qué trabajo se instaló y si salió
o no del stock propio.

Este bloque no incorpora todavía pantallas HTTP, órdenes de compra generales,
cuentas corrientes de proveedores, lotes o series de repuestos, control de
calidad, entrega, facturación ni conciliación antifraude. Esas funciones se
apoyarán en los hechos preservados aquí.
