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

`3875fc270d00a42b241a33002268168a747b985a`
— `feat(fiscal): add WSAA TRA CMS signing boundary`

Estado verificado al cierre de **ARCA WSAA TRA/CMS Signing Boundary V1**:

- toda la progresión fiscal previa hasta WSAA Credential Material Boundary V1
  permanece publicada;
- `WsaaServiceName` endurece `service` al XSD oficial: 3..32 caracteres,
  primera letra y luego sólo letras, dígitos o `_`;
- el hardening se aplica a request WSAA, referencias de credencial, material
  sensible y readiness de homologación;
- `WsaaTra` representa `loginTicketRequest` v1.0 con sólo `uniqueId`,
  `generationTime`, `expirationTime` y `service`;
- `source`/`destination` permanecen omitidos y `issuerCuit` sigue siendo
  metadata de scope SRCM, no un campo TRA;
- `WsaaTraClock`, `WsaaTraUniqueIdProvider`, `WsaaTraBuilder` y
  `WsaaTraWindowPolicy` separan tiempo, identidad y ventana de forma testeable;
- la tolerancia local de ventana nunca puede exceder 86400 segundos por lado;
- `WsaaCmsDigestAlgorithm` hace explícitas las capacidades técnicas SHA-1 y
  SHA-256, pero `WsaaCmsDigestPolicy` queda sin implementación/binding;
- `WsaaCmsSigner` permanece como contrato sin implementación concreta;
- `WsaaSignedCms` exige Base64 puro, conserva digest explícito, redacta contenido
  y rechaza serialización;
- la aceptación real de SHA-1/SHA-256 por ARCA **no está validada**;
- no existe digest por defecto en el dominio;
- validación funcional: **32/229 focal, 56/332 regresión fiscal y 1002 tests / 7574 assertions GREEN**;
- BD real autoritativa sigue sin cambios: **107 tablas de negocio**,
  fingerprint
  `D682F392715CFC9EAE886BD1D865DC60415D345E8369B9071EC89FD3436DAC3D`,
  schema
  `F2653BE8FF9B9160A6E544868478E39B7C37E57123E096BC97756CE902D92F42`
  y ledger **93 migraciones** /
  `03AC754F8B637811B412AB381F881BB55F3C838D77FCE547748878CB5BA6FC14`;
- las 29 migraciones no fiscales siguen deliberadamente pendientes;
- no se dereferenció material real, no se ejecutó signing productivo, no hubo
  `LoginCms`, SOAP ni HTTP ARCA; producción sigue bloqueada.

Próximo paso exacto:
`ARCA_WSAA_CREDENTIAL_MATERIAL_RESOLUTION_RECON_V1`.
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
## Estado funcional P10 al cierre de ARCA WSAA TRA/CMS Signing Boundary V1

P10 mantiene el contrato **venta comercial ≠ comprobante fiscal ≠ autorización
fiscal** y toda la cadena publicada hasta material de credenciales WSAA.

Este corte agrega la frontera TRA/CMS sin cruzar todavía a ejecución externa.

`service` queda alineado con el XSD WSAA. `WsaaTra` materializa localmente el
`loginTicketRequest` v1.0 sólo con `uniqueId`, tiempos y servicio. La generación
queda desacoplada mediante clock, uniqueId provider y política de ventana.

El signing permanece fail-closed: `WsaaCmsSigner` y `WsaaCmsDigestPolicy` son
contratos sin binding. SHA-1 y SHA-256 existen como capacidades técnicas
explícitas, no como afirmación de aceptación ARCA.

`WsaaSignedCms` define la forma que consumirá una futura llamada `LoginCms`:
Base64 puro de CMS attached, sin wrappers PEM/MIME, redactado y no
serializable.

Checkpoint funcional publicado de referencia:
`3875fc270d00a42b241a33002268168a747b985a`.

Próximo paso exacto:
`ARCA_WSAA_CREDENTIAL_MATERIAL_RESOLUTION_RECON_V1`, read-only, para decidir
cómo resolver referencias opacas a PEM real sin imprimir/copiar secretos y cómo
entregar ese material a un signer concreto sin romper aislamiento ni rotación.
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

**P10 — ARCA WSAA Credential Material Resolution RECON V1**.

Debe relevar, estrictamente read-only y sin imprimir secretos, qué mecanismos
locales/operativos pueden resolver las referencias opacas de certificado, clave
privada y passphrase hacia `WsaaCredentialMaterial` para homologación.

No firmará material real, no ejecutará `LoginCms`, no abrirá HTTP WSAA/WSFE, no
habilitará producción y no declarará integración ARCA validada.
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
