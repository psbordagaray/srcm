# ADR 49 — Explicit Canonical CSV Import Commit V1

Estado: Aceptada para P7.2

Checkpoint de partida:
`8e3be20157da990dc67641718d5f5bdf03c2070e`

## 1. Contexto

P7.1 incorporó el contrato CSV canónico y una vista previa completamente
read-only.

P7.2 agrega el paso explícito que convierte una vista previa validada en
`FinancialExternalMovement`, reutilizando el mismo ledger externo de P3/P5/P6.

No se crea un segundo libro financiero.

## 2. Separación preview / commit

La carga inicial continúa sin crear movimientos financieros.

Cuando la vista previa es válida, SRCM guarda sólo un borrador normalizado,
privado y temporal:

- no guarda el archivo CSV original;
- no crea movimientos;
- no crea conciliaciones;
- no altera Venta ni Caja;
- el borrador queda cifrado y autenticado con `Crypt`;
- el token UUID se vincula a usuario, organización y cuenta;
- el borrador vence.

La importación requiere un POST separado y explícito.

## 3. Fuente y estado

Cada fila confirmada se registra mediante
`ExternalFinancialMovementRecorder` con:

- `source = csv`;
- `status = posted`;
- cuenta y moneda de la vista previa;
- `source_key` determinista de P7.1;
- bruto, neto, comisión y retención exactos;
- fecha normalizada;
- ID externo y referencia cuando existan.

Un extracto canónico representa hechos ya asentados por la institución.
P7.2 no inventa estados pending a partir de un extracto.

## 4. Idempotencia

Para el mismo archivo exacto, P7.1 ya produce:

`csv:{file_sha256}:{line_number}`

Reimportar el mismo archivo exacto no duplica movimientos.

Cuando existe `external_operation_id`, P7.2 agrega una defensa cross-file:

- si ya existe un `posted` en la misma cuenta con el mismo ID externo y
  mismo contenido financiero, se considera deduplicado;
- si el mismo ID externo ya existe con dinero/dirección/moneda diferentes,
  el import falla cerrado;
- si existen múltiples `posted` previos con el mismo ID, requiere revisión
  manual.

Sin ID externo, sólo puede garantizarse identidad fuerte para el mismo archivo
exacto. P7.2 no inventa una identidad bancaria inexistente.

## 5. Atomicidad

El commit completo corre dentro de una transacción.

Si una fila entra en conflicto, ninguna fila nueva del mismo commit queda
persistida.

Los movimientos ya existentes que se detectan como duplicados no se reescriben.

## 6. Auditoría

Cada movimiento nuevo conserva la auditoría del
`ExternalFinancialMovementRecorder`.

Además, el commit registra sobre la cuenta:

`financial_statement_csv_import_committed`

con datos seguros:

- source;
- SHA-256 del archivo;
- cantidad de filas;
- cantidad creada;
- cantidad deduplicada.

No se vuelca el contenido del CSV ni las referencias completas al audit batch.

## 7. Conciliación

Importar no concilia.

Luego del commit, los nuevos movimientos quedan disponibles para el mismo
Centro de Conciliación P6.

El ranking y cualquier decisión de conciliación siguen siendo procesos
separados.

## 8. Seguridad

P7.2 mantiene:

- `RequireOrganization`;
- permiso `review-financial-reconciliation`;
- cuenta tenant-private y activa;
- cuentas cash rechazadas;
- borrador cifrado;
- token ligado al usuario y tenant;
- no provider HTTP;
- no credenciales;
- no archivo crudo persistido.

## 9. Próximo corte

P7.3 debe abordar el siguiente faltante del roadmap P7:
mapeo configurable / adaptadores de columnas para extractos no canónicos,
sin debilitar el contrato canónico ni el commit P7.2.
