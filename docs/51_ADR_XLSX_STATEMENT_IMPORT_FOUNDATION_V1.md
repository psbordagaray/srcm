# ADR 51 — XLSX Statement Import Foundation V1

Estado: Aceptada para P7.4

## 1. Contexto

P7.1–P7.3 ya cubren:

- vista previa CSV canónica;
- commit explícito e idempotente;
- mapeo configurable por carga.

El roadmap P7 exige importación CSV/XLSX contra el mismo motor financiero.

## 2. Decisión

P7.4 agrega XLSX sin introducir un segundo ledger ni una segunda semántica de
conciliación.

El XLSX se lee desde la primera hoja, se normaliza hacia el mismo contrato de
filas de P7.3 y luego reutiliza el mismo commit P7.2.

Los movimientos confirmados usan:

- `source = xlsx`;
- `status = posted`;
- `source_key = xlsx:{file_sha256}:{line_number}`.

## 3. Lectura XLSX

La Foundation usa las extensiones PHP estándar `zip` y `SimpleXML`.

No incorpora una dependencia de terceros nueva.

Se admiten:

- celdas string;
- inline strings;
- shared strings;
- valores numéricos;
- fechas seriales Excel cuando el estilo es reconocible como fecha;
- workbook con sistema de fechas 1900 o 1904;
- mapeo canónico o configurable de P7.3.

Se procesa sólo la primera hoja.

## 4. Fórmulas

P7.4 falla cerrado ante cualquier celda con fórmula.

Un valor cacheado de Excel no prueba que la fórmula haya sido recalculada en
el momento correcto. Para evidencia financiera se requieren valores
confirmados, no fórmulas ejecutables o potencialmente obsoletas.

## 5. Seguridad de paquete

El lector:

- limita cantidad de entradas ZIP;
- limita tamaño de XML interno;
- no sigue relaciones externas;
- rechaza targets con `..` o esquema remoto;
- usa `LIBXML_NONET`;
- no evalúa fórmulas;
- no persiste el XLSX original.

## 6. Normalización

El XLSX se transforma temporalmente en filas y se entrega al mismo
`FinancialStatementCsvPreviewer`.

Esa reutilización conserva:

- reglas tenant;
- cuenta activa no-cash;
- moneda server-authoritative;
- matemática `gross = net + fee + withholding`;
- dirección credit/debit;
- fingerprints de fila;
- límites de filas;
- fail-closed de duplicados.

El archivo temporal normalizado se elimina.

## 7. Identidad

La identidad durable se deriva del XLSX original, no del archivo temporal
normalizado.

Por eso:

`xlsx:{sha256_original}:{line_number}`

es estable para reintentos del mismo archivo exacto.

La detección cross-file por `external_operation_id` continúa cruzando fuentes,
de modo que CSV y XLSX no pueden crear dos verdades diferentes para la misma
operación externa.

## 8. Borradores

P7.4 escribe borradores temporales versión 3 con `source`.

Versiones 1 y 2 continúan válidas durante su TTL y se interpretan como CSV.

El commit acepta sólo `csv` o `xlsx` en esta etapa.

## 9. Conciliación

Importar XLSX nunca concilia automáticamente.

Los movimientos `posted` quedan disponibles en el mismo Centro P6 para la
decisión explícita posterior.

## 10. Dependencias de runtime

La capacidad XLSX exige:

- `ZipArchive`;
- `simplexml_load_string`.

El runner de implementación verifica ambas antes de modificar el repositorio.

## 11. Próximo corte

Con CSV/XLSX, preview, normalización, mapping, duplicados e idempotencia
cubiertos, el siguiente faltante explícito de P7 es el fallback manual
controlado y auditable, sólo cuando no exista una alternativa razonable.
