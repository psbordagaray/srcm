# ADR 07 — Inventario privado por organización

Fecha: 31/07/2026
Estado: aceptada
Origen: mandato de Dirección para Inventario
Checkpoint de partida: `f31aae0f458aa2e216205fd14126e69080cd7961`

## 1. Contexto

SRCM ya posee una fundación multiorganización operativa. El catálogo maestro y
el conocimiento son compartidos, mientras que proveedores, ofertas y auditoría
privada pertenecen a la organización activa resuelta por el servidor.

Inventario debe incorporarse sin guardar existencia, disponibilidad, reserva,
costo ni ubicación dentro del producto maestro compartido. El primer bloque
implementa solamente la decisión arquitectónica y las ubicaciones privadas y
jerárquicas. El libro de movimientos, los saldos, las reservas, los conteos y
las unidades serializadas pertenecen a bloques posteriores.

## 2. Decisión

Las ubicaciones de inventario se modelarán como información privada de una
organización mediante `InventoryLocation` y la tabla `inventory_locations`.

Cada ubicación tendrá:

- organización propietaria obligatoria;
- ubicación padre opcional;
- nombre y nombre normalizado;
- tipo operativo;
- estado activo o inactivo;
- fechas de creación y actualización.

No existirá borrado destructivo. Una ubicación fuera de uso se inactivará y su
historia permanecerá disponible.

## 3. Frontera compartida y privada

El producto maestro continúa siendo compartido y no recibe campos de stock o
ubicación.

Son privados por organización:

- la jerarquía de ubicaciones;
- la futura existencia atribuida a cada ubicación;
- los futuros movimientos, saldos, reservas, conteos e incidencias;
- la auditoría operativa relacionada.

Los equipos de clientes recibidos para diagnóstico, reparación o custodia no
son inventario comercial propio y no utilizarán estas ubicaciones para
convertirse en stock vendible.

## 4. Jerarquía

`parent_id` permitirá construir una jerarquía flexible. La organización es la
raíz propietaria y no se duplicará como una ubicación ficticia.

Los tipos iniciales serán:

| Valor técnico | Nombre visible |
| --- | --- |
| `branch` | Sucursal |
| `warehouse` | Depósito |
| `sector` | Sector |
| `shelf` | Estantería |
| `position` | Posición |
| `receiving` | Recepción |

Los tipos se almacenarán como cadenas validadas por una enumeración de PHP, no
como un `ENUM` rígido de base de datos. Así podrán incorporarse nuevas
ubicaciones físicas u operativas sin reconstruir la tabla.

La jerarquía no impondrá una única combinación rígida de tipos porque SRCM debe
servir a distintos rubros. La interfaz podrá sugerir una estructura habitual,
pero el dominio protegerá siempre las invariantes.

## 5. Invariantes de integridad

1. Toda ubicación pertenece a una organización existente.
2. Una ubicación padre debe pertenecer a la misma organización que su hija.
3. Una ubicación no puede ser su propio padre.
4. Reubicar una ubicación no puede crear ciclos.
5. Una ubicación activa no puede depender de un padre inactivo.
6. No se puede inactivar una ubicación mientras conserve descendientes
   activos.
7. No se permiten nombres equivalentes entre ubicaciones hermanas activas.
8. Ninguna petición del cliente determina `organization_id`.
9. Cambiar una URL o un ID no permite consultar ni modificar ubicaciones de
   otra organización.
10. No existe una operación `destroy`.

La base de datos utilizará una clave candidata compuesta
`(organization_id, id)` y una clave foránea compuesta
`(organization_id, parent_id)` para rechazar cruces de tenant aun cuando se
intente omitir la capa HTTP.

Las reglas de ciclos, estado del padre y duplicados equivalentes se validarán
en el dominio dentro de una transacción.

## 6. Resolución del tenant

`InventoryLocation` reutilizará `BelongsToOrganization`:

- la organización activa será resuelta por `CurrentOrganization`;
- la creación asignará el tenant en el servidor;
- el binding de rutas quedará limitado a la organización activa;
- las consultas de listado aplicarán `forOrganization()` explícitamente;
- los controladores verificarán nuevamente la pertenencia antes de mutar.

Enviar `organization_id` en un formulario, una URL o una petición no cambiará
la organización propietaria.

## 7. Permisos

Este bloque incorporará capacidades separadas:

- `view-inventory`: consultar las ubicaciones de la organización activa;
- `manage-inventory-locations`: crear, editar, reubicar, activar o inactivar
  ubicaciones.

