# ADR 28 — Búsqueda global operativa

Estado: aceptada.

Checkpoint de decisión: `e6baed2c77aa3d24404304921bb0600d951ceb5e`.

## Contexto

SRCM ya posee búsquedas locales en Productos, Personas, Reparaciones, Compras y Ventas, pero el operador debe conocer primero qué módulo contiene el dato.

El “Explorador” existente pertenece al dominio técnico/conocimiento y no debe confundirse con una búsqueda transversal de expedientes operativos.

## Decisión

Se incorpora una búsqueda global de sólo lectura dentro de la organización activa.

`GlobalSearchReader` compone resultados directamente desde las fuentes existentes. No crea un índice paralelo ni una segunda fuente de verdad.

El Bloque 1 busca:

- productos por SKU, nombre, descripción, marca o categoría;
- modelos técnicos por código, nombre, marca o categoría;
- personas por nombre, CUIT/DNI, correo o teléfono;
- reparaciones por número, UUID, cliente/propietario, activo, problema informado o identificador técnico;
- compras por UUID, proveedor, CUIT o referencia de recepción;
- ventas por número, UUID, cliente/documento o reparación relacionada.

Cada resultado enlaza a la ficha original del módulo correspondiente.

## Alcance organizacional

Productos y modelos técnicos mantienen el alcance compartido del catálogo.

Personas, Reparaciones, Compras y Ventas se consultan exclusivamente con `organization_id` de `CurrentOrganization`.

El navegador nunca provee un `organization_id` a la búsqueda.

## Protección frente a consultas amplias

La consulta útil mínima es de dos caracteres.

Se eliminan `%` y `_` como comodines antes de decidir si la consulta es suficientemente específica.

La entrada se limita a 100 caracteres.

Cada tipo devuelve como máximo ocho resultados luego de priorizar coincidencia exacta, prefijo y coincidencia parcial.

## Persistencia

No hay migraciones, tablas nuevas, índices externos ni procesos de sincronización.

## Fuera de alcance

- búsqueda semántica o IA;
- Elasticsearch/Meilisearch;
- ranking aprendido;
- historial personal de búsquedas;
- autocompletado remoto;
- búsquedas sobre archivos/evidencias;
- búsqueda global entre organizaciones;
- acciones de escritura desde resultados.
