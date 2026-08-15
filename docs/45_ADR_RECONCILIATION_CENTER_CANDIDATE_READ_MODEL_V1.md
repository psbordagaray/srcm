# ADR 45 — Reconciliation Center Candidate Read Model V1

Estado: Aceptada para P6.1

Checkpoint de partida:
`4cf33767fa03609c8a3e3085a3c8a3d71e188ade`

## 1. Contexto

SRCM ya posee las verdades fundamentales necesarias para conciliación:

1. venta;
2. cobro declarado;
3. movimiento financiero externo;
4. bruto, neto, comisión y retención del movimiento;
5. expediente/eventos de conciliación append-only.

P6 no debe reemplazar esas verdades ni crear un segundo ledger.

## 2. Decisión

P6.1 crea un Centro de Conciliación de sólo lectura que reúne cobros
electrónicos y movimientos externos candidatos sin ejecutar coincidencias
automáticas.

El centro es un read model. No persiste candidatos, scores ni decisiones.

## 3. Reglas de elegibilidad

Un movimiento puede aparecer como candidato sólo cuando:

- pertenece a la misma organización;
- pertenece a la misma cuenta financiera del cobro;
- tiene la misma moneda que la venta;
- es crédito;
- está `posted`;
- ocurrió dentro de ±7 días del cobro;
- no fue asignado a un cobro diferente.

Estas reglas son filtros, no prueba de conciliación.

## 4. Orden de evidencia

Los candidatos se ordenan de forma determinista para reducir trabajo manual.

Evidencias V1:

- `external_operation_exact`: identificador externo exacto;
- `gross_exact`: bruto exacto;
- `gross_within_one_percent`: diferencia de bruto dentro de 1%;
- `time_within_five_minutes`;
- `time_within_one_hour`;
- `time_within_one_day`;
- `time_within_seven_days`.

El score sólo ordena candidatos.

No autoriza ni ejecuta conciliación.

## 5. Bruto y neto

El cobro esperado se compara contra el bruto externo.

El centro muestra por separado:

- bruto;
- neto;
- comisión;
- retención;
- diferencia contra bruto esperado.

Por lo tanto, una acreditación neta menor por comisión no se presenta como
error de venta.

## 6. Estado existente

Si un cobro ya posee expediente de conciliación, P6.1 muestra el último evento
append-only existente.

No recalcula ni modifica la historia.

## 7. Privacidad y autorización

El Centro de Conciliación:

- está dentro de `RequireOrganization`;
- requiere `review-financial-reconciliation`;
- no mezcla candidatos de otra organización;
- no expone acciones de mutación en P6.1.

## 8. Provider-neutral

El read model opera sobre `FinancialExternalMovement`.

No conoce Mercado Pago, Payway ni contratos particulares de API.

Movimientos futuros provenientes de CSV, banco u otros adaptadores utilizan el
mismo Centro de Conciliación.

## 9. Alcance siguiente

P6.2 podrá convertir una selección humana explícita en propuesta/ejecución de
conciliación utilizando el motor append-only ya existente, agregando guards
para resolución, diferencias y reintentos.

P6.1 no cambia semántica de conciliación ni crea auto-match.
