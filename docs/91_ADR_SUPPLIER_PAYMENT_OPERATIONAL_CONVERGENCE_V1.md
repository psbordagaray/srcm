# ADR 91 — Supplier Payment Operational Convergence V1

Estado: Aceptada para P9.7j

Checkpoint de partida:
`a0da002495ac9b7c8b37903ffc365eb894e9bec7`

Fecha: 2026-08-17

## Contexto

P9.7i publicó la verdad canónica
`PurchasePaymentDisbursement → PurchasePaymentDisbursementAllocation`, pero
dejó deliberadamente la ruta HTTP individual sobre
`PurchasePaymentExecution` legacy y no expuso la autorización agrupada.

El dominio podía ejecutar individual o agrupado, cash o non-cash, mientras la
operación visible sólo podía ejecutar individual cash. El control posterior
también seguía leyendo únicamente el ledger legacy.

## Decisión

P9.7j converge la superficie operacional sobre los managers ya publicados:

- la ruta individual ejecuta `executeIndividual`;
- la autorización agrupada obtiene creación, aprobación, rechazo, cancelación
  y ejecución HTTP explícitas;
- el centro “Pagos a proveedores” permite formar grupos compatibles entre
  órdenes distintas;
- cash y non-cash se presentan con confirmación irreversible y evidencia
  requerida según canal;
- el control posterior lee el desembolso canónico sin mutarlo.

No se introduce otro ledger ni se duplica lógica monetaria en controladores o
vistas.

## Compatibilidad legacy

`PurchasePaymentExecution` continúa append-only como historia P4F.3. Su manager,
modelo, migración, relaciones y controles permanecen disponibles para hechos
ya registrados y pruebas de regresión.

Desde P9.7j, ninguna ruta HTTP nueva crea ejecuciones legacy. Las autorizaciones
individuales nuevas y existentes que todavía estén `Approved` se consumen por
`PurchasePaymentDisbursement`.

## Operación individual

La pantalla de la orden:

- ejecuta cash desde `CashBox` con turno propio y un único `CashMovement`;
- ejecuta non-cash desde cuenta bancaria, billetera, adquirente u otra cuenta;
- exige referencia para non-cash;
- muestra el canal, la imputación y el control posterior;
- calcula saldo con legacy + allocations + créditos + anticipos.

`CashReserve` se excluye de las cuentas proponibles de esta superficie porque
su salida requiere el flujo de Tesorería ya decidido.

## Operación agrupada

El centro sólo ofrece obligaciones que:

- poseen saldo económico derivado positivo;
- no tienen solicitud individual activa;
- no están reservadas por otro grupo activo;
- comparten proveedor, beneficiario y moneda;
- poseen al menos una cuenta ejecutable compatible.

La selección requiere dos o más obligaciones. Los importes pueden ser
parciales al solicitar, pero una autorización agrupada se ejecuta completa y
atómicamente.

## Control posterior

Para cash se verifica que:

- exista exactamente un `CashMovement` enlazado al parent;
- coincidan organización, cuenta, caja, turno, actor, moneda e importe;
- el tipo sea `purchase_payment_disbursement`;
- el arqueo/cierre sea el control físico posterior.

Para non-cash se verifica que:

- la cuenta no sea física;
- exista referencia de ejecución;
- no existan caja ni turno;
- no se haya fabricado un `CashMovement`.

El estado queda `external_verification_pending`. P9.7j no crea
`FinancialExternalMovement` ni concilia automáticamente.

## Autoridad y aislamiento

- Viewer puede consultar, nunca mutar.
- Admin u Operator pueden solicitar y ejecutar según capacidades existentes.
- sólo Admin puede aprobar o rechazar.
- solicitante, aprobador y ejecutor conservan las segregaciones de ADR 89/90.
- route binding, consultas y managers permanecen privados por organización.

## Integridad operacional

La UI no decide saldos ni compatibilidad como autoridad. Sólo construye una
solicitud; los managers vuelven a bloquear y validar todos los hechos dentro de
transacción.

Una repetición exacta usa idempotencia. Un cambio de hechos con la misma clave
falla cerrado.

## Fuera de alcance

- matching automático con un movimiento bancario/provider;
- comisiones, retenciones o diferencia bancaria;
- ejecución parcial de un grupo aprobado;
- grupos multi-proveedor, multi-beneficiario o multi-moneda;
- pago directo desde `CashReserve`;
- migración de la BD real;
- reescritura o backfill del ledger legacy;
- fiscalidad ARCA.

## Próximo corte

P9.7k — Supplier Payment External Verification RECON: relevar la frontera de
matching contra `FinancialExternalMovement`, cuentas, moneda, importe,
referencias e idempotencia antes de diseñar conciliación de pagos salientes.
