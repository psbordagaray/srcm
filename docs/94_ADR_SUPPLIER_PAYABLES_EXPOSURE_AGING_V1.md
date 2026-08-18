# ADR 94 — Supplier Payables Exposure & Aging V1

Estado: Aceptada para P9.8

Checkpoint de partida:
`922cd73e62d1c05f82932992689a78c07f68de26`

Fecha: 2026-08-17

## Contexto y evidencia del RECON

P9.7a–P9.7l publicó obligación, créditos, anticipos, autorización, desembolso
canónico y evidencia externa saliente. El RECON P9.8 confirmó:

- `PurchaseObligationBalanceReader` ya es la fórmula canónica por obligación;
- el remanente sólo disminuye por ejecución legacy, allocation de desembolso,
  nota de crédito aplicada o anticipo aplicado;
- autorización, verificación externa y resolución no liquidan CxP;
- `PurchaseObligation` conserva proveedor, beneficiario, moneda, condición y
  `due_on` cuando corresponde;
- no existen aging, snapshot, reporte global ni estado de cuenta de proveedor;
- el controller de operación de pagos repetía el cálculo y limitaba su universo
  a 250 obligaciones, por lo que no podía ser la exposición global.

## Decisión

P9.8 implementa CxP como **read model derivado**. No agrega migración, tabla,
snapshot, columna de saldo ni mutación de hechos históricos.

La fórmula vinculante continúa siendo:

`pendiente = obligación original`
`- ejecución legacy`
`- allocations de desembolso canónico`
`- notas de crédito aplicadas`
`- anticipos aplicados`

`PurchaseObligationBalanceReader::readMany()` proyecta la misma fórmula en
lote. La lectura individual y los locks transaccionales conservan su contrato.
La pantalla operacional consume también ese reader y deja de mantener una
fórmula duplicada.

## Vencimiento efectivo

P9.8 fija estas reglas deterministas:

- `due_date`: `PurchaseObligation.due_on`;
- `on_receipt`: fecha civil de `PurchaseReceipt.received_at`;
- `other`: sin vencimiento hasta que un hecho futuro modele su condición; no se
  inventa una fecha;
- una obligación con vencimiento igual a la fecha de corte está al día;
- una obligación totalmente liquidada queda `settled`.

## Buckets

- `current`;
- `overdue_1_30`;
- `overdue_31_60`;
- `overdue_61_90`;
- `overdue_91_plus`;
- `undated`;
- `settled`.

Los buckets siguen el precedente estructural P9.3, pero se calculan sobre las
verdades de compras. No se reutiliza ningún ledger, saldo ni estado de CxC.

## Dimensiones de exposición

La exposición nunca mezcla monedas. El resumen global se agrupa por:

`supplier_id + beneficiary_business_party_id + currency_code`.

El proveedor identifica la relación de compra. El beneficiario identifica a
quién corresponde el desembolso. Una obligación logística con tercero no se
absorbe silenciosamente en la cuenta principal del proveedor.

## Estado de cuenta

`SupplierPayableStatementReader` deriva movimientos cronológicos:

- débito: obligación reconocida;
- crédito: pago legacy imputado;
- crédito: allocation de desembolso;
- crédito: nota de crédito aplicada;
- crédito: anticipo aplicado.

El saldo corrido se conserva por beneficiario y moneda. Las autorizaciones y la
evidencia externa no aparecen como liquidaciones. Una obligación cancelada sale
de la exposición abierta, pero permanece completa en el estado de cuenta.

## Autoridad y superficie

- `view-purchases` habilita reporte global y cuenta individual;
- todos los reads se resuelven contra la organización activa;
- un proveedor de otra organización responde 404;
- rutas: `supplier-payables.aging` y `suppliers.account`;
- navegación, Compras y ficha del proveedor exponen accesos explícitos;
- no hay POST, PATCH, DELETE ni exportación automática en P9.8 V1.

## Fuera de alcance

- saldo almacenado o snapshots;
- compensación automática de créditos disponibles no aplicados;
- cambio retroactivo de vencimientos;
- modificación o cancelación de obligaciones;
- contabilización automática;
- fiscalidad ARCA;
- migración de la BD real;
- HTTP externo.

## Tests vinculantes

La frontera P9.8 debe probar:

1. ausencia de tablas de aging/snapshot y rutas explícitas;
2. clasificación `due_date`, `on_receipt` y `other`;
3. separación proveedor, beneficiario y moneda;
4. desembolso parcial y estado de cuenta;
5. obligación liquidada fuera del reporte abierto pero auditable;
6. lectura Viewer tenant-scoped sin mutación;
7. autorización sin desembolso no cambia exposición.

## Próximo corte

Con P9.8, P9 CxC/CxP queda cerrado en V1. El siguiente corte del Roadmap Full
es **P10 — Fiscalidad argentina / ARCA RECON**, preservando el contrato
`venta comercial ≠ comprobante fiscal ≠ autorización fiscal`.
