# ADR 47 — Reconciliation Difference Review & Append-Only Resolution V1

Estado: Aceptada para P6.3

Checkpoint de partida:
`276d341f694e30d7a3991ee7d47f6d09900feeca`

## 1. Decisión

P6.3 completa el ciclo mínimo de una diferencia de conciliación mediante una
resolución humana explícita y append-only.

Resolver no modifica el evento `difference`, no modifica allocations y no
reescribe movimientos externos, cobros ni ventas.

La resolución agrega un nuevo `PaymentReconciliationEvent` con estado
`resolved`.

## 2. Motor único

La resolución vive en `PaymentReconciliationManager`.

No se crea un segundo motor de conciliación.

El manager conserva autoridad sobre:

- organización activa;
- permiso de revisión financiera;
- expediente de conciliación;
- orden de eventos;
- idempotencia;
- auditoría.

## 3. Estado resoluble

Sólo puede resolverse un expediente cuyo último evento sea `difference`.

Un cobro:

- sin expediente;
- sin eventos;
- con último evento `matched`;
- con cualquier estado distinto de `difference`;

falla cerrado.

## 4. Evidencia preservada

El evento `resolved` copia del último evento `difference`:

- `allocated_gross_amount_minor`;
- `difference_minor`.

No crea allocations nuevas.

Las allocations originales continúan enlazadas al evento que produjo la
diferencia y permanecen inmutables.

De esta forma la resolución expresa una decisión sobre evidencia ya registrada,
no una nueva acreditación ficticia.

## 5. Nota obligatoria

Resolver una diferencia requiere una nota explícita de entre 10 y 2000
caracteres.

La nota debe describir la decisión humana. P6.3 no inventa causas ni corrige
automáticamente el importe.

## 6. Idempotencia

La clave se deriva del evento `difference` fuente:

`p6:resolve:{organization}:{payment}:{differenceEventId}`

Repetir la misma resolución con la misma nota devuelve el evento `resolved`
existente.

Intentar resolver nuevamente con una nota diferente falla cerrado.

Un futuro evento `difference` posterior tendrá otra identidad de resolución.

## 7. Auditoría

Cada resolución nueva registra:

`commerce_payment_reconciliation_resolved`

con:

- cobro;
- evento difference fuente;
- bruto asignado;
- diferencia preservada.

## 8. HTTP y privacidad

La ruta POST:

- vive dentro de `RequireOrganization`;
- requiere `review-financial-reconciliation`;
- resuelve el cobro dentro de la organización activa;
- devuelve 404 ante cobros de otra organización.

La pantalla sólo muestra la acción de resolución cuando el estado actual es
`difference`.

El GET del Centro continúa sin mutaciones.

## 9. Fuera de alcance

P6.3 no incorpora:

- auto-match;
- tolerancias automáticas;
- corrección destructiva;
- cambio de importes históricos;
- nuevas allocations al resolver;
- split allocation UI;
- reversión de una resolución.

Cualquier corrección futura debe expresarse con nuevos eventos y reglas
explícitas.
