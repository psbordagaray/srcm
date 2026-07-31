# SRCM — Relevo del Bloque 1 de Inventario

Fecha: 31/07/2026

Estado: implementación terminada y verificada; pendiente de push al momento de
redactar este relevo.

## 1. Mandato ejecutado

Se ejecutó únicamente el Bloque 1 del mandato aprobado por Dirección:

- auditoría del estado real;
- ADR de Inventario privado por organización;
- ubicaciones privadas y jerárquicas;
- configuración inicial idempotente de SULU TV;
- permisos separados;
- pruebas de aislamiento, integridad y operación;
- interfaz inicial de administración.

Checkpoint obligatorio de partida:

`f31aae0f458aa2e216205fd14126e69080cd7961`

La implementación comenzó después de confirmar rama, HEAD, sincronización,
árbol limpio y suite base de 174 pruebas con 1208 aserciones.

## 2. Decisión arquitectónica

La decisión completa quedó registrada en:

`docs/07_ADR_INVENTARIO_PRIVADO_POR_ORGANIZACION.md`

Se mantuvo la frontera aprobada:

- catálogo y conocimiento compartidos;
- ubicaciones e inventario privados por organización;
- ningún campo de stock o ubicación dentro del producto maestro;
- equipos de clientes bajo custodia fuera del inventario comercial propio.

## 3. Resultado funcional

SRCM permite representar una jerarquía flexible con los tipos iniciales:

- sucursal;
- depósito;
- sector;
- estantería;
- posición;
- recepción.

La organización es la raíz propietaria y no se duplica como ubicación
ficticia.

La interfaz permite:

- consultar el árbol y el camino completo de cada ubicación;
- filtrar por nombre, camino, tipo y estado;
- crear ubicaciones;
- editar nombre, tipo y padre;
- activar e inactivar sin borrar;
- ver acciones solamente con el permiso correspondiente.

## 4. Configuración inicial de SULU TV

El seeder idempotente crea exactamente:

1. Sucursal principal.
2. Depósito principal, hijo de la sucursal.
3. Recepción, hija del depósito.

Se ejecutó dos veces sobre la base local MySQL y permanecieron exactamente tres
registros, sin duplicados ni auditoría ficticia.

## 5. Controles APB implementados

- `organization_id` se resuelve en el servidor.
- El cliente no puede elegir ni reemplazar el tenant.
- El binding de rutas oculta ubicaciones ajenas.
- Una clave foránea compuesta rechaza padres de otra organización.
- La organización propietaria de una ubicación es inmutable.
- Se rechazan padres propios y ciclos jerárquicos.
- Una ubicación activa no puede depender de un ancestro inactivo.
- No se inactiva una ubicación con descendientes activos.
- No existen hermanos activos con nombres equivalentes.
- No existe ruta ni operación de borrado destructivo.
- Las mutaciones estructurales se ejecutan en transacciones y se serializan por
  organización.
- Creación, edición, reubicación y cambio de estado quedan auditados.

## 6. Permisos

Se incorporaron capacidades separadas:

- `view-inventory`;
- `manage-inventory-locations`.

Con los roles actuales:

- administrador: consulta y administra ubicaciones;
- operador: consulta;
- consulta: consulta.

No se creó un permiso general y omnipotente `manage-inventory`.

## 7. Verificación

Resultados finales de la implementación:

- pruebas de fundación: 7 aprobadas, 34 aserciones;
- pruebas HTTP de ubicaciones: 7 aprobadas, 76 aserciones;
- suite completa: 188 aprobadas, 1318 aserciones;
- migración aplicada correctamente sobre MySQL local;
- seeder verificado como idempotente sobre la base real;
- seis rutas operativas y ninguna ruta `show` o `destroy` innecesaria;
- revisión visual aprobada en listado y formulario de creación;
- `git diff --check` limpio en cada checkpoint.

## 8. Checkpoints del bloque

- `d1af536` — ADR de Inventario privado por organización.
- `951631d` — fundación de ubicaciones por organización.
- `f5a7185` — administración privada e interfaz de ubicaciones.

## 9. Alcance no iniciado

Por orden expreso de Dirección, no se avanzó al Bloque 2 ni se anticiparon:

- movimientos y líneas;
- confirmación inmutable;
- reversos y reemplazos;
- proyección o reconstrucción de saldos;
- disponibilidad y condiciones;
- stock negativo e incidencias;
- reservas y señas;
- conteos e importación inicial;
- unidades serializadas;
- costos o valoración.

El siguiente desarrollo deberá partir del HEAD final limpio y sincronizado de
este bloque y requerirá una instrucción expresa para comenzar el Bloque 2.
