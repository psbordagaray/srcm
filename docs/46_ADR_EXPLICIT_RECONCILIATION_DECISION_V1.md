# ADR 46 — Explicit Reconciliation Decision V1

Estado: Aceptada para P6.2

Checkpoint de partida:
`744c09215b0fc1262cbd5d2371eae55577f8e9bc`

## 1. Decisión

P6.2 habilita la primera mutación del Centro de Conciliación, pero sólo como
decisión humana explícita sobre un candidato individual.

El ranking P6.1 continúa siendo informativo. Ningún score ejecuta una
conciliación.

## 2. Reutilización de la verdad existente

P6.2 no crea otro motor ni otro ledger.

`FinancialReconciliationDecisionManager` valida la decisión del Centro y luego
delegará exclusivamente en `PaymentReconciliationManager`, que conserva:

- expediente único por cobro;
- eventos append-only;
- allocations append-only;
- cálculo bruto esperado vs bruto asignado;
- estado `matched` o `difference`;
- auditoría;
- rechazo de movimiento usado por otro cobro.

## 3. Revalidación en el momento de decidir

La UI nunca es autoridad.

Antes de delegar, P6.2 vuelve a exigir:

- actor con permiso de revisión financiera;
- cobro y movimiento de la organización activa;
- cobro con cuenta financiera;
- misma cuenta financiera;
- misma moneda;
- movimiento `credit`;
- movimiento `posted`;
- movimiento dentro de ±7 días del cobro.

Así, manipular el POST no permite saltear las reglas del read model.

## 4. Importe asignado

P6.2 asigna el bruto completo del movimiento seleccionado.

No inventa un neto esperado y no oculta comisiones o retenciones.

Si bruto externo y bruto esperado difieren, el evento queda en `difference`.

## 5. Diferencias

Una decisión con diferencia requiere una nota humana explícita de al menos
10 caracteres.

Esto evita que una discrepancia quede aceptada silenciosamente.

P6.2 no corrige ni ajusta automáticamente la diferencia.

## 6. Idempotencia

La decisión usa una clave estable:

`p6:manual:{organization}:{payment}:{movement}`

Repetir exactamente la misma decisión no duplica eventos ni allocations.

Elegir posteriormente otro movimiento genera un nuevo evento append-only y
preserva la historia anterior.

## 7. HTTP y privacidad

La ruta POST:

- vive dentro de `RequireOrganization`;
- requiere `review-financial-reconciliation`;
- resuelve el cobro y movimiento mediante queries tenant-scoped;
- devuelve 404 ante referencias de otra organización.

El GET del Centro nunca crea conciliaciones.

## 8. Alcance

P6.2 cubre una selección manual de un único movimiento.

No incorpora todavía:

- conciliación de múltiples movimientos en una sola decisión UI;
- split allocations;
- resolución administrativa específica de diferencias;
- auto-match;
- tolerancias automáticas;
- reversión destructiva.

Esos comportamientos deben incorporarse sólo mediante eventos nuevos y reglas
explícitas en cortes posteriores.
