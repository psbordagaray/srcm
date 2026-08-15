# ADR 48 — Canonical CSV Statement Preview V1

Estado: Aceptada para P7.1

Checkpoint de partida:
`7d9a2fcf2c9c8677caeac18a2b31dc8f44fa2ed3`

## 1. Contexto

P6 cerró un Centro de Conciliación provider-neutral capaz de trabajar sobre
`FinancialExternalMovement`.

P7 incorpora instituciones sin API. El orden del roadmap es:

1. API/webhook cuando existe;
2. polling cuando corresponde;
3. importación de extractos;
4. flujo manual controlado como último recurso.

La importación no debe crear un segundo motor financiero.

## 2. Decisión P7.1

P7.1 introduce exclusivamente un contrato CSV canónico y una vista previa
read-only.

La vista previa:

- selecciona una cuenta financiera privada;
- valida archivo y cabecera;
- normaliza fecha, dirección e importes;
- deriva moneda desde la cuenta;
- calcula identidad SHA-256 del archivo;
- calcula source keys deterministas por archivo y línea;
- calcula fingerprint financiero por fila;
- muestra bruto, neto, comisión y retención separados;
- no registra `FinancialExternalMovement`;
- no concilia;
- no crea un batch persistente.

## 3. Contrato CSV canónico

Cabecera exacta:

`occurred_at,direction,gross_amount,fee_amount,withholding_amount,net_amount,external_operation_id,reference`

Reglas:

- UTF-8; BOM UTF-8 inicial tolerado;
- separador coma;
- quoting CSV estándar;
- `occurred_at` en ISO 8601 con offset;
- `direction`: `credit` o `debit`;
- importes positivos/no negativos con punto decimal y hasta dos decimales;
- `gross = net + fee + withholding`;
- `external_operation_id` opcional y máximo 191 caracteres;
- `reference` opcional y máximo 500 caracteres;
- máximo 1000 filas;
- máximo 2 MiB.

## 4. Moneda y cuenta

La moneda nunca se toma del CSV.

Proviene de la cuenta financiera elegida dentro de la organización activa.

Las cuentas cash (`cash_box`, `cash_reserve`) se rechazan: el extracto externo
no debe alterar el ledger de caja física.

La cuenta debe estar activa y ser tenant-private.

## 5. Idempotencia futura

P7.1 todavía no persiste.

Sin embargo produce evidencia estable para P7.2:

- `file_sha256`;
- `source_key = csv:{file_sha256}:{line_number}`;
- fingerprint canónico por fila.

Repetir la vista previa del mismo archivo exacto produce las mismas identidades.

P7.2 deberá reutilizar estas identidades al registrar movimientos mediante el
motor existente y deberá fallar cerrado ante contenido conflictivo.

## 6. Duplicados dentro del archivo

Si `external_operation_id` está presente, no puede repetirse dentro del mismo
CSV canónico.

La ausencia de ID externo sigue siendo válida: algunos extractos no ofrecen
identidad transaccional confiable.

## 7. Seguridad

P7.1:

- requiere `review-financial-reconciliation`;
- no ejecuta HTTP externo;
- no maneja credenciales;
- no persiste el archivo;
- no persiste filas;
- no ejecuta conciliación;
- escapa contenido al renderizar mediante Blade.

## 8. Próximo corte

P7.2 podrá convertir una vista canónica confirmada en movimientos externos
idempotentes usando `ExternalFinancialMovementRecorder` y
`FinancialMovementSource::Csv`.

El commit de importación deberá ser explícito, auditable y separado de la
previsualización.
