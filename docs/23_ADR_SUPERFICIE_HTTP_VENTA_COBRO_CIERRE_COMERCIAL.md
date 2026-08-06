# ADR 23 — Superficie HTTP/UI de venta, cobro y cierre comercial

## Estado

Aceptada.

## Contexto

El dominio comercial ya confirma en una única transacción:

- ventas de reparación, productos o ambas cosas;
- líneas técnicas derivadas del presupuesto aprobado;
- líneas de producto vinculadas con una salida confirmada de inventario;
- uno o más pagos que cancelan exactamente el total;
- cliente, receptor, moneda, fechas y referencias;
- idempotencia y huella antifraude;
- inmutabilidad de la venta, sus líneas y sus pagos.

La reparación entregada también queda protegida contra sustitución: no puede omitirse y cobrarse como una venta minorista aislada al mismo cliente.

Faltaba exponer ese núcleo mediante HTTP/UI privada por organización.

## Decisión

Core 16 incorpora cuatro rutas comerciales:

1. listado de ventas;
2. formulario de nueva venta;
3. confirmación de la venta;
4. detalle inmutable de la operación.

También integra el cierre comercial dentro del expediente de Reparaciones.

### Autoridad del servidor

El navegador no envía líneas técnicas ni precios del servicio.

Cuando se selecciona una reparación, `CommerceCheckoutManager` deriva:

- la orden entregada;
- la evidencia física de entrega;
- la última revisión presupuestada;
- la decisión aprobada;
- la alternativa seleccionada;
- todos sus conceptos y su subtotal.

Los productos sí requieren catálogo, ubicación, condición, cantidad y precio. El nombre comercial se toma nuevamente del catálogo.

### Pagos

La interfaz admite pagos combinados.

Cada medio conserva importe, referencia, notas y fecha. Los medios distintos de efectivo requieren referencia. La suma se valida nuevamente en el dominio y debe cancelar exactamente el total.

Los importes HTTP se reciben como unidades monetarias con hasta dos decimales y se convierten a unidades menores sin utilizar números flotantes.

### Integración con Reparaciones

Una orden entregada sin venta muestra una acción explícita de liquidación. Después de confirmar la venta, el expediente enlaza la operación comercial inmutable.

La pantalla de ventas presenta además la cantidad de reparaciones entregadas pendientes de liquidación.

### Permisos

- `view-commerce-sales`: todos los roles activos de la organización.
- `record-commerce-sales`: administradores y operadores activos.
- Viewer conserva acceso de lectura y no puede confirmar operaciones.

### Aislamiento

Controlador, Form Request, rutas y consultas exigen la organización activa.

Clientes, ubicaciones, reparaciones y ventas de otra organización se rechazan sin revelar su existencia.

### Persistencia

Core 16 no agrega migraciones ni altera el motor comercial validado.

Toda escritura continúa pasando por `CommerceCheckoutManager`. La capa HTTP no inserta ni actualiza directamente ventas, líneas, pagos o movimientos.

## Consecuencias

- La venta mixta queda operable desde navegador.
- El servicio aprobado no puede ser editado en mostrador.
- La salida física y el ingreso del dinero quedan unidos atómicamente.
- El expediente técnico y la operación comercial se enlazan en ambas direcciones.
- Facturación fiscal, caja, conciliaciones, anulaciones y cuentas corrientes permanecen fuera de este bloque.
