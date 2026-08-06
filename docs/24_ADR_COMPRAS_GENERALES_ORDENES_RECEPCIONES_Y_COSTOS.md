# ADR 24 — Compras generales: órdenes, recepciones y costos

Fecha: 06/08/2026

Estado: aceptada por Dirección

Checkpoint de partida:

`004c74950fa5a77e40b2b46f39b596c318d2341e`

## 1. Contexto

SRCM ya posee proveedores privados por organización, ofertas vinculadas con
productos del catálogo y una compra directa de repuestos afectada a una orden
de Reparaciones. Esa compra especializada es inmutable y resuelve únicamente
el abastecimiento de una necesidad técnica concreta.

La auditoría de fundación confirmó que todavía no existe un módulo general de
Compras: no hay agregado propio, orden de compra, recepción general, rutas,
vistas ni pruebas específicas. La navegación conserva Compras deshabilitado.

El nuevo módulo debe registrar el compromiso comercial con un proveedor,
recibir mercadería en una o varias entregas y conservar el costo de adquisición
sin convertir la orden en la fuente de verdad del stock. Según la decisión ya
vigente de Inventario, los saldos se determinan por movimientos confirmados.

## 2. Decisión

Se crea el contexto `App\Domain\Purchase` y un agregado general compuesto por:

1. `PurchaseOrder`;
2. `PurchaseOrderLine`;
3. `PurchaseReceipt`;
4. `PurchaseReceiptLine`.

La orden representa qué se pidió y bajo qué condiciones comerciales. La
recepción representa qué se recibió realmente. Una recepción confirmada crea y
confirma de forma atómica un movimiento de inventario de tipo `receipt`.

No se creará un modelo ambiguo llamado `Purchase` que mezcle pedido, recepción,
documento del proveedor, pago y movimiento físico en un único registro.

## 3. Fronteras con las fundaciones existentes

`Supplier`, `SupplierOffer` y sus administradores permanecen en Comercio. El
módulo Compras los reutiliza sin duplicarlos.

Una línea de orden puede vincular opcionalmente una oferta activa del mismo
proveedor, producto y organización. La oferta es una referencia para preparar
la operación, pero no es evidencia histórica suficiente: al emitir la orden se
copian código del proveedor, descripción, moneda y costo acordado.

`ServicePartPurchase` permanece intacta dentro de Reparaciones. No se migrará,
reinterpretará ni modificará el historial congelado de Core 10–16. Un puente
futuro podrá permitir que una compra general atienda necesidades de servicio,
pero no forma parte del primer bloque.

## 4. Ciclo de vida de la orden

Los estados iniciales serán:

| Valor técnico | Significado |
| --- | --- |
| `draft` | Preparación editable, todavía sin compromiso |
| `issued` | Orden emitida e inmutable |
| `partially_received` | Posee al menos una recepción parcial |
| `received` | Todas las cantidades fueron recibidas |
| `cancelled` | Orden cancelada mediante transición explícita |

La emisión valida y congela proveedor, moneda, líneas, cantidades y costos. Una
orden emitida no se edita ni se elimina. Toda corrección posterior se expresa
mediante cancelación permitida o una nueva orden.

La cancelación de una orden emitida sólo se admite mientras no exista ninguna
recepción confirmada. Una orden parcialmente recibida o recibida no puede
cancelarse para ocultar mercadería ya ingresada.

## 5. Líneas y cantidades

Cada línea identifica obligatoriamente:

- producto del catálogo;
- cantidad ordenada;
- unidad base y escala de cantidad copiadas del producto;
- código y descripción comercial congelados;
- costo unitario acordado en unidades monetarias menores;
- subtotal calculado por el dominio;
- oferta de proveedor opcional.

Las cantidades respetan la escala del producto. Una misma línea puede recibirse
en varias entregas. La suma de recepciones confirmadas nunca puede superar la
cantidad ordenada.

El primer bloque rechaza sobrantes de recepción. La aceptación de excedentes
con autorización administrativa será una capacidad posterior y explícita.

## 6. Costos y moneda

Cada orden utiliza una única moneda ISO de tres letras. Los importes se guardan
como enteros en unidades menores; no se utilizan números de punto flotante.

La orden conserva:

- subtotal de mercadería;
- costo logístico esperado opcional;
- total esperado.

La recepción conserva el costo unitario efectivamente documentado por línea,
el costo logístico real opcional y su total real. Así SRCM puede demostrar
diferencias entre lo acordado y lo recibido sin reescribir la orden.

Registrar estos costos no implementa todavía valoración contable del
inventario, costo promedio, FIFO, cuentas por pagar ni resultado financiero.
Inventario continúa siendo la verdad cuantitativa; Compras conserva la evidencia
comercial necesaria para una valoración posterior.

## 7. Recepción física e Inventario

Una recepción pertenece a una sola orden y puede contener una parte de sus
líneas. Cada línea de recepción identifica:

- línea de orden;
- cantidad recibida;
- condición de inventario;
- ubicación de destino activa de la misma organización;
- costo unitario real;
- línea de movimiento de inventario resultante.

La confirmación se ejecuta dentro de una única transacción:

1. bloquea orden, líneas y cantidades ya recibidas;
2. valida organización, estado, escala y remanente;
3. crea la recepción y sus líneas;
4. crea un `InventoryMovement` de tipo `receipt`;
5. confirma el movimiento;
6. vincula cada línea de recepción con su línea de movimiento;
7. actualiza el estado de la orden.

Si falla el movimiento, no queda una recepción parcial. La orden y la recepción
no actualizan saldos directamente. La existencia visible continúa derivándose
exclusivamente de movimientos confirmados.

