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

`94e7412680561a3d9b25e664b2ba20a3d5a7fa7c`
— `feat(fiscal): add WSFE provider response normalization`

Estado verificado al cierre de **WSFE Provider Response Normalization V1**:

- P10.1–P10.7.4 permanecen publicados y vigentes;
- evidencia WSFE explícita publicada: receptor fiscal, fecha de comprobante,
  resumen monetario, moneda/cotización, vencimiento de pago,
  notas de crédito/débito y comprobantes/períodos asociados;
- autoridad de secuencia remota separada de la numeración fiscal local;
- clasificación de detalle tributario WSFE y composición canónica
  `FeCAEReq` publicadas;
- `FiscalAuthorizationTransportRequest` transporta el `FeCAEReq` canónico y
  permanece libre de secretos;
- contrato efímero de Ticket WSAA publicado: scope explícito por organización,
  ambiente, servicio y CUIT; `Token`/`Sign` privados, redactados y no
  serializables;
- mapa oficial WSAA/WSFE por `FiscalEnvironment` publicado sin habilitar
  producción;
- frontera SOAP 1.1 publicada para `FECAESolicitar` con
  `Auth(Token,Sign,Cuit) + FeCAEReq` y preservación previa del resultado
  provider (`FeCabResp`, `FeDetResp`, CAE, `CAEFchVto`, observaciones,
  Events, Errors y campos desconocidos);
- normalización provider-specific fail-closed publicada:
  `A/A + CAE + CAEFchVto válido → Authorized`,
  `R/R + sin CAE → Rejected`, y cualquier estado parcial, contradictorio,
  incompleto o nuevo → `Unknown`;
- observaciones, Events, Errors y campos provider desconocidos se conservan
  íntegros; no se interpreta el significado de códigos `Obs/Evt/Err`;
- `FiscalAuthorizationTransportResult` continúa deliberadamente grueso; la
  convergencia provider-specific → provider-neutral es la siguiente frontera;
- suite del último cambio funcional: **976 tests / 7393 assertions GREEN**;
- BD real: **88 tablas de negocio**, fingerprint
  `9EC82FF55039B520624D4B189F4FAFA9972480EAB411375893790E5BD7B4BDA0`,
  schema
  `418B3761625FE33CAD8B9E13E3F566001EE68228B947EA9ABBBD449E2B1B6B96`
  y ledger **76 migraciones** /
  `6A10916F7A5514FA4BA8061AC8CCF0F704AB9F4F8D3A2B23954FF113B9A3EB78`;
- no se ejecutó WSAA/WSFE/ARCA real, no existe CAE real y producción sigue
  bloqueada;
- PHP local sigue sin extensión `soap`; esto no invalida las fronteras puras
  ya publicadas, pero bloquea el cliente SOAP real posterior.
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
| P10 — ARCA readiness | `FeCAEReq`, bridge al transport, Ticket WSAA, endpoint map, SOAP 1.1 call/result boundary y provider response normalization publicados; red real aún bloqueada |

P9.7c fue un relevamiento de brechas; no introdujo una verdad productiva nueva.
## Estado funcional P10 al cierre de WSFE Provider Response Normalization V1

P10 mantiene el contrato **venta comercial ≠ comprobante fiscal ≠ autorización
fiscal**. `CommerceSale` sigue siendo la verdad comercial y su `sale_number` no
es autoridad fiscal.

La evidencia necesaria para una solicitud WSFE estándar quedó modelada de forma
explícita e inmutable. SRCM no deriva silenciosamente desde la venta identidad
fiscal del receptor, fecha fiscal, concepto, composición tributaria, moneda,
cotización, vencimiento, asociaciones ni clasificación tributaria.

La numeración fiscal local permanece separada de la autoridad de secuencia
remota. El adapter obtiene el candidato remoto y recién entonces compone el
`FeCAEReq` canónico que atraviesa `FiscalAuthorizationTransportRequest`.

La frontera de proveedor ya distingue:

`evidencia fiscal → FeCAEReq → transport request sin secretos → Ticket WSAA
efímero + CUIT → llamada FECAESolicitar SOAP 1.1`

`Token` y `Sign` sólo se materializan en el borde explícito de proveedor. No se
guardan en el transport request, no se serializan y se redactan en debug.

`WsfeFecaeSoapResultData` preserva el resultado provider completo antes de
normalizar outcome: `FeCabResp`, `FeDetResp`, CAE, `CAEFchVto`, observaciones,
Events, Errors y campos futuros desconocidos.

`WsfeFecaeProviderResponseNormalizer` convierte esa evidencia de forma
provider-specific y fail-closed: sólo `A/A + CAE numérico + CAEFchVto válido`
es `Authorized`; sólo `R/R + ausencia de CAE` es `Rejected`; `P`, faltantes,
contradicciones o códigos nuevos quedan `Unknown`. Las observaciones, Events,
Errors y campos desconocidos se preservan y sus códigos no se reinterpretan.

`FiscalAuthorizationTransportResult` permanece sin modificar. La siguiente
frontera debe decidir la convergencia hacia el resultado provider-neutral y los
hechos de autorización sin perder CAE, vencimiento ni evidencia.

**Preparado para conectar no significa integración ARCA validada.** Todavía no
existen `LoginCms` real, certificado/clave privada/CMS, `SoapClient` habilitado,
WSDL cargado, HTTP WSAA/WSFE ni CAE real. Producción permanece bloqueada.

Checkpoint funcional publicado de referencia:
`94e7412680561a3d9b25e664b2ba20a3d5a7fa7c`.
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

**P10 — WSFE Provider Result Convergence V1**.

Debe partir de `WsfeFecaeNormalizedResponseData` y fijar cómo la verdad
provider-specific converge con `FiscalAuthorizationTransportResult` y con los
hechos de autorización sin perder CAE, `CAEFchVto`, observaciones, Events,
Errors ni el resultado provider preservado.

El corte debe conservar semántica fail-closed (`Authorized`, `Rejected`,
`Unknown`), no interpretar códigos ARCA no demostrados, no abrir HTTP y no
declarar integración ARCA validada.

Después de esa frontera siguen, como cortes separados: material de credenciales
+ CMS/WSAA concreto, habilitación controlada de PHP SOAP, transport SOAP real y
homologación con credenciales válidas.
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
