# ADR 10 — Disponibilidad y Override de stock negativo

Fecha: 31/07/2026

Estado: aceptada por Dirección

Origen: mandato de Inventario y decisión de Dirección del 31/07/2026

Checkpoint de partida:

`5942006cffbb745d5c0d5c131f2f3a41d0c5d1ab`

## 1. Contexto

El Bloque 2 dejó implementado un libro privado por organización con
movimientos confirmados e inmutables, saldos reconstruibles, correcciones
atómicas, cantidades exactas e idempotencia.

En el flujo ordinario actual, una salida que supera el saldo es rechazada. Esa
defensa no debe eliminarse ni reemplazarse por un parámetro confiado al
cliente. Dirección aprobó permitir stock negativo únicamente mediante un flujo
excepcional, advertido, motivado y trazable.

También debe distinguirse:

- stock físico;
- cantidad disponible para ofrecer;
- déficit existente;
- condición real de la mercadería.

## 2. Decisión de Dirección

Una salida negativa requiere autorización administrativa.

Con los roles actuales:

- el Operador puede preparar e intentar confirmar una salida;
- si la salida produciría negativo, la confirmación se detiene;
- un Administrador activo de la misma organización debe emitir el Override;
- el rol Consulta no puede iniciar ni aprobar la operación.

La segunda confirmación consume un Override real del Administrador, no es un
segundo clic libre del mismo Operador.

## 3. Credenciales y Override

Un Administrador nunca debe entregar su usuario, contraseña o sesión a otra
persona. Compartir credenciales destruye la atribución de responsabilidad y
permite que una acción del Operador aparezca falsamente como realizada por el
Administrador.

Si el Administrador no está físicamente presente, SRCM permitirá emitir el
Override remotamente desde su propia sesión, incluso desde un teléfono.

`InventoryNegativeOverride` será un objeto explícito del dominio. El Override:

- se concede a una solicitud concreta;
- sirve una sola vez;
- queda ligada al movimiento y contenido exactos;
- identifica al Operador autorizado y al Administrador otorgante;
- no convierte al Operador en Administrador;
- no habilita otros negativos;
- no habilita ajustes, correcciones ni reconstrucciones;
- deja de ser válida si cambia el movimiento o el saldo relevante.

El identificador público del Override no funciona como permiso al portador:
solo puede consumirlo el usuario autorizado y dentro de la organización
correcta.

Este Override puntual es la forma aprobada de «pasar derechos».

No se implementará por ahora una delegación abierta por turno, día o monto.
Una política más amplia requerirá otra decisión de Dirección.

## 4. Definiciones de cantidad

Para cada combinación de organización, producto, ubicación y condición:

### Stock físico

Es el saldo firmado proyectado desde movimientos confirmados. Puede ser
positivo, cero o negativo.

### Disponibilidad

Es la cantidad que puede ofrecerse sin crear una excepción.

Mientras Reservas no esté implementado:

`disponible = máximo(stock físico, 0)`

Cuando existan reservas activas:

`disponible = máximo(stock vendible - reservas activas, 0)`

La disponibilidad nunca se muestra como cantidad negativa.

### Déficit

Representa la magnitud del saldo físico negativo:

`déficit = máximo(-stock físico, 0)`

SRCM debe mostrar simultáneamente disponibilidad cero, saldo físico negativo y
déficit. No debe esconder el faltante detrás de un cero.

## 5. Condiciones

La disponibilidad se calcula y muestra por separado para:

- nuevo;
- usado;
- reacondicionado;
- dañado o para reparar;
- exhibición o demostración.

Todas las condiciones pueden venderse por decisión de Dirección, pero deben
mostrarse de forma notoria. Una condición no puede consumir saldo de otra ni
sustituirse silenciosamente.

El libro ya conserva la condición en cada línea y el saldo ya la incluye como
dimensión. El Bloque 3 agregará la lectura operativa y la presentación APB; no
duplicará esa información en el producto maestro.

## 6. Flujo ordinario

1. El usuario prepara un movimiento de salida.
2. SRCM bloquea y relee las posiciones afectadas.
3. Si todas conservan saldo suficiente, confirma normalmente.
4. Si alguna quedaría negativa, no confirma nada.
5. El movimiento permanece en borrador.
6. No se modifica ningún saldo ni se crea todavía una incidencia.

El rechazo ordinario existente permanece como comportamiento por defecto.

## 7. Solicitud de excepción

Cuando la confirmación detecta un negativo potencial, SRCM crea o devuelve una
solicitud idempotente que contiene como mínimo:

