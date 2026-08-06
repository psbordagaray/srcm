# ADR 20 — Superficie HTTP/UI de trabajo y custodia

## Estado

Aceptada.

## Contexto

Reparaciones ya posee un motor de dominio para:

- planificar trabajos internos y tercerizados;
- planificar trabajo correctivo de garantía;
- iniciar ejecución interna;
- transferir y recuperar custodia con especialistas externos;
- registrar resultados técnicos completados o sin solución;
- mantener idempotencia, inmutabilidad, permisos y aislamiento por organización.

El expediente web únicamente mostraba los trabajos en modo lectura. No existían rutas, formularios ni controladores para operar esas capacidades.

## Decisión

Core 13 incorpora una superficie HTTP/UI privada y anidada bajo la orden de servicio.

### Autoridad del servidor

La interfaz no recibe como autoridad:

- la alternativa aprobada del presupuesto;
- la resolución correctiva de garantía;
- la organización;
- el estado de la orden;
- el estado o pertenencia del trabajo.

El controlador deriva esos datos desde la organización activa y el expediente. Una discrepancia de organización u orden responde `404`. Un estado incompatible responde `409`.

### Escrituras

Toda escritura pasa por `ServiceWorkManager`. La capa HTTP no modifica directamente:

- órdenes;
- trabajos;
- historiales;
- eventos de custodia;
- informes técnicos.

### Identidad e idempotencia

Cada formulario mutable transporta una clave UUID con prefijo específico:

- `service-ui:work-plan:`;
- `service-ui:work-start:`;
- `service-ui:work-dispatch:`;
- `service-ui:work-return:`;
- `service-ui:work-report:`.

El manager conserva la semántica idempotente y rechaza reutilizaciones contradictorias.

### Permisos

- Viewer: lectura del expediente.
- Operator y Admin: planificación y ejecución.
- Operator y Admin: transferencia de custodia.
- Las reglas de dominio continúan siendo la autoridad final.

### Especialistas externos

Core 13 utiliza `BusinessParty` como identidad del tercero porque el dominio vigente no posee una clasificación exclusiva de especialista. La interfaz muestra las personas y organizaciones de la organización activa, sin introducir una taxonomía nueva en este bloque.

### Persistencia

Core 13 no agrega migraciones ni modifica el dominio validado. Las correcciones se registran mediante nuevos hechos, nunca editando resultados o transferencias confirmadas.

## Consecuencias

- El flujo técnico queda operable desde el expediente.
- El trabajo normal y el correctivo de garantía comparten una única superficie.
- La custodia externa queda visible y atribuible.
- Repuestos, calidad, entrega y cobro permanecen en Core 14, 15 y 16.
