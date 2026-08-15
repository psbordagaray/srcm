# ADR 52 — Manual External Movement Fallback V1

Estado: Aceptada para P7.5

Checkpoint de partida:
`5129310566013e4e29c7d215178403b419d15f9c`

## 1. Contexto

P7 ya dispone de:

- preview y normalización CSV;
- commit explícito e idempotente;
- mapeo configurable;
- XLSX;
- conciliación posterior mediante el mismo motor P6.

El roadmap exige además un fallback manual explícito y auditable para
instituciones sin una alternativa razonable de API, CSV o XLSX.

## 2. Decisión

P7.5 agrega una superficie manual separada del importador.

Cada registro manual:

- pertenece a una cuenta financiera privada y activa;
- rechaza `cash_box` y `cash_reserve`;
- usa `FinancialMovementSource::Manual`;
- usa `FinancialMovementStatus::Posted`;
- deriva la moneda exclusivamente de la cuenta;
- exige bruto, neto, comisión y retención exactos;
- exige fecha/hora externa explícita;
- exige un motivo de fallback de 10 a 500 caracteres;
- permite ID de operación y referencia externa seguros;
- queda disponible para el mismo Centro de Conciliación;
- nunca auto-concilia.

No se crea un ledger paralelo.

## 3. Idempotencia

El formulario genera una clave UUID privada para el intento.

La `source_key` es:

`manual:{financial_account_public_id}:{idempotency_uuid}`

Reintentar el mismo submit no duplica el movimiento.

## 4. Dedupe cross-source

Si el usuario aporta `external_operation_id`, antes de crear un movimiento
manual SRCM busca un `posted` existente en la misma cuenta:

- mismo ID + mismo contenido financiero: devuelve el hecho existente;
- mismo ID + contenido financiero diferente: falla cerrado;
- más de un `posted` previo con ese ID: exige revisión.

Esto evita duplicar manualmente un movimiento que ya llegó por API, webhook,
polling, CSV o XLSX.

Sin ID externo, SRCM no inventa una identidad bancaria inexistente.

## 5. Auditoría

El recorder conserva `financial_external_movement_recorded`.

Además:

- creación manual nueva:
  `financial_manual_external_movement_recorded`;
- dedupe manual contra un hecho previo:
  `financial_manual_external_movement_deduplicated`.

El motivo humano queda en metadata de auditoría. No se deben ingresar secretos,
PAN completo, CVV, tokens ni credenciales.

## 6. Autoridad y seguridad

P7.5 reutiliza `review-financial-reconciliation`.

La cuenta se revalida tenant-scoped, activa y no-cash al registrar.

El movimiento confirmado permanece inmutable.

El fallback manual no modifica:

- Venta;
- `CommercePayment`;
- Caja;
- Inventario;
- conciliaciones existentes.

## 7. Cierre de P7

Con P7.5 quedan cubiertos los elementos ejecutivos del roadmap P7:

- CSV/XLSX;
- preview;
- normalización;
- mapeo;
- duplicados;
- idempotencia;
- mismo motor de conciliación;
- fallback manual explícito y auditable.

El siguiente bloque ejecutivo es P8 — Posventa comercial completa.