- organización;
- movimiento borrador;
- Operador solicitante;
- fecha y hora;
- motivo obligatorio propuesto;
- huella del contenido del movimiento;
- posiciones afectadas;
- saldo actual de cada posición;
- cantidad de salida;
- saldo proyectado;
- estado de la solicitud.

Estados iniciales:

- pendiente;
- aprobada;
- rechazada;
- invalidada;
- cumplida.

Aprobar la solicitud emite un Override separado con estados:

- activo;
- consumido;
- revocado;
- invalidado.

No se crea una solicitud válida si el movimiento ya fue confirmado, cancelado
o pertenece a otra organización.

## 8. Pantalla de advertencia

La interfaz debe mostrar, sin depender del color como único indicador:

- producto;
- condición;
- ubicación;
- saldo físico actual;
- cantidad que se intenta retirar;
- saldo resultante;
- déficit que se crearía;
- motivo;
- identidad del solicitante;
- advertencia de que se generará una incidencia auditable.

La acción principal no se llamará simplemente «Aceptar». Usará un texto
explícito como:

`Autorizar salida con stock negativo`

## 9. Emisión administrativa del Override

El Administrador otorgante debe autenticarse con su propia cuenta y pertenecer
activamente a la misma organización.

Antes de emitir el Override, SRCM vuelve a bloquear y releer:

- organización;
- movimiento y líneas;
- solicitud;
- saldos afectados.

Si cambió el movimiento o cualquier saldo relevante, la solicitud se invalida
y debe generarse una advertencia nueva con los valores actualizados.

Si los datos permanecen vigentes, se crea un único Override activo ligado a:

- solicitud;
- movimiento;
- huella del contenido;
- saldos y resultados autorizados;
- Operador autorizado;
- Administrador otorgante.

Reintentar la misma aprobación devuelve el mismo Override y no genera otro.

El cliente no puede enviar un `allow_negative`, rol, organización, saldo ni
resultado proyectado como fuente de verdad.

## 10. Consumo y confirmación excepcional

El Operador autorizado ejecuta nuevamente la confirmación indicando el
Override. SRCM no confía en los datos del navegador: carga el objeto, comprueba
su destinatario y vuelve a validar todo.

Un Override válido se consume dentro de la misma transacción que:

1. confirma el movimiento;
2. proyecta el nuevo saldo;
3. crea la incidencia y sus líneas;
4. registra solicitante y otorgante;
5. conserva el motivo inmutable;
6. marca el Override como consumido;
7. marca la solicitud como cumplida.

Si alguna parte falla, no se confirma el movimiento, no se modifica el saldo y
no se consume el Override.

Un reintento devuelve el resultado existente y no duplica movimiento,
incidencia ni Override.

## 11. Incidencia de stock negativo

Una salida puede afectar varias posiciones. Se utilizará una cabecera de
incidencia vinculada al movimiento y líneas inmutables por cada combinación
afectada.

La incidencia conserva:

- organización;
- movimiento que creó el negativo;
- solicitante y otorgante del Override;
- motivo;
- fecha de apertura;
- estado actual;
- producto, ubicación y condición por línea;
- saldo previo;
- cantidad aplicada;
- saldo resultante;
- déficit incremental creado por cada línea;
- responsable y explicación de resolución.

Estados funcionales iniciales:

- abierta;
- en revisión;
- resuelta.

La historia de cambios de estado será acumulativa y no se sobrescribirá. La
incidencia no se borra aunque el saldo vuelva a ser positivo.

Si una posición ya era negativa, una salida nueva registra solamente el
incremento adicional del déficit:

`déficit creado = déficit resultante - déficit previo`

Así cada incidencia explica cuánto agravó la situación sin apropiarse del
faltante de movimientos anteriores.

## 12. Regularización

Cada confirmación posterior que incremente una posición con déficit debe
comprobar si la regulariza total o parcialmente.

- La cantidad ingresada se imputa primero a las líneas de incidencia más
  antiguas todavía pendientes.
- Cada imputación conserva incidencia, línea, movimiento regularizador,
  cantidad, actor y fecha.
- Una regularización parcial reduce el déficit pendiente, pero no resuelve la
  incidencia.
- Una regularización total fija `regularized_at` cuando todas sus líneas fueron
  compensadas.
- La incidencia y su historia permanecen consultables.
- Regularizar cantidad no equivale a cerrar la revisión administrativa.
- Una incidencia regularizada que estaba abierta o en revisión permanece
  pendiente de resolución administrativa.
- Resolver exige déficit pendiente igual a cero, Administrador, fecha y
  explicación.

Esta imputación FIFO se refiere únicamente a la explicación del déficit. No es
costeo FIFO ni anticipa valoración de inventario.

## 13. Productos serializados

