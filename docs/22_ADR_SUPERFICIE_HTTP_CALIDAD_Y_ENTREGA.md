# ADR 22 — Superficie HTTP/UI de calidad y entrega

## Estado

Aceptada.

## Contexto

Reparaciones ya posee un motor de dominio que:

- exige todos los trabajos completados antes del control de calidad;
- calcula el resultado del control desde las comprobaciones;
- devuelve la orden a retrabajo cuando una prueba falla;
- habilita la entrega sólo con el último control aprobado;
- registra la salida final de custodia;
- identifica al receptor y su conformidad;
- genera garantías a partir de los trabajos completados que declararon cobertura;
- cierra correctamente una orden correctiva de garantía después de su entrega;
- preserva idempotencia, inmutabilidad y aislamiento por organización.

El expediente sólo mostraba el conteo general de controles. No existían gates, rutas, formularios ni controlador HTTP para operar calidad y entrega.

## Decisión

Core 15 incorpora una superficie privada y anidada bajo la orden de servicio.

### Protocolo de calidad

El servidor define un protocolo mínimo de seis comprobaciones:

1. encendido y estabilidad;
2. carga y alimentación;
3. función principal;
4. conectividad;
5. condición física posterior al trabajo;
6. accesorios y elementos en custodia.

El navegador informa el resultado y las observaciones de cada comprobación, pero no puede alterar sus códigos ni sus descripciones. El servidor deriva las etiquetas desde el protocolo vigente.

Una prueba fallida exige el retrabajo requerido. Un control sin fallas no puede declarar retrabajo.

### Entrega

La entrega no acepta un identificador de control suministrado por el navegador. El servidor deriva la última inspección de la organización y exige que esté aprobada.

El receptor puede vincularse a una `BusinessParty` de la organización o registrarse como receptor autorizado. La falta de conformidad exige observaciones.

### Custodia y garantías

Toda escritura pasa por `ServiceCompletionManager`.

La entrega:

- crea el evento final de custodia;
- transfiere el equipo al receptor;
- genera las garantías atribuibles a los reportes de trabajo;
- cierra el eventual reclamo correctivo;
- cambia la orden a entregada.

La capa HTTP no modifica directamente inspecciones, entregas, custodias, garantías ni estados.

### Idempotencia

Cada formulario mutable transporta una clave UUID con prefijo específico:

- `service-ui:quality-inspection:`;
- `service-ui:delivery:`.

### Permisos

- Viewer: lectura del expediente.
- Operator y Admin: control de calidad.
- Operator y Admin: entrega.

### Persistencia

Core 15 no agrega migraciones ni altera el dominio validado. Los controles, entregas, custodias y garantías confirmados continúan siendo inmutables.

## Consecuencias

- El cierre técnico queda operable desde el expediente.
- Un control rechazado abre el camino explícito de retrabajo.
- La entrega conserva la última inspección aprobada como autoridad.
- La custodia final y las garantías se generan en una única transacción.
- Venta, cobro y cierre comercial permanecen en Core 16.