Con los roles existentes:

| Rol | Ver ubicaciones | Administrar ubicaciones |
| --- | --- | --- |
| Administrador | Sí | Sí |
| Operador | Sí | No |
| Consulta | Sí | No |

No se creará un permiso general `manage-inventory`. Los permisos futuros para
recibir, transferir, reservar, contar, ajustar o corregir se incorporarán en
sus bloques correspondientes.

El rol de administrador representa por ahora las facultades del dueño o
administrador. Este bloque no agrega un nuevo rol de dueño.

## 8. Auditoría

La creación, actualización, reubicación, activación e inactivación de una
ubicación será auditable mediante la infraestructura existente. Se reutilizará
el observer actual mientras continúe siendo técnicamente genérico, aunque su
nombre histórico sea `CatalogAuditObserver`.

La auditoría conservará organización, autor, fecha, evento y valores
modificados. No se crearán eventos ficticios durante la carga idempotente de la
configuración inicial.

## 9. Configuración inicial de SULU TV

Un seeder propio e idempotente creará, dentro de la organización cuyo slug es
`sulu-tv`, la estructura mínima:

1. Sucursal principal (`branch`).
2. Depósito principal (`warehouse`), hijo de la sucursal.
3. Recepción (`receiving`), hija del depósito.

El seeder podrá ejecutarse más de una vez sin duplicar ubicaciones ni cambiar
sus relaciones correctas. Las estanterías y posiciones concretas podrán
crearse desde la interfaz según la distribución física real de SULU TV.

## 10. Interfaz inicial

La interfaz mostrará la jerarquía y el camino legible de cada ubicación. Los
miembros podrán consultar; solo quienes posean
`manage-inventory-locations` verán acciones de administración.

Las operaciones serán explícitas y guiadas:

- crear ubicación;
- editar nombre o tipo;
- cambiar de ubicación padre sin producir ciclos;
- activar;
- inactivar cuando no existan descendientes activos.

No habrá campos de stock, costo, reserva ni disponibilidad en estas pantallas.

## 11. Alternativas descartadas

### Guardar ubicación en el producto maestro

Se descarta porque un mismo producto puede existir simultáneamente en muchas
organizaciones, condiciones y ubicaciones.

### Crear una jerarquía global compartida

Se descarta porque revelaría estructura operativa privada y permitiría cruces
entre organizaciones.

### Utilizar una lista plana

Se descarta porque SULU TV necesita localizar mercadería hasta estantería o
posición y porque una lista plana pierde contexto físico.

### Borrar ubicaciones fuera de uso

Se descarta porque rompería trazabilidad histórica y permitiría ocultar
errores. Se utilizará inactivación.

### Implementar ahora movimientos o saldos

Se descarta en este bloque para respetar el orden aprobado y evitar anticipar
estructuras sin cerrar primero la fundación de ubicaciones.

## 12. Consecuencias

### Positivas

- aislamiento multiorganización coherente con la fundación existente;
- ubicación precisa hasta estantería o posición;
- estructura extensible a múltiples rubros;
- protección de base de datos contra padres de otro tenant;
- configuración inicial reproducible;
- historial preservado sin borrado destructivo.

### Costos y límites aceptados

- la validación de ciclos requiere lógica de dominio adicional;
- inactivar una rama exige resolver primero sus descendientes activos;
- todavía no existe stock porque su fuente de verdad se implementará con el
  libro de movimientos del Bloque 2.

## 13. Criterios de verificación del Bloque 1

El bloque no podrá cerrarse hasta demostrar que:

- el seeder es idempotente;
- cada organización ve solamente sus ubicaciones;
- un ID ajeno responde sin revelar el recurso;
- el servidor ignora cualquier `organization_id` recibido;
- la base rechaza una relación padre-hija entre organizaciones;
- no pueden crearse ciclos ni padres propios;
- los permisos autorizan y deniegan cada operación prevista;
- no existe ruta de borrado;
- los cambios quedan auditados;
- las pruebas relacionadas y la suite completa están verdes.

## 14. Alcance diferido

Quedan expresamente fuera de esta ADR y de su implementación inmediata:

- movimientos y líneas;
- saldos y disponibilidad;
- condiciones de mercadería;
- stock negativo e incidencias;
- reservas y señas;
- conteos e importación inicial;
- unidades serializadas;
- costos y valoración;
- documentos contractuales y custodia de equipos de clientes.
