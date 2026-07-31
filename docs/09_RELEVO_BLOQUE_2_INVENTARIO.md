# SRCM — Relevo del Bloque 2 de Inventario

Fecha: 31/07/2026

Estado: implementación terminada, verificada y sincronizada; relevo preparado
para formalizar el cierre ante Dirección.

## 1. Mandato ejecutado

Se ejecutó el Bloque 2 del mandato aprobado por Dirección:

- libro privado de movimientos y líneas;
- confirmación transaccional e inmutable;
- reversos y reemplazos enlazados;
- proyección de saldos verificable y reconstruible;
- idempotencia y defensas de concurrencia;
- permisos separados por operación.

Checkpoint de partida del bloque:

`f35d75c9a467c30230ed5166997bbf437eaf06e5`

Checkpoint de implementación terminado y publicado:

`587e8973bd9178195436a2a3f8e74a4efffb2d41`

Rama:

`feature/core-entity`

Al cierre, `HEAD` y `origin/feature/core-entity` coinciden y el árbol de trabajo
está limpio.

## 2. Tesis arquitectónica aplicada

Se implementó la decisión aprobada por Dirección:

> Los movimientos confirmados e inmutables son la fuente de verdad. Los saldos
> son proyecciones rápidas, verificables y reconstruibles.

Consecuencias efectivas:

- no existe una acción genérica para editar stock;
- un borrador no modifica existencias;
- confirmar aplica el efecto completo dentro de una transacción;
- un movimiento confirmado no puede editarse ni eliminarse;
- corregir no altera el original: crea un reverso y un reemplazo vinculados;
- si la proyección deriva de la historia, puede detectarse y reconstruirse;
- la organización, el actor, el motivo y las fechas quedan conservados.

## 3. Modelo implementado

### 3.1 Movimientos

`inventory_movements` conserva:

- organización;
- identificador público;
- tipo y estado;
- autor y confirmador;
- fecha efectiva, de registro y de confirmación;
- motivo;
- origen documental u operativo;
- clave de idempotencia privada por organización;
- vínculos de reverso y reemplazo;
- metadatos.

El vocabulario inicial incluye:

- saldo inicial;
- recepción;
- salida;
- transferencia;
- devolución de cliente;
- devolución a proveedor;
- ajuste positivo;
- ajuste negativo;
- reverso.

### 3.2 Líneas

`inventory_movement_lines` conserva por cada renglón:

- organización y movimiento;
- secuencia asignada por el servidor;
- producto maestro compartido;
- condición;
- origen y destino, según el tipo;
- cantidad y unidad ingresadas;
- factor de conversión;
- cantidad y unidad base;
- observaciones.

Las relaciones movimiento-organización y ubicación-organización están
protegidas con claves foráneas compuestas. Una línea no puede asociar un
movimiento o una ubicación de otro tenant.

### 3.3 Saldos proyectados

`inventory_balances` proyecta la cantidad por:

- organización;
- producto;
- ubicación;
- condición.

La dimensión es única y conserva versión para detectar cada actualización. El
saldo no tiene formulario ni mecanismo de edición directa.

## 4. Confirmación, idempotencia y concurrencia

La confirmación:

- bloquea la organización, el movimiento, sus líneas y las posiciones
  afectadas;
- relee el estado dentro de la transacción;
- valida unidades, cantidades, producto y ubicaciones activas;
- aplica una transferencia como un único hecho atómico;
- rechaza saldos insuficientes en el flujo ordinario actual;
- confirma una sola vez aunque exista doble clic o reintento;
- conserva actor y fecha de confirmación.

La creación de borradores:

- determina organización, actor, estado, secuencia y cantidades base en el
  servidor;
- rechaza productos inactivos y relaciones ajenas;
- normaliza la petición antes de persistirla;
- usa una huella canónica del contenido;
- con la misma clave y el mismo contenido devuelve el movimiento existente;
- con la misma clave y contenido diferente falla sin modificar datos.

El bloqueo por organización serializa las creaciones que compiten dentro del
mismo tenant. Los índices únicos constituyen una segunda defensa en la base.

## 5. Inmutabilidad y correcciones

La inmutabilidad se protege en dos niveles:

1. modelos y servicios de dominio;
2. disparadores de base de datos compatibles con SQLite y MySQL.

Una modificación o eliminación directa de un movimiento confirmado o de sus
líneas es rechazada aun cuando se intente evitar Eloquent.

La corrección atómica:

- exige administrador activo;
- bloquea el movimiento original;
- crea un reverso exacto y un reemplazo;
- enlaza ambos con el original;
- calcula el efecto neto conjunto antes de proyectarlo;
- evita que un estado negativo transitorio invalide una corrección cuyo
  resultado final es válido;
- es idempotente y no crea una segunda corrección.

El original permanece confirmado, visible e inmutable.

## 6. Verificación y reconstrucción de saldos

Se incorporaron cuatro responsabilidades separadas:

- cálculo desde movimientos confirmados;
- comparación entre historia y proyección;
- informe de diferencias;
- reconstrucción transaccional.

Los borradores no participan del cálculo. Una reconstrucción repetida produce
el mismo resultado y solo puede ejecutarla un administrador activo de la
organización correspondiente.