El movimiento utilizará `source_type = purchase_receipt`, el identificador
público de la recepción como `source_id` y la referencia documental disponible
como `source_reference`.

## 8. Documentos, identidad e idempotencia

Órdenes y recepciones tendrán `public_id` UUID y claves de idempotencia. La
emisión y la confirmación de recepción conservarán una huella SHA-256 del
contenido normalizado para impedir duplicaciones y detectar la reutilización
de una clave con datos diferentes.

La recepción admite número de remito, factura u otra referencia externa. Cuando
una referencia esté informada, su versión normalizada no podrá repetirse para
el mismo proveedor y organización sin una revisión explícita.

El documento externo no sustituye el identificador propio de SRCM y no concede
por sí solo validez fiscal. Facturación de compra, impuestos, percepciones,
retenciones y notas de crédito pertenecen a bloques posteriores.

## 9. Aislamiento multiorganización

Todas las tablas operativas de Compras incluyen `organization_id`. Ninguna
petición del navegador decide ese valor.

La base de datos protegerá mediante claves compuestas y restricciones
equivalentes en SQLite y MySQL que:

- orden y proveedor pertenezcan a la misma organización;
- oferta, proveedor y producto coincidan con la línea;
- recepción y orden pertenezcan al mismo tenant;
- ubicación y movimiento pertenezcan a la misma organización;
- una línea de recepción no se vincule con una línea de otra orden;
- una línea de movimiento ajena no pueda usarse como evidencia.

Las consultas y el binding de rutas se limitarán además mediante
`BelongsToOrganization`, `CurrentOrganization` y verificaciones explícitas.

## 10. Permisos

Compras incorpora capacidades separadas:

| Capacidad | Administrador | Operador | Consulta |
| --- | --- | --- | --- |
| `view-purchases` | Sí | Sí | Sí |
| `draft-purchase-orders` | Sí | Sí | No |
| `issue-purchase-orders` | Sí | Sí | No |
| `receive-purchases` | Sí | Sí | No |
| `cancel-purchase-orders` | Sí | No | No |

No se reutilizará `manage-commerce` como permiso general de Compras. La gestión
de proveedores y ofertas conserva sus permisos actuales, mientras que las
órdenes y recepciones reciben facultades operativas propias.

## 11. Inmutabilidad, auditoría y correcciones

No habrá rutas `destroy`. Las órdenes emitidas, recepciones confirmadas y sus
líneas serán inmutables.

Los cambios de estado se ejecutan por administradores de dominio y quedan
auditados con organización, actor, fecha y motivo. Una recepción equivocada no
se edita: su corrección física utilizará el mecanismo de reverso o devolución
del libro de inventario y, cuando corresponda, un documento comercial posterior.

La primera implementación no automatizará devoluciones a proveedor ni notas de
crédito, aunque Inventario ya posea el tipo físico `supplier_return`.

## 12. Primer bloque de implementación

Compras Bloque 1 implementará exclusivamente la fundación de dominio y base de
datos:

- enumeraciones de estado;
- modelos, migraciones y relaciones;
- datos de entrada inmutables;
- administrador de órdenes;
- administrador de recepciones;
- emisión, recepción parcial, recepción total y cancelación permitida;
- creación y confirmación atómica del movimiento de recepción;
- permisos y gates;
- pruebas de dominio, aislamiento, idempotencia e inmutabilidad;
- compatibilidad SQLite y MySQL/MariaDB.

No incluirá todavía superficie HTTP/UI. La navegación de Compras permanecerá
deshabilitada hasta que un bloque posterior publique listados, formularios y
expedientes operativos.

## 13. Criterios de verificación

El bloque no podrá cerrarse hasta demostrar que:

- una orden emitida no puede editarse ni eliminarse;
- una recepción parcial conserva correctamente el remanente;
- múltiples recepciones completan la orden sin excederla;
- una recepción confirmada produce exactamente un movimiento `receipt`
  confirmado y líneas vinculadas;
- un fallo de inventario revierte toda la recepción;
- las cantidades respetan la escala del producto;
- una oferta ajena, inactiva o incompatible es rechazada;
- ningún recurso puede cruzar organizaciones;
- las claves de idempotencia son repetibles sólo con la misma huella;
- consulta no puede emitir, recibir ni cancelar;
- operador no puede cancelar una orden emitida;
- SQLite y MySQL/MariaDB aplican las mismas invariantes;
- las pruebas focales y la suite integral permanecen verdes.

## 14. Alcance diferido

Quedan fuera de esta ADR y del Bloque 1:

- interfaz HTTP/UI;
- solicitudes internas y aprobaciones de compra;
- comparación automática de múltiples cotizaciones;
- aceptación autorizada de excedentes;
- devoluciones a proveedor y notas de crédito;
- facturas fiscales, impuestos y percepciones;
- cuentas por pagar y pagos a proveedores;
- conciliación bancaria y caja;
- valoración contable, costo promedio y FIFO;
- distribución avanzada de flete y otros costos indirectos;
- importación de remitos, facturas o planillas;
- consignaciones entrantes;
- números de serie, IMEI y lotes durante la recepción.

## 15. Consecuencias

### Positivas

- separa compromiso comercial de recepción física;
- admite entregas parciales sin inflar stock;
- conserva costos acordados y reales;
- integra Compras con el libro de Inventario sin duplicar saldos;
- mantiene intacta la fundación especializada de Reparaciones;
- establece permisos, idempotencia e invariantes antes de publicar interfaz.

### Costos y límites aceptados

- el primer bloque agrega varios modelos para evitar un registro monolítico;
- el costo queda documentado, pero todavía no valora existencias;
- la compra general no sustituye aún la compra directa de Reparaciones;
- Compras seguirá sin interfaz hasta completar la fundación y validarla.
