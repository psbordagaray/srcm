# ADR 26 — Directorio general de identidades comerciales

Estado: aceptada.

Checkpoint de decisión: `b087db1db28ddac8223295692c2148b24775d187`.

## Contexto

ADR 25 estableció que `BusinessParty` es la única identidad comercial privada por organización y que `Customer` y `Supplier` son roles independientes sobre esa identidad. El roadmap de MVP aún conserva el ítem Personas, mientras existe un scaffold histórico `Person` sin atributos operativos ni relaciones de negocio.

Para que el comercio pueda operar con una única verdad de identidad, SRCM necesita una superficie general para consultar y mantener personas y organizaciones aunque todavía no posean rol Cliente o Proveedor.

## Decisión

`BusinessParty` será también la entidad del Directorio de Personas e Identidades.

El directorio permite:

- listar y buscar identidades de la organización activa;
- filtrar por persona/organización y por rol comercial;
- crear una identidad sin asignarle automáticamente un rol;
- editar nombre, tipo, documento fiscal y datos de contacto;
- ver un expediente transversal con roles Cliente/Proveedor, ventas y actividad de Reparaciones;
- reutilizar la misma identidad cuando posteriormente se asigna un rol Cliente o Proveedor.

No existe una segunda entidad operativa `Person`.

## Integridad APB

La política de coincidencia, adopción y anti-duplicado se centraliza en `BusinessPartyIdentityManager`.

`CustomerManager` y `SupplierManager` delegan en ese mismo componente para evitar divergencia de reglas entre superficies.

La creación directa desde el Directorio nunca fusiona silenciosamente identidades existentes. Documento fiscal, correo o nombre equivalente provocan rechazo y revisión. La adopción automática queda reservada a la asignación de roles cuando documento o correo identifican de forma inequívoca la misma `BusinessParty` y tipo/nombre son consistentes.

La edición de una identidad modifica la verdad compartida; por lo tanto el cambio es visible desde todos sus roles y contextos.

## Permisos

- `view-business-parties`: admin, operator y viewer.
- `manage-business-parties`: admin y operator.
- No existe `destroy`.

## Tenant

Todas las consultas y mutaciones se resuelven dentro de la organización activa. El navegador no suministra un `organization_id` confiable.

## Scaffold histórico Person

`Person` y la tabla `people` no se eliminan en este bloque. Permanecen sin uso operativo hasta una limpieza posterior demostrablemente segura.

No se crean rutas, controladores ni relaciones de negocio nuevas sobre ese scaffold.

## Fuera de alcance

- CRM y segmentación;
- campañas y fidelización;
- cuenta corriente o crédito;
- importación masiva;
- deduplicación asistida por operador;
- merge manual de identidades;
- eliminación física;
- asignación automática de roles Cliente/Proveedor desde el Directorio.
