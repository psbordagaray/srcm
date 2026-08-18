# SRCM Full — Puerta de entrada y continuidad maestra

Estado: **vinculante para recuperación de contexto**
Actualizado: **2026-08-18**
Rama canónica de desarrollo: `feature/core-entity`

## Empezar siempre aquí

Este archivo existe para que un cambio de conversación, versión de asistente o
herramienta no pueda alterar dónde quedó SRCM ni hacia dónde continúa.

El checkpoint canónico actual es siempre el `HEAD` publicado de
`origin/feature/core-entity` cuando local y remoto coinciden y el repositorio
está limpio. La base funcional publicada que este checkpoint documental
sincroniza es:

`1783f95514837d2ec5b44933e9306d17316c5a6b`
— `feat(fiscal): add fiscal concept and service period evidence`

Estado verificado al cierre de P10.7.4:

- P10.1–P10.5: configuración, documento fiscal, hechos de autorización,
  numeración interna fiscal y frontera provider-neutral publicados;
- P10.6.1: readiness de configuración de homologación publicado, sin ejecutar
  WSAA/WSFE ni cargar secretos;
- P10.6.2: preflight de credenciales GREEN confirmó configuración deshabilitada
  y ausencia de credenciales/endpoints activos;
- P10.7.1: perfil fiscal de contraparte y política tributaria versionada;
- P10.7.2: composición tributaria explícita e inmutable por documento;
- P10.7.3: clasificación fiscal explícita e inmutable;
- P10.7.4: concepto fiscal explícito y período de servicios cuando corresponde;
- focales y suite completa GREEN en el checkpoint P10.7.4;
- BD real SQLite SHA-256
  `EC6B45D96173C7E0BBC54D03B0F8B0052502A94A0949D4C8B9427CE9E2830DBF`
  sin cambios;
- repo limpio y staging vacío al publicar P10.7.4.
## Jerarquía de verdad

1. Código, migraciones y tests del checkpoint Git publicado.
2. `docs/06_ROADMAP.md` como estado ejecutivo y secuencia.
3. `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md` como North Star y alcance.
4. ADR y planes especializados aplicables.
5. RESULT del último runner formalmente validado.
6. Conversación y memoria informal, sólo como apoyo.

Una capa inferior no puede contradecir una superior. Una decisión implementada
y validada no se reabre salvo regresión demostrable o decisión explícita de
Dirección.

`docs/README.md` se lee primero porque es el índice de recuperación; no crea una
verdad de dominio adicional ni puede reemplazar la jerarquía anterior.

## Protocolo obligatorio de recuperación

Antes de diseñar o modificar:

1. confirmar proyecto SRCM y rama `feature/core-entity`;
2. ejecutar `git fetch` y obtener branch, HEAD, origin, status y staging;
3. exigir HEAD local = origin y repositorio completamente limpio;
4. leer este archivo, Roadmap y North Star;
5. leer el plan especializado y todos los ADR del dominio afectado;
6. leer el RESULT del último bloque;
7. reconstruir modelos, managers, migraciones, permisos, rutas, UI y tests de la frontera;
8. declarar base exacta, invariantes, fuera de alcance y próximo paso;
9. implementar mediante ZIP autocontenido y runner PowerShell único;
10. validar focales, regresiones, suite completa, BD y gates antes de commit/push.

## Alcance vinculante

SRCM Full es una plataforma horizontal para **retail, mayorista, distribución,
servicios, reparación y omnicanalidad**, desde el comercio unipersonal hasta
supermercados, mayoristas y operaciones multi-sucursal.

El Core común cubre o integra catálogo, Knowledge opcional, stock, compras,
proveedores, ventas, clientes, POS, pagos, caja, tesorería, CxC, CxP,
fiscalidad, posventa, reparaciones, logística, promociones, CRM, fidelización,
hardware, offline, seguridad, observabilidad, analítica, IA, API e
integraciones.

Restaurantes, hoteles, clínicas, farmacias, estaciones de servicio y fábricas
MRP completas se construyen como **verticales sobre SRCM Core** cuando sus
reglas propias lo justifiquen. Reutilizan el Core sin contaminarlo ni
degradarlo.

> La potencia total pertenece a SRCM; la complejidad visible pertenece sólo a
> quien la necesita.

“SRCM lo quiero TODO” significa resolver nativamente o integrar sólidamente el
circuito operativo completo. No significa construir indiscriminadamente todo
desde cero.

## Estado maestro resumido

