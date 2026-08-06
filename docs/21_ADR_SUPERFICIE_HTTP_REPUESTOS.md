# ADR 21 — Superficie HTTP/UI de repuestos afectados

## Estado

Aceptada.

## Contexto

Reparaciones ya posee un motor de dominio para:

- planificar necesidades de repuestos desde una línea aprobada;
- planificar repuestos correctivos cubiertos por garantía;
- distinguir stock propio de compra directa para una orden;
- registrar compras afectadas con proveedor, costos y documentación;
- consumir desde inventario mediante una salida confirmada;
- consumir desde una compra afectada sin inflar el stock general;
- preservar idempotencia, inmutabilidad y aislamiento por organización.

El expediente sólo contaba necesidades y compras. No existían gates, rutas, formularios ni controlador HTTP para operar esas capacidades.

## Decisión

Core 14 incorpora una superficie HTTP/UI privada y anidada bajo la orden de servicio.

### Autoridad del servidor

Para una reparación presupuestada, la cantidad requerida se deriva de la línea de repuesto perteneciente a la alternativa aprobada. El cliente HTTP no puede alterar esa cantidad.

Para un trabajo correctivo de garantía, la necesidad se deriva de la resolución vinculada al trabajo y no crea un presupuesto ficticio.

La organización, la orden, el trabajo, el origen y las relaciones entre líneas se validan nuevamente en el servidor y en `ServicePartManager`.

### Dos circuitos de abastecimiento

#### Stock propio

El consumo exige una ubicación activa. `ServicePartManager` crea y confirma una salida de inventario vinculada a la orden. El saldo visible se reduce por el movimiento confirmado.

#### Compra directa para la orden

La compra se registra como un hecho comercial afectado. No genera una recepción ni incrementa el stock general. El consumo debe identificar la línea de compra que entrega el repuesto.

### Escrituras

Toda escritura pasa por `ServicePartManager`. La capa HTTP no modifica directamente:

- necesidades;
- compras;
- líneas de compra;
- consumos;
- movimientos de inventario;
- estados de la orden.

### Identidad e idempotencia

Cada formulario mutable transporta una clave UUID con prefijo específico:

- `service-ui:part-requirement:`;
- `service-ui:part-purchase:`;
- `service-ui:part-consumption:`.

### Permisos

- Viewer: lectura del expediente.
- Operator y Admin: planificación de repuestos.
- Operator y Admin: registro de compras afectadas.
- Operator y Admin: consumo de repuestos.

### Persistencia

Core 14 no agrega migraciones ni altera el dominio validado. Necesidades, compras y consumos confirmados continúan siendo inmutables.

## Consecuencias

- El abastecimiento técnico queda operable desde el expediente.
- Una compra directa no se confunde con mercadería propia.
- El consumo desde stock conserva la verdad basada en movimientos confirmados.
- Calidad y entrega permanecen en Core 15.
- Venta, cobro y cierre comercial permanecen en Core 16.
