# ADR 29 — Importación controlada de Productos CSV/XLSX

Estado: aceptada.

Checkpoint de decisión: `e3d403335cadae2b76bfd4f917b1a3b24ff19898`.

## Contexto

El MVP necesita incorporar datos provenientes de planillas sin crear una vía paralela que eluda las reglas de Catálogo, Conocimiento o Inventario.

El proyecto no posee una biblioteca externa para hojas de cálculo.

## Decisión

El Bloque 1 de Importación cubre exclusivamente la ficha maestra de Productos.

Formatos admitidos:

- CSV o TXT delimitado por punto y coma, coma o tabulación;
- Excel `.xlsx`;
- únicamente la primera hoja del libro.

La lectura de `.xlsx` utiliza un lector mínimo interno de Office Open XML. SRCM interpreta el directorio ZIP, entradas almacenadas o DEFLATE y el XML necesario de libro/relaciones/hoja/cadenas compartidas sin depender de `ZipArchive`, SimpleXML ni una biblioteca Composer. Las entradas DEFLATE requieren la función estándar `gzinflate` de zlib.

## Flujo

1. El usuario con permiso `manage-catalog` carga el archivo.
2. SRCM parsea hasta 500 filas.
3. Se normalizan encabezados y valores.
4. Se resuelven Categoría, Marca y Fabricante contra maestros activos existentes.
5. Se detectan duplicados dentro del archivo y contra el catálogo.
6. Se presenta una previsualización.
7. Si no existen errores se genera un token privado temporal ligado al usuario.
8. Al confirmar, SRCM revalida el conjunto completo y crea los productos dentro de una transacción.

La previsualización vence a los 30 minutos y los borradores privados antiguos se limpian automáticamente.

## Fuente de verdad

La importación crea cada producto mediante `CatalogProductKnowledgeManager`.

No inserta directamente `catalog_products` y, por lo tanto, conserva la sincronización con la ficha de conocimiento y el identificador principal.

## Referencias

Categoría es obligatoria.

Marca y Fabricante son opcionales.

La importación no crea categorías, marcas ni fabricantes. Una referencia inexistente, inactiva o ambigua falla de forma cerrada.

## Inventario

Este bloque no importa:

- stock inicial;
- saldos;
- movimientos;
- costos;
- compras;
- ventas.

La verdad de Inventario continúa siendo el libro de movimientos confirmados.

## Atomicidad

La previsualización no modifica el catálogo.

La confirmación vuelve a validar duplicados y referencias. Si una fila falla o aparece un conflicto entre previsualización y confirmación, la transacción completa se revierte.

## Seguridad y límites

- máximo 5 MB por archivo;
- máximo 500 filas de datos;
- máximo 24 columnas leídas;
- borrador almacenado en el disco privado `local`;
- token UUID ligado al usuario;
- no se expone una ruta de lectura del borrador;
- el lector XLSX no depende de `ext-zip` ni SimpleXML;
- no existe importación para usuarios Viewer.

## Columnas

Obligatorias:

- `sku`
- `nombre`
- `categoria`

Opcionales:

- `marca`
- `fabricante`
- `descripcion`
- `unidad_base`
- `decimales`
- `activo`

Se admiten alias equivalentes en español e inglés.

## Fuera de alcance

- `.xls` binario legado;
- múltiples hojas;
- auto-creación de maestros;
- actualización masiva de productos existentes;
- importación de inventario o balances;
- importación de Personas, Clientes o Proveedores;
- trabajos en cola;
- archivos mayores a 500 filas;
- mapeo interactivo de columnas.