## 7. Cantidades, unidades y fraccionamiento

La fundación de cantidades se adelantó porque era necesaria para que el libro
no naciera limitado a artículos enteros.

Cada producto define:

- unidad base;
- escala decimal admitida.

Reglas implementadas:

- la unidad `unit` con escala cero rechaza fracciones;
- una unidad fraccionable conserva la precisión configurada;
- no existe redondeo silencioso;
- la línea conserva cantidad ingresada, unidad ingresada, factor de conversión
  y cantidad base;
- la equivalencia se comprueba nuevamente al confirmar;
- el fraccionamiento se configura por producto, no mediante un interruptor
  global de la organización.

La prueba de 215 litros es solamente un escenario demostrativo: representa la
apertura de dos tambores de 200 litros y ventas fraccionadas por 185 litros. No
existe un límite funcional de 215 litros.

## 8. Permisos

No se creó un permiso omnipotente `manage-inventory`.

Matriz implementada:

| Operación | Administrador | Operador | Consulta |
| --- | --- | --- | --- |
| Ver inventario | Sí | Sí | Sí |
| Recibir | Sí | Sí | No |
| Registrar salida | Sí | Sí | No |
| Transferir | Sí | Sí | No |
| Procesar devoluciones | Sí | Sí | No |
| Saldo inicial y ajustes | Sí | No | No |
| Corregir | Sí | No | No |
| Reconstruir saldos | Sí | No | No |

El reverso no puede crearse mediante el creador general. Solo nace dentro del
flujo específico de corrección.

La autorización se comprueba al crear y al confirmar, además de exponerse como
Gates tenant-aware para las futuras rutas e interfaces.

## 9. Controles APB implementados

- El cliente no determina `organization_id`.
- Una clave o ID ajeno no permite operar sobre otro tenant.
- La base rechaza relaciones cruzadas de movimiento y ubicación.
- Una transferencia no puede quedar aplicada a medias.
- Una repetición no duplica movimientos ni saldos.
- Una clave idempotente no puede reutilizarse con otro contenido.
- Los productos por unidad no aceptan decimales.
- Las conversiones adulteradas se rechazan atómicamente.
- Las líneas confirmadas y su cabecera son inmutables incluso por SQL directo.
- El saldo visible puede verificarse y repararse desde la historia.
- Las correcciones conservan original, reverso y reemplazo.
- Las tareas administrativas dependen de una membresía activa de la
  organización, no del rol global enviado por el cliente.

## 10. Endurecimientos de base incorporados

Durante la validación cruzada se detectaron y corrigieron dos debilidades
preexistentes que afectaban la confiabilidad del entorno:

- `DatabaseSeeder` pasó a ser idempotente y conserva usuario, membresía y
  organización de prueba sin duplicarlos;
- MySQL recibió protección explícita para impedir modificación o eliminación
  directa de auditoría, igualando la defensa ya comprobada en SQLite.

Ambas correcciones poseen pruebas de regresión.

## 11. Verificación final

Suite focalizada final:

- 19 pruebas aprobadas;
- 112 aserciones.

Suite completa SQLite:

- 219 pruebas aprobadas;
- 1478 aserciones;
- cero fallos y cero errores.

Suite completa MySQL 8.4.3:

- 219 pruebas aprobadas;
- 1478 aserciones;
- cero fallos y cero errores.

La misma revisión confirmó:

- `git diff --check` limpio;
- configuración temporal MySQL eliminada;
- entorno normal restaurado a SQLite;
- rama local y remota sincronizadas;
- árbol de trabajo limpio.

## 12. Checkpoints del bloque

- `0a0deb4` — fundación del libro de movimientos.
- `a1242f4` — confirmación y proyección de saldos.
- `7f85ca2` — idempotencia del seeder e inmutabilidad de auditoría.
- `6262161` — inmutabilidad del libro en la base de datos.
- `b142389` — verificación y reconstrucción de saldos.
- `aabddea` — corrección atómica de movimientos.
- `587e897` — permisos y creación segura de borradores.

## 13. Alcance deliberadamente no iniciado

No se implementaron:

- disponibilidad calculada para venta;
- flujo explícito de stock negativo;
- incidencias de negativo y su regularización;
- reservas, señas o liberaciones;
- sesiones de conteo y diferencias;
- importación y conciliación del stock inicial;
- unidades serializadas, S/N o IMEI;
- costos o valoración;
- interfaz operativa de movimientos.

En el flujo ordinario actual, una salida insuficiente falla de forma segura. La
decisión aprobada de permitir negativos con advertencia, segunda confirmación,
motivo e incidencia pertenece al Bloque 3 y no debe resolverse debilitando esa
validación.

## 14. Recomendación a Dirección

Dirección puede aprobar el cierre del Bloque 2 sobre el checkpoint
`587e8973bd9178195436a2a3f8e74a4efffb2d41`.

El siguiente desarrollo recomendado es el Bloque 3 — Disponibilidad,
condiciones y negativos. Debe comenzar por auditar qué parte de condiciones y
cantidades ya quedó estructuralmente resuelta, y luego diseñar el flujo APB de
negativo con advertencia, segunda confirmación, motivo inmutable e incidencia,
sin anticipar reservas.