Las unidades serializadas no existen todavía. Cuando se incorporen, nunca
podrán utilizar este flujo para quedar en negativo.

El permiso administrativo no podrá saltear esa prohibición.

## 14. Permisos

Se incorporarán capacidades separadas:

- `view-inventory-availability`;
- `request-inventory-negative`;
- `override-inventory-negative`;
- `view-inventory-negative-incidents`;
- `review-inventory-negative-incidents`.

Matriz inicial:

| Capacidad | Administrador | Operador | Consulta |
| --- | --- | --- | --- |
| Ver disponibilidad | Sí | Sí | Sí |
| Solicitar negativo | Sí | Sí | No |
| Autorizar negativo | Sí | No | No |
| Ver incidencias | Sí | No | No |
| Revisar o resolver incidencias | Sí | No | No |

Un Administrador que inicia su propia salida también debe atravesar la
advertencia explícita. Puede emitirse un Override a sí mismo porque ambas
acciones quedan atribuidas y separadas, pero no existe una vía silenciosa.

## 15. Aislamiento e integridad

- Todas las tablas privadas incluyen organización.
- Movimiento, solicitud, Override, incidencia, ubicación y líneas deben
  pertenecer a la misma organización.
- Se usarán claves compuestas cuando protejan relaciones tenant-aware.
- Los identificadores públicos no revelarán existencia de recursos ajenos.
- Las transiciones críticas se ejecutarán en servicios de dominio y
  transacciones.
- Las defensas importantes se replicarán en la base cuando SQLite y MySQL lo
  permitan de forma mantenible.

## 16. Pruebas obligatorias

- saldo suficiente confirma sin solicitud;
- saldo insuficiente no modifica nada;
- Operador no puede emitir su propio Override;
- Administrador ajeno no puede emitirlo;
- Override remoto válido permite confirmar exactamente una vez;
- otro Operador no puede consumir un Override ajeno;
- cambiar líneas invalida la solicitud;
- cambiar el saldo obliga a mostrar una nueva advertencia;
- motivo vacío es rechazado;
- petición manipulada no decide saldos ni organización;
- varias líneas negativas producen una sola incidencia con el detalle
  completo;
- fallo parcial revierte movimiento, saldo, Override e incidencia;
- regularización parcial conserva la incidencia abierta;
- regularización total conserva la historia;
- disponibilidad se separa por condición;
- ninguna condición consume otra silenciosamente;
- productos por unidad y fraccionables conservan sus reglas actuales;
- SQLite y MySQL producen los mismos resultados.

## 17. Orden de implementación

1. Auditar la estructura ya adelantada de condiciones y cantidades.
2. Implementar lectura de disponibilidad y déficit sin persistir números
   duplicados.
3. Implementar solicitud y emisión puntual del Override.
4. Integrar confirmación excepcional e incidencia en una única transacción.
5. Implementar regularización e historia de estados.
6. Incorporar interfaz APB y pruebas HTTP.
7. Ejecutar suites completas SQLite y MySQL.
8. Emitir relevo de cierre.

## 18. Alternativas descartadas

### Compartir el usuario del Administrador

Se descarta porque elimina atribución, permite acciones no autorizadas y
convierte la auditoría en información falsa.

### `allow_negative=true`

Se descarta porque una petición manipulada podría eludir la advertencia.

### Delegación abierta al Operador

Se descarta por ahora porque permitiría múltiples negativos sin revisión
individual.

### Editar el saldo para corregirlo

Se descarta porque el saldo es una proyección. La regularización nace de un
movimiento confirmado.

### Ocultar el negativo mostrando solamente cero

Se descarta porque disponibilidad cero no explica el déficit físico.

## 19. Consecuencias

### Positivas

- la venta excepcional puede continuar con un Override remoto;
- ningún empleado necesita credenciales ajenas;
- cada decisión identifica solicitante, autorizado y otorgante;
- el Override no sirve para otra operación;
- concurrencia e idempotencia permanecen protegidas;
- el déficit queda visible hasta su regularización;
- no se debilita la fuente de verdad del libro.

### Costos aceptados

- la excepción exige una interacción administrativa adicional;
- se incorporan solicitud, incidencia e historia;
- un cambio de saldo obliga a revisar nuevamente la decisión;
- el flujo requiere una interfaz móvil utilizable para aprobación remota.

## 20. Alcance no incluido

Esta ADR no implementa:

- reservas ni señas;
- conteos o importación;
- seriales, S/N o IMEI;
- costos;
- delegaciones generales de rol;
- trabajo offline.

Su implementación se limitará a disponibilidad, condiciones, autorización de
negativo, incidencia, regularización e interfaz APB correspondientes al Bloque
3.
