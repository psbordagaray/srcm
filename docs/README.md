# SRCM Full — Puerta de entrada y continuidad maestra

Estado: **vinculante para recuperación de contexto**
Actualizado: **2026-08-19**
Rama canónica de desarrollo: `feature/core-entity`

## Empezar siempre aquí

Este archivo existe para que un cambio de conversación, versión de asistente o
herramienta no pueda alterar dónde quedó SRCM ni hacia dónde continúa.

El checkpoint canónico actual es siempre el `HEAD` publicado de
`origin/feature/core-entity` cuando local y remoto coinciden y el repositorio
está limpio. La base funcional publicada que este checkpoint documental
sincroniza es:

`b80068970bf261e93362f9adadb8fe609c4e37a0`
— `feat(fiscal): add WSAA access ticket provider`

Estado verificado al cierre de **ARCA WSAA Access Ticket Provider Boundary V1**:

- `WsaaAccessTicketProvider` → `EncryptedCacheWsaaAccessTicketProvider`;
- cache/lock por SHA-256 del scope exacto `organization_id + environment + service + issuer_cuit`;
- `Token`/`Sign` sólo persisten dentro de envelope cifrado; no se serializa `WsaaAccessTicket`;
- TA vigente se reutiliza hasta expiración real; no existe refresh preventivo;
- envelope corrupto/decrypt inválido falla cerrado y no dispara `LoginCms`;
- lock distribuido 60 s / espera 20 s con recheck interno de cache;
- `WsaaTraClock` usa UTC de sistema; uniqueId criptográfico unsigned 32-bit; ventana TRA de proyecto -60/+600 s;
- digest policy: `sha1` sólo en homologación por especificación oficial; producción fail-closed y aceptación provider-real aún **NO VALIDADA**;
- sonda real de cache database + lock + cifrado ejecutada sólo con datos sintéticos y limpieza exacta;
- no hubo certificado/clave/TA real, CMS real, `LoginCms` real ni HTTP ARCA;
- validación funcional: **36/305 focal, 70/480 regresión fiscal y 1034 tests / 7802 assertions GREEN**;
- BD autoritativa preservada: **107 tablas de negocio**, fingerprint
  `D682F392715CFC9EAE886BD1D865DC60415D345E8369B9071EC89FD3436DAC3D`, schema
  `F2653BE8FF9B9160A6E544868478E39B7C37E57123E096BC97756CE902D92F42`
  y **93 migraciones** / `03AC754F8B637811B412AB381F881BB55F3C838D77FCE547748878CB5BA6FC14`;
- SHA binario SQLite continúa siendo canary solamente.

Próximo paso exacto:
`ARCA_WSAA_HOMOLOGATION_LOGIN_CMS_RECON_V1`.

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
| P10.1–P10.7.4 | Configuración fiscal, documento, autorización, numeración, integración, perfil/política, composición, clasificación y concepto/período publicados |
| P10 — Payload WSFE | Receptor, fecha, resumen monetario, moneda/cotización, vencimiento, ajustes, asociaciones, secuencia remota y clasificación tributaria publicados |
| P10 — ARCA readiness | `FeCAEReq`, transport, Ticket WSAA, endpoint map, SOAP 1.1, response normalization y provider-result convergence/persistencia neutral publicados; red real aún bloqueada |

P9.7c fue un relevamiento de brechas; no introdujo una verdad productiva nueva.
## Estado funcional P10 al cierre de ARCA WSAA Access Ticket Provider Boundary V1

P10 mantiene el contrato **venta comercial ≠ comprobante fiscal ≠ autorización fiscal**.

La cadena WSAA ya queda orquestada para homologación: cache cifrado → lock por scope → TRA → material → digest explícito → CMS → `LoginCms` → TA → cache. La reutilización del TA vigente es obligatoria y no hay retry automático.

Producción continúa bloqueada y la aceptación provider-real de SHA-1 todavía no fue demostrada mediante una llamada real.

Checkpoint funcional publicado de referencia:
`b80068970bf261e93362f9adadb8fe609c4e37a0`.

ADR relacionado: `docs/126_ADR_ARCA_WSAA_ACCESS_TICKET_PROVIDER_BOUNDARY_V1.md`.

Próximo paso exacto:
`ARCA_WSAA_HOMOLOGATION_LOGIN_CMS_RECON_V1`.

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

**P10 — ARCA WSAA Homologation LoginCms RECON V1**.

Debe verificar la preparación exacta para una única prueba real de homologación: bindings publicados, configuración habilitable, referencias de credencial presentes sin imprimir ni dereferenciar secretos, cache/lock limpio y política de no-retry; el RECON no ejecutará todavía CMS real, `LoginCms` ni HTTP ARCA.

## Registro mínimo de cada relevo

Todo RESULT debe dejar: proyecto, fase, fecha, rama, HEAD/base/origin, ADR,
decisiones vinculantes, paths exactos, migraciones, focales, suite, BD pre/post,
HTTP externo, commit/push, estado final y próximo paso exacto.

## Gate irrefutable de continuidad documental

**Cada paso que cambie el estado real de SRCM debe actualizar, validar y
publicar los tres documentos maestros de recuperación:**

1. `docs/README.md`;
2. `docs/06_ROADMAP.md`;
3. `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`.

Este gate es obligatorio, irrefutable y excluyente. Si cualquiera de los tres
queda atrasado respecto del checkpoint funcional recién cerrado, el avance no
está documentalmente cerrado y **no se abre la siguiente frontera funcional**.

Secuencia obligatoria:

`implementación → validación GREEN → checkpoint/push → verificación remota →
sincronización de los tres maestros → publicación/verificación documental →
siguiente frontera`.

La North Star no se reescribe innecesariamente, pero su sección dinámica de
continuidad se actualiza en cada paso para conservar checkpoint, estado,
invariantes, bloqueos y próximo paso exacto.
