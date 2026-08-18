# ADR 93 — Supplier Payment External Difference Resolution V1

Estado: Aceptada para P9.7l

Checkpoint de partida:
`83dde8a9e2efa01b57d1d2cb336151f44a248867`

Fecha: 2026-08-17

## Contexto y evidencia del RECON

P9.7k publicó `PurchasePaymentExternalVerification` como vínculo append-only
entre el desembolso canónico y un débito externo contabilizado. El RECON P9.7l
confirmó:

- `FinancialExternalMovement` ya conserva bruto, neto, comisión, retención y
  evolución de estado externa;
- `PurchasePaymentDisbursementAllocation` continúa siendo la imputación que
  descarga la obligación;
- la resolución entrante de `PaymentReconciliation` es un evento administrativo
  propio de `CommercePayment + Credit` y no puede reutilizarse;
- no existen todavía un libro de gastos, retenciones ni asientos contables que
  permita materializar esos conceptos sin inventar una verdad inexistente.

## Decisión

P9.7l incorpora `PurchasePaymentExternalResolution`, una decisión append-only
sobre una verificación y la observación externa más reciente revisada:

`PurchasePaymentExternalVerification + FinancialExternalMovement observado`
`→ PurchasePaymentExternalResolution`.

La resolución conserva como snapshots:

- estado externo observado;
- diferencia contra el desembolso;
- comisión;
- retención;
- outcome, fundamento, actor y momento.

No modifica la verificación, el desembolso, sus allocations, la obligación ni
el movimiento externo.

## Outcomes

- `treasury_exception_accepted`: cierre administrativo de una diferencia
  todavía `Posted`; afirma que no cambia CxP, pero no pretende ser un asiento;
- `provider_follow_up_required`: reclamo o revisión con banco, billetera,
  procesador u otra entidad financiera;
- `supplier_follow_up_required`: revisión con proveedor o beneficiario;
- `evidence_correction_required`: la evidencia importada o atribuida requiere
  corrección mediante nuevos hechos.

Una observación `Pending`, `Failed` o `Reversed` nunca puede cerrarse como
excepción aceptada. Sólo admite un outcome de seguimiento. Una verificación
exacta, sin comisión ni retención y cuyo último estado sea `Posted`, no tiene
diferencia que resolver.

## Evolución externa

La unidad de unicidad es:

`verificación + movimiento externo observado`.

Si después de una decisión aparece otra observación con el mismo
`external_operation_id`, la decisión anterior permanece como historia y el
nuevo estado vuelve a requerir revisión propia. No se edita ni se invalida
retrospectivamente una decisión anterior.

## Tesorería y CxP

El efecto de tesorería continúa respaldado por `FinancialExternalMovement`.
P9.7l no crea un segundo movimiento.

El saldo CxP continúa derivándose exclusivamente de ejecución legacy,
allocations del desembolso canónico, notas de crédito y anticipos aplicados.
Una diferencia externa no reabre ni reduce la obligación silenciosamente.

Los snapshots de comisión y retención quedan disponibles para una futura
integración contable/administrativa, pero P9.7l no fabrica `Expense`,
`SupplierWithholding` ni `AccountingJournalEntry`.

## Autoridad, idempotencia e integridad

- sólo Admin con `review-financial-reconciliation` puede decidir;
- la verificación se resuelve mediante consulta tenant-scoped;
- cada POST exige confirmación, outcome, nota de 10 a 2000 caracteres y clave
  de idempotencia;
- fingerprint incluye verificación, observación, outcome, snapshots y nota;
- modelo y triggers SQLite/MySQL bloquean update/delete;
- guards de BD revalidan tenant, actor, latest observation, estado, importes y
  compatibilidad del outcome.

## Fuera de alcance

- contabilización automática;
- modificación de CxP;
- reapertura o nuevo pago automático ante reversa;
- edición de evidencia externa;
- conciliación automática;
- migración de la BD real;
- llamadas HTTP a proveedores;
- fiscalidad ARCA.

## Próximo corte

P9.8 — Supplier Payables Exposure & Aging RECON: relevar la proyección de
vencimientos, exposición y estado de cuenta de proveedores sobre las verdades
CxP ya publicadas, sin crear saldos almacenados paralelos.