| Frente | Estado publicado |
| --- | --- |
| Core, Inventario, Reparaciones y Compras base | Implementados y protegidos por ADR/tests |
| P1–P3.1 | Terminal, evidencia, cuentas y conciliación foundation publicados |
| P4A–P4F | Caja, turnos, movimientos, cierre, hechos monetarios y pagos a proveedores publicados |
| P5.1–P5.8.2 | Provider-neutral, Mercado Pago, compatibilidad y health gates publicados |
| P6.1–P6.3 | Centro de conciliación, decisión y resolución publicados |
| P7.1–P7.5 | CSV/XLSX, mapping, commit y fallback manual publicados |
| P8 | Posventa V1 cerrada en P8.5.8 |
| P9.1–P9.6b | CxC, aging, crédito, cuotas, anticipos y excedentes publicados |
| P9.7a–P9.7l | Factura proveedor, match, créditos, anticipos, grupo, desembolso, verificación y resolución externa publicados |
| P9.8 | Exposición, aging y estado de cuenta CxP derivados publicados; P9 V1 cerrado |
| P10.1–P10.5 | Configuración fiscal, documento, hechos de autorización, numeración y frontera de integración publicados |
| P10.6.1 | Readiness de homologación publicado; transporte, secretos y ejecución externa siguen bloqueados |
| P10.7.1–P10.7.4 | Perfil/política fiscal, composición tributaria, clasificación y concepto/período publicados |

P9.7c fue un relevamiento de brechas; no introdujo una verdad productiva nueva.
## Estado funcional P10 al cierre de P10.7.4

P10 mantiene el contrato **venta comercial ≠ comprobante fiscal ≠ autorización
fiscal**. `CommerceSale` sigue siendo la verdad comercial y su `sale_number` no
es autoridad fiscal.

La capa publicada ya contiene configuración fiscal por organización y punto de
venta, documento fiscal append-only, hechos de autorización, numeración fiscal
interna separada, contratos provider-neutral, readiness de homologación,
perfil/política fiscal de contraparte, composición tributaria, clasificación y
concepto fiscal con período de servicios cuando corresponde.

Las decisiones P10.7 son explícitas: SRCM no infiere desde la venta la clase de
comprobante, la alícuota, la composición tributaria ni el concepto fiscal. La
evidencia fiscal se registra de forma separada e inmutable.

No existe todavía ejecución real WSAA/WSFE desde SRCM. No se han habilitado
credenciales productivas ni de homologación, no se inventan CAE/CAEA y no se
considera listo el transporte externo hasta completar el payload fiscal y sus
gates de homologación.

Checkpoint funcional publicado de referencia:
`1783f95514837d2ec5b44933e9306d17316c5a6b`.
## Estado funcional P9.7l

**P9.7k — Supplier Payment External Verification V1** vincula explícitamente
un desembolso non-cash con un `FinancialExternalMovement` `Debit + Posted` de
la misma organización, cuenta y moneda mediante
`PurchasePaymentExternalVerification` append-only.

El ranking de candidatos sólo asiste al Administrador; no decide ni concilia
automáticamente. La evidencia saliente permanece independiente de
`PaymentReconciliation`, que conserva su semántica entrante
`CommercePayment + Credit`. Un mismo movimiento externo no puede respaldar a
la vez cobro, reembolso y desembolso. Diferencias, comisiones y retenciones se
proyectan de forma explícita y no se compensan silenciosamente.

`PurchasePaymentExternalResolution` registra una decisión append-only sobre la
observación externa vigente: excepción de tesorería aceptada o seguimiento con
entidad, proveedor o evidencia. Conserva estado e importes, no modifica CxP y
no fabrica un asiento contable.

## Estado funcional P9.8

`SupplierPayableAgingReader` proyecta la exposición abierta por proveedor,
beneficiario y moneda. `SupplierPayableStatementReader` conserva obligaciones y
las cuatro clases de imputación en un estado de cuenta cronológico.

P9.8 no agrega migración, tabla, snapshot ni saldo mutable. `due_date` usa
`due_on`, `on_receipt` usa la fecha de recepción y `other` permanece sin fecha.
Autorización y evidencia externa no reducen CxP.

## Próximo paso exacto

**P10 — Fiscal Payload Completeness RECON**, estrictamente read-only.

Debe comparar el payload fiscal actualmente persistido con la estructura
necesaria para una futura autorización WSFE, empezando por las brechas que el
Core todavía no representa explícitamente: moneda/cotización cuando aplique,
comprobantes o períodos asociados para notas de crédito/débito, fechas fiscales
condicionales y cualquier otra evidencia requerida por el modo de comprobante.

El RECON no asignará un nuevo subnúmero por simple secuencia: primero debe
confirmar la frontera real. No habilitará WSAA/WSFE, secretos, homologación ni
producción y no modificará ventas ni BD real.
## Registro mínimo de cada relevo

Todo RESULT debe dejar: proyecto, fase, fecha, rama, HEAD/base/origin, ADR,
decisiones vinculantes, paths exactos, migraciones, focales, suite, BD pre/post,
HTTP externo, commit/push, estado final y próximo paso exacto.

Cada checkpoint futuro debe actualizar este archivo y `docs/06_ROADMAP.md` si
cambia el estado de avance. `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md` sólo
se modifica cuando cambia o necesita continuidad explícita la North Star.
