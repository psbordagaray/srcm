# ADR 27 — Dashboard operativo por organización

Estado: aceptada.

Checkpoint de decisión: `14bfef14c411db94646aa13bc930ae78e8b610b4`.

## Contexto

La ruta `dashboard` ya existía, pero su vista era una maqueta con métricas fijas en cero, mensajes de módulos todavía “pendientes” y una sección de búsqueda futura. Ese contenido dejó de representar el estado real del MVP.

SRCM ya posee fuentes operativas consolidadas para Inventario, Reparaciones, Compras, Ventas, Clientes, Proveedores, Personas y Auditoría.

## Decisión

El Dashboard será una proyección de lectura privada por organización. No crea una nueva fuente de verdad y no mantiene contadores propios.

`DashboardReader` obtiene todos los datos desde las fuentes existentes y siempre resuelve la organización mediante `CurrentOrganization`.

El tablero muestra:

- reparaciones abiertas y listas para entregar;
- órdenes de compra emitidas o parcialmente recibidas;
- ventas confirmadas del día;
- totales de ventas agrupados por moneda;
- posiciones y productos con disponibilidad;
- posiciones con déficit;
- identidades, clientes activos y proveedores activos;
- distribución de posiciones disponibles por ubicación;
- reparaciones, compras y ventas recientes;
- actividad auditada reciente.

## Magnitudes que no se suman

No se presenta un “stock total” sumando cantidades de productos con unidades base distintas.

No se suman importes de monedas diferentes. Los totales de venta se agrupan por `currency_code`.

## Seguridad

El Dashboard está dentro de `RequireOrganization`.

Todas las consultas privadas usan `organization_id` de la organización activa o lectores tenant-scoped existentes.

No acepta `organization_id` desde navegador.

Las acciones rápidas respetan las abilities existentes; un Viewer mantiene acceso de lectura pero no recibe enlaces de mutación.

## Persistencia

No hay migraciones ni tablas nuevas.

El Dashboard no altera Inventario, Compras, Ventas, Reparaciones, Personas, Clientes, Proveedores ni Auditoría.

## Fuera de alcance

- objetivos comerciales;
- comparaciones históricas o BI;
- margen o rentabilidad;
- cuentas por cobrar/pagar;
- valuación de inventario;
- alertas configurables;
- búsqueda global;
- importación;
- gráficos históricos.
