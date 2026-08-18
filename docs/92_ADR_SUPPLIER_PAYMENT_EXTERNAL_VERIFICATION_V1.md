# ADR 92 — Supplier Payment External Verification V1

Estado: Aceptada para P9.7k

Checkpoint de partida:
`c04f3156361cebb6f41b0d128ee4c33b9d5219d6`

Fecha: 2026-08-17

## Contexto

P9.7j publicó la operación individual y agrupada, cash y non-cash, sobre
`PurchasePaymentDisbursement`. El camino non-cash exige una referencia, pero
esa declaración del operador no prueba que una entidad financiera haya
contabilizado el débito.

El RECON P9.7k confirmó que `FinancialExternalMovement` ya es el ledger
provider-neutral, inmutable e idempotente para API, webhook, polling,
CSV/XLSX y fallback manual. También confirmó que `PaymentReconciliation`, sus
events, allocations, readers y decisiones pertenecen exclusivamente a
`CommercePayment + Credit`: extenderlos a pagos salientes mezclaría dos hechos
económicos distintos.

## Decisión

P9.7k incorpora
`PurchasePaymentExternalVerification`, una evidencia append-only que vincula:

`PurchasePaymentDisbursement → FinancialExternalMovement`.

No crea otro movimiento financiero, no reescribe el desembolso y no reutiliza
el expediente entrante de ventas.

La verificación sólo admite:

- desembolso canónico `noncash` con referencia declarada;
- movimiento externo `Debit + Posted`;
- misma organización, cuenta financiera y moneda;
- selección explícita por un Administrador con permiso de conciliación;
- una única evidencia por desembolso y un único uso del movimiento externo;
- clave de idempotencia y fingerprint de ambos hechos inmutables.

## Matching y referencia

La referencia se compara en orden contra:

1. `external_operation_id`;
2. `source_key`;
3. `raw_reference`.

Una coincidencia exacta queda registrada como base estructurada. Si ninguna
coincide, el Administrador todavía puede confirmar la atribución, pero debe
explicar el motivo con una nota explícita. La selección nunca es automática:
el ranking de candidatos es sólo ayuda visual y no posee autoridad de dominio.

## Importe, comisiones y retenciones

La evidencia conserva el bruto, neto, comisión y retención del movimiento
externo sin reinterpretarlos.

`amount_difference_minor = gross externo - desembolso`.

Sólo se proyecta “importe exacto” cuando:

- la diferencia es cero;
- comisión es cero;
- retención es cero.

Cualquier diferencia, comisión o retención exige nota y permanece visible
como diferencia no resuelta. P9.7k no fabrica asientos, no altera la obligación
y no compensa saldos silenciosamente.

## Exclusividad de evidencia

Un mismo `FinancialExternalMovement` no puede respaldar simultáneamente:

- un cobro entrante mediante `PaymentReconciliationAllocation`;
- un reembolso externo de posventa;
- un desembolso a proveedor.

La exclusividad se valida en manager y mediante guards de BD en ambas
direcciones para los ledgers existentes.

## Evolución de estado externo

La evidencia verificada permanece inmutable. Si el mismo
`external_operation_id` recibe después una observación `reversed`, `failed` o
`pending`, el control posterior muestra el nuevo estado sin borrar la evidencia
`posted`, reabrir la obligación ni volver a pagar.

## Autoridad y visibilidad

- Viewer y Operator pueden consultar el control del desembolso;
- sólo Admin, mediante `review-financial-reconciliation`, ve candidatos y
  confirma la evidencia;
- route binding, manager, consultas y guards conservan aislamiento tenant;
- la UI no decide compatibilidad, diferencia ni exclusividad.

## Fuera de alcance

- conciliación automática;
- resolución contable de diferencias;
- materialización de comisiones o retenciones como hechos separados;
- un movimiento agregado para varios desembolsos independientes;
- reescritura de `PurchasePaymentExecution` legacy;
- migración de la BD real;
- llamadas HTTP a proveedores;
- fiscalidad ARCA.

## Próximo corte

P9.7l — Supplier Payment External Difference Resolution RECON: relevar cómo
comisiones, retenciones, reversas y diferencias deben afectar tesorería y CxP
sin reescribir desembolso, obligación ni evidencia externa.
