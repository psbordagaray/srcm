# ADR 89 — Grouped Supplier Payment Foundation V1

Estado: Aceptada para P9.7h

Checkpoint de partida:
`acca89ccd770134294b15e07d4b70b5b71583a12`

Fecha: 2026-08-17

## Contexto

P9.7 ya posee obligaciones, pagos parciales individuales,
notas de crédito, anticipos y sus aplicaciones explícitas.

La brecha siguiente del roadmap es pagar varias obligaciones
en una misma operación real sin perder la imputación exacta
por deuda.

P9.7h establece primero la autorización agrupada. El
desembolso efectivo y no-cash se generalizará en P9.7i.

## Decisión

Se incorpora:

`PurchasePaymentGroupRequest`
→ `PurchasePaymentGroupRequestItem`

La cabecera representa una sola intención/autorización de
pago. Cada item fija una obligación y un importe.

El total del grupo es derivado de la suma de items; no se
materializa un segundo campo mutable de total.

## Reglas de agrupación

1. Un grupo requiere al menos dos obligaciones distintas.
2. Todas deben pertenecer a la misma organización.
3. Todas comparten proveedor, beneficiario y moneda.
4. Una única cuenta de origen activa queda fijada para el
   grupo y debe usar esa moneda.
5. Cada item puede ser parcial, pero no superar el saldo
   económico derivado de su obligación.
6. Una obligación no puede repetirse dentro del grupo.
7. Una obligación con solicitud individual `pending` o
   `approved` no puede ingresar a un grupo.
8. Una obligación ya reservada por otro grupo activo tampoco
   puede ingresar.
9. Mientras un grupo está `pending` o `approved`, se bloquean
   nuevas solicitudes individuales y nuevas aplicaciones de
   crédito/anticipo sobre esas obligaciones.
10. Cancelar o rechazar el grupo libera esas obligaciones.

## Autoridad y segregación

- Admin u Operator pueden solicitar.
- Sólo Admin puede aprobar o rechazar.
- El solicitante no puede aprobar ni rechazar su propia
  solicitud.
- El solicitante puede cancelar su solicitud activa.
- Un Admin distinto también puede cancelarla.

## Efecto económico

P9.7h no mueve dinero y no extingue deuda.

No crea:

- `PurchasePaymentExecution`;
- `CashMovement`;
- `FinancialExternalMovement`;
- aplicación de nota de crédito;
- aplicación de anticipo.

La autorización agrupada reserva el plan de desembolso y
mantiene estables las obligaciones hasta su ejecución o
cancelación.

## Inmutabilidad

Los items son append-only e inmutables.

La cabecera conserva inmutables sus hechos base y sólo admite
transiciones explícitas de lifecycle:

- pending → approved;
- pending → rejected;
- pending → cancelled;
- approved → cancelled.

`executed` queda deliberadamente reservado para P9.7i.

## Base de datos

SQLite protege también la exclusión entre grupos activos.

MySQL/MariaDB evita lecturas/agregados sobre la misma tabla
desde su propio trigger. Allí la exclusión entre dos grupos
activos se protege transaccionalmente en el manager y vuelve
a validarse antes de aprobar; los cruces con solicitudes
individuales y aplicaciones de crédito sí poseen guards
cross-table.

## Fuera de alcance

- ejecución cash del grupo;
- ejecución non-cash del grupo;
- ejecución non-cash individual general;
- una operación que mezcle beneficiarios o monedas;
- neteo automático;
- fiscalidad ARCA;
- migración de la BD real.

## Próximo corte

P9.7i — Supplier Payment Execution Generalization:
ejecución cash/non-cash de autorizaciones individuales y
agrupadas, con una sola verdad monetaria por desembolso.
