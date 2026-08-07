# ADR 30 — Usuarios y permisos por organización

Estado: aceptada.

Checkpoint de decisión: `9ad75215b164f2fb5db08d948739a6fbb6527591`.

## Contexto

SRCM ya posee `users`, `organization_memberships` y el enum `UserRole`.

Los módulos privados más recientes resuelven permisos desde la membresía activa mediante `CurrentOrganization::roleFor()`. Sin embargo, `manage-catalog` todavía consultaba `users.role`, un rol global legado que puede divergir de la membresía seleccionada.

El alta pública de usuarios no está expuesta por `routes/auth.php`. Existe además un observer histórico que bootstrappea la membresía cuando hay exactamente una organización activa; se conserva por compatibilidad con la fundación y los seeders, pero no será la vía operativa de alta de personal.

## Decisión

El rol efectivo de autorización es el rol de `organization_memberships` para la organización activa.

`users.role` se conserva como compatibilidad técnica y valor legado, pero no debe otorgar privilegios operativos por sí solo.

`manage-catalog` pasa a resolverse mediante `CurrentOrganization::roleFor()`, igual que Comercio, Compras, Reparaciones e Inventario.

## Superficie de administración

Se incorpora `Usuarios y permisos` dentro de la organización activa.

Todos los miembros activos pueden consultar el directorio.

Sólo Administrador puede:

- crear una cuenta nueva y su membresía;
- agregar a la organización un usuario existente por email;
- reactivar una membresía inactiva;
- cambiar el rol de otro miembro;
- desactivar o reactivar el acceso de otro miembro.

No existe `destroy`.

## Alta de usuario

Cuando el email no existe:

- nombre y contraseña inicial son obligatorios;
- la cuenta se marca verificada porque es provisionada por un Administrador autenticado;
- `users.role` se fija deliberadamente en `viewer`;
- la membresía recibe el rol seleccionado;
- la organización actual del usuario queda configurada.

El alta usa `User::withoutEvents()` para evitar que el observer histórico de bootstrap cree una membresía implícita.

Cuando el email ya existe y la cuenta está activa, no se duplica el usuario. Se crea o reactiva únicamente la membresía.

Una cuenta eliminada globalmente no puede reactivarse desde esta superficie.

## Guardas administrativas

- un Administrador no puede cambiar su propio rol;
- un Administrador no puede desactivar su propio acceso;
- una operación no puede dejar a la organización sin Administrador activo;
- una membresía de otra organización no puede ser consultada ni modificada;
- al desactivar la membresía que era la organización actual del usuario, SRCM selecciona otra membresía activa o deja `current_organization_id` en null;
- las mutaciones se registran en Auditoría.

## Roles

### Administrador

Acceso a configuración sensible, administración de miembros, auditoría y operaciones críticas.

### Operador

Operación diaria permitida por las reglas de cada módulo.

### Consulta

Lectura operativa sin mutaciones.

## Persistencia

No hay migraciones nuevas.

La fundación existente de usuarios y membresías es suficiente para el Bloque 1.

## Fuera de alcance

- roles personalizados;
- permisos por usuario individual;
- matrices de permisos editables;
- invitaciones por email;
- expiración temporal de membresías;
- autenticación multifactor;
- SSO;
- recuperación administrativa de cuentas eliminadas;
- cambio forzado de contraseña en primer ingreso.
