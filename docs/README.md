# SRCM Full — Puerta de entrada y continuidad maestra

Estado: **vinculante para recuperación de contexto**
Actualizado: **2026-08-28**

<!-- P12_CURRENT_CONTINUITY_V1 -->
## Current P12 checkpoint — P12.2 Snapshot Reference Panel Foundation GREEN

<!--
P12_2_SNAPSHOT_REFERENCE_PANEL_STATUS=GREEN_PUBLISHED
P12_2_FUNCTIONAL_CHECKPOINT=cd03ddd673bca942a4d13b23192d4a237fab95bb
P12_2_FUNCTIONAL_PARENT=bb86c7379872633a6310ac0f999cafa913d84969
P12_2_FUNCTIONAL_TREE=069be93ab88e8eff903e1b00a0c43ad249f3ba45
P12_2_CI73_RUN_ID=33219420366
P12_2_CI73_JOB_ID=99010136491
P12_2_DB_MIGRATIONS=124
P12_2_SNAPSHOT_CONTRACT_VERSION=2
P12_2_SNAPSHOT_CAPABILITY=restricted_offline_read_model
P12_2_SNAPSHOT_SCOPE_FIELDS=binding_public_id,device_public_id,binding_expires_at
P12_2_INDEXEDDB_STORAGE_SCHEMA_VERSION=1
P12_2_INDEXEDDB_DATABASE_NAME=srcm-restricted-offline-v1
P12_2_INDEXEDDB_OBJECT_STORE=operational-read-model-snapshots
P12_2_INDEXEDDB_RECORD_KEY=current
P12_2_REFERENCE_PANEL_SURFACE=product_catalog_same_page_read_only
P12_2_REFERENCE_PANEL_STATE_MODEL=online-refreshed,offline-cached-valid,no-valid-cache,authority-rejected
P12_2_REFERENCE_PANEL_SEARCH=sku,name,category,brand,manufacturer,snapshot_identifiers
P12_2_REFERENCE_PANEL_FRESHNESS=generated_at,stored_at,exact_age,binding_expiry
P12_2_REFERENCE_PANEL_AUTHORITY=informational_only_server_revalidation_required
P12_2_REFERENCE_PANEL_CONTENT_INTEGRITY=content_fingerprint_revalidated_by_store
P12_2_REFERENCE_PANEL_CONCURRENCY_EVIDENCE=balance_version_informational_only
P12_2_TRUE_OFFLINE_RELOAD_NAVIGATION=NOT_IMPLEMENTED
P12_2_SERVICE_WORKER=NOT_IMPLEMENTED
P12_2_LOCAL_OFFLINE_QUEUE=NOT_IMPLEMENTED
P12_2_POS_OFFLINE_CHECKOUT=NOT_IMPLEMENTED
P12_2_NEXT_BOUNDARY=P12_2_RESTRICTED_OFFLINE_SERVICE_WORKER_APP_SHELL_RECON_V1
-->

Checkpoint funcional publicado:
`cd03ddd673bca942a4d13b23192d4a237fab95bb` — `feat(offline): add snapshot reference panel`.

El primer consumidor UI restringido de Snapshot V2 ya está publicado y CI-verificado. El corte modificó exactamente cuatro paths —dos modificados y dos agregados—, sin migración, sin dependencia nueva, sin cambio de `package-lock.json` y sin cambio de schema/store IndexedDB.

CI73 (`33219420366`) y `quality-gates` (`99010136491`) terminaron `completed / success` en intento 1 sobre el SHA funcional exacto. Pasaron `Offline snapshot store contract tests`, release preflight, build productivo, Vite, full suite y `Tracked tree must remain unchanged`.

Contrato publicado del Reference Panel:
- facade JS `operationalSnapshotReference.js` que consume únicamente el store IndexedDB ya validado;
- no abre IndexedDB directamente ni modifica schema/store;
- primera superficie: **Catálogo → Productos**, dentro del documento autenticado ya cargado;
- panel Alpine estrictamente read-only, sin links ni formularios;
- búsqueda local por SKU, nombre, categoría, marca, fabricante e identificadores del Snapshot V2;
- join local de catálogo + precio + disponibilidad + ubicación + condición;
- cuatro estados visibles: `online-refreshed`, `offline-cached-valid`, `no-valid-cache`, `authority-rejected`;
- muestra `generated_at`, `stored_at`, edad exacta del cache y expiración del Browser Binding;
- integridad heredada del store mediante `content_fingerprint`;
- `balance_version` es evidencia informativa de concurrencia;
- no inventa un umbral arbitrario de “frescura”;
- una negativa de autoridad no expone datos cacheados;
- caída de red/5xx puede seguir mostrando únicamente un cache previamente validado y no expirado;
- precio y disponibilidad cacheados son información de referencia, nunca autoridad.

La semántica de negocio permanece fail-closed:
- el panel no confirma ventas;
- no reserva stock;
- no integra checkout;
- el servidor sigue revalidando precio, disponibilidad y autoridad final.

Limitación de navegación aún vigente:
IndexedDB permite consultar el snapshot mientras la página ya cargada sigue viva, pero **no** convierte por sí solo SRCM en una aplicación navegable tras recargar sin red. No hay Service Worker ni app shell offline todavía.

La BD canónica permanece exactamente en **124 migraciones**, schema `437ab508213ecb039f48b52b99438d1a7e50dcaa28d2e859ea1dad77c0fb7c9c` y binario `9c94e562dff64821b808d3fca5cbed533f54f694ab38336dc6e0a87df132aeb3`. `.env` permanece intacto.

Límites vinculantes:
- Service Worker no implementado;
- app-shell/precache no implementado;
- Background Sync no implementado;
- cola/replay de mutaciones offline no implementada;
- POS offline checkout no implementado;
- venta final offline bloqueada;
- pago final offline bloqueado;
- autorización fiscal offline bloqueada;
- merge silencioso de precio/stock prohibido.

Próxima frontera exacta:
`P12_2_RESTRICTED_OFFLINE_SERVICE_WORKER_APP_SHELL_RECON_V1`.

Ese RECON será estrictamente read-only. Deberá relevar Vite/assets, shell Blade, rutas que necesitan fallback, auth/session, CSP/security headers, lifecycle login/logout/org-switch/unbind y estrategia de cache para determinar si un Service Worker/app shell mínimo puede habilitar **recarga y navegación read-only offline** sin cachear autoridad sensible ni abrir mutaciones.

Recovery Anchor Protocol V1 continúa obligatorio. Producción permanece fail-closed.

Rama canónica de desarrollo: `feature/core-entity`

## Current P11 checkpoint — Protected Main Dispatch Identity + Canonical Alignment

Canonical source checkpoint before this documentation-only consolidation:
`c2333566d273670dd23da9a5cb1208914cb0af93` — `fix(release): require protected main dispatch identity`.
Branch/local/origin main/origin feature were aligned at that exact commit before this cut.
Production release authorization remains fail-closed.

P11 production governance evidence now consolidated:
- the stable production node identity is the logical Tailscale name `straleon-prod-01`; runtime Tailscale IP is telemetry only and is not release identity;
- Safe Smoke Run 3 is GREEN through Tailscale whois/tag/route identity plus pinned SSH, strictly read-only and without application deployment;
- PR #4 rebase-merged the protected-main dispatch hardening; CI35 (feature), CI36 (PR), CI37 (main) and CI38 (feature realignment) are GREEN;
- manual production deploy/bootstrap workflows require a branch dispatch on protected `main` and require requested `release_sha` to equal the dispatch `GITHUB_SHA`;
- bootstrap applies that identity contract before build/install authorization boundaries, while deploy applies it before the protected production job;
- ADR 139 remains the stable-node-identity decision and ADR 140 remains the protected-main-dispatch decision; no new ADR is required by this consolidation;
- local database continuity is canonical by integrity, schema, 122 migrations and non-session logical state; SQLite binary SHA and session row count are telemetry only;
- security, privacy, encrypted off-host backup, restore evidence and continuity remain architectural priorities and are not weakened by this docs-only cut.

Authorization source gates remain closed:
- `release.production_release_enabled=false`;
- `release.initial_application_release_bootstrap_enabled=false`;
- `release.external_gates.production_environment_secrets_and_approvals=false`.

This documentation-only cut does not dispatch a workflow, open SSH, mutate the server,
deploy an application, mutate the production database, authorize bootstrap, or activate
public traffic.

This block is the canonical continuity state and supersedes older P11 checkpoint
narrative preserved below for historical context.

Next exact boundary:
`NEXT_FRONTIER_BOOTSTRAP_AUTHORIZATION_SOURCE_REVIEW_BEFORE_ANY_EXECUTION`.

## Empezar siempre aquí

Este archivo existe para que un cambio de conversación, versión de asistente o
herramienta no pueda alterar dónde quedó SRCM ni hacia dónde continúa.

El checkpoint canónico actual es siempre el `HEAD` publicado de
`origin/feature/core-entity` cuando local y remoto coinciden y el repositorio
está limpio. El checkpoint canonico heredado de P11 y usado para abrir P12 es:

`a3bc84b615d0beeb7729cd8bbe88d1be1910b5c4`
- `feat(release): add single-operator recovery governance`
Estado verificado al cierre de **ARCA WSFE Authorization Runtime Binding V1**:

- `FiscalAuthorizationRuntimeScopeStore` queda enlazado a `EnvironmentFiscalAuthorizationRuntimeScopeStore` y toma `service + issuer_cuit` del mismo mapa tenant-scoped WSAA, sin inferir identidad desde perfil fiscal ni venta;
- `FiscalAuthorizationCredentialStore` queda satisfecho por ese scope store explícito, sin dereferenciar certificado, clave o passphrase;
- `FiscalRemoteSequenceAuthority` → `WsaaBackedFiscalRemoteSequenceAuthority` compone readiness → scope → TA → `FECompUltimoAutorizado`;
- `FiscalAuthorizationTransport` → `WsaaBackedFiscalAuthorizationTransport` compone readiness → scope → TA → `FECAESolicitar` → normalizer → convergence;
- `WsfeFecaeRequestComposerContract`, normalizer y convergence quedan enlazados a sus implementaciones publicadas;
- los wire boundaries DOM/Guzzle de `FECompUltimoAutorizado` y `FECAESolicitar` permanecen separados, inyectables, homologación-only y sin retry automático;
- homologación deshabilitada falla antes de solicitar TA o abrir wire; producción sigue bloqueada;
- WSASS y homologación externa real continúan diferidos por decisión operativa; no hubo certificado/clave/CUIT real, dereferencia de credenciales, CMS, `LoginCms`, `FECompUltimoAutorizado`, `FECAESolicitar` ni HTTP ARCA real;
- ADR relacionado: `docs/129_ADR_ARCA_WSFE_AUTHORIZATION_RUNTIME_BINDING_V1.md`;
- validación funcional: **62/329 focal, 89/461 regresión fiscal y 1052 tests / 7911 assertions GREEN**;
- BD autoritativa preservada: **107 tablas de negocio**, fingerprint
  `D682F392715CFC9EAE886BD1D865DC60415D345E8369B9071EC89FD3436DAC3D`, schema
  `F2653BE8FF9B9160A6E544868478E39B7C37E57123E096BC97756CE902D92F42`
  y **93 migraciones** / `03AC754F8B637811B412AB381F881BB55F3C838D77FCE547748878CB5BA6FC14`;
- SHA binario SQLite continúa siendo canary solamente.

Cierre local P10 verificado en checkpoint documental `7922c51f7f52995c7137094ec7e8be9cbdd32192`: `P10_LOCAL_CLOSURE=GREEN`, repo/BD intactos y evidencia funcional vigente **62/329 focal, 89/461 regresión fiscal y 1052 tests / 7911 assertions GREEN**.

La única deuda P10 remanente es `REAL_ARCA_HOMOLOGATION`; no bloquea P11. WSASS continúa diferido y producción bloqueada.

P11 ya tiene publicados **Production Security Baseline V1** en `b712081c550d2fba36704ec75678eba1f5b73ff9`, **Production Observability Baseline V1** en `a17b8aec8ee583dbb121931fd58fc54663de46c7` y **Production Resilience Baseline V1** en `95b9ae392a7c3a038f6c4db55774f238681ff00d`. Resiliencia agrega snapshot SQLite consistente, retención, verificación de restaurabilidad aislada, objetivos RPO/RTO y freshness gate de backup en readiness; ADR: `docs/132_ADR_PRODUCTION_RESILIENCE_BASELINE_V1.md`.

Validación del corte de resiliencia: **6/36 focal, 19/112 regresión Resilience+Observability+Security y 1077 tests / 8059 assertions GREEN**. La BD real permaneció exacta en el baseline canónico y las pruebas de backup/restore usaron SQLite sintética temporal: no hubo backup ni restore sobre `database/database.sqlite`. RPO objetivo: **60 min**; RTO objetivo: **240 min**; retención baseline: **168 snapshots**; freshness gate: **90 min**.

Cloudflare R2 provider configuration, real encrypted backup export and operational restore drill are now evidenced and closed as resilience release gates; production secrets/approvals and the global production switch remain blocked.

La deuda P10 `REAL_ARCA_HOMOLOGATION` y WSASS siguen diferidos y no bloquean P11.

Próximo paso exacto:
`P11_PRODUCTION_ENVIRONMENT_SECRETS_AND_APPROVALS_RECON_V1`.

CI/CD Release Gates V1 publica workflow versionado con checkout fijado por SHA, permisos mínimos, instalaciones desde lockfiles, `git diff --check`, full suite, build y `srcm:release-preflight --ci`. El preflight inventaría migraciones sin ejecutar migrate/rollback, exige `down()` no vacío y valida `GET /api/health/ready`. Producción continúa fail-closed: backup off-host cifrado y restore drill operativo ya están cerrados por evidencia; secretos/aprobaciones de producción y el switch global siguen bloqueados. ADR: `docs/133_ADR_PRODUCTION_CI_CD_RELEASE_GATES_V1.md`. Validación: **6/32 focal, 31/180 regresión Release+Resilience+Observability+Security, 1083/8091 full + asset build GREEN**.

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
| P11 - Production gates and off-host backup | Security, Observability, Resilience and CI/CD published; encrypted off-host capability 0a433cb2ed22ea08c44f8c5c299749aacb5feb75; S3-compatible adapter foundation c154eeda909ba70ba36931fc9d58615db13fd418 plus CI hotfix cfb7d784d5f898b6c04a72ac473f69e95d91c603; provider not configured; P11_PRODUCTION_ENVIRONMENT_SECRETS_AND_APPROVALS_RECON_V1 next |

P9.7c fue un relevamiento de brechas; no introdujo una verdad productiva nueva.

## Estado funcional P11 — Production Baselines V1

Security checkpoint: `b712081c550d2fba36704ec75678eba1f5b73ff9` — `feat(security): add production security baseline`.

Observability checkpoint: `a17b8aec8ee583dbb121931fd58fc54663de46c7` — `feat(observability): add production observability baseline`.

Resilience checkpoint: `95b9ae392a7c3a038f6c4db55774f238681ff00d` — `feat(resilience): add production backup restore baseline`.

Security conserva headers HTTP globales conservadores, producción fail-closed por configuración insegura, step-up por contraseña reciente en mutaciones de alto impacto y guard de secret/config hygiene.

Observability conserva `request_id`/`correlation_id` globales, contexto de excepciones, canal `stderr_json`, señales seguras de queue/jobs/integraciones, limpieza de contexto en workers largos y readiness `GET /api/health/ready`.

Resilience publica:
- `SqliteBackupManager` con snapshot consistente mediante `VACUUM INTO`, sin copiar el archivo SQLite vivo;
- comando `srcm:backup-database` y scheduler horario `withoutOverlapping`;
- SHA-256 + manifiesto por snapshot y retención por defecto de **168** snapshots;
- comando `srcm:verify-database-backup` que verifica checksum e integridad sobre una copia temporal aislada, sin exponer restore real;
- política de producción que exige directorio de backup fuera del árbol del repo;
- readiness de backup verificado fresco con ventana **90 min**;
- objetivos **RPO 60 min** y **RTO 240 min**;
- ADR 132 aceptado.

Validación resilience: **6/36 focal, 19/112 regresión Resilience+Observability+Security y 1077 tests / 8059 assertions GREEN**. BD canónica intacta, sin migrate/rollback, sin backup/restore sobre la BD real y sin ARCA real.

Release state P11: copia off-host cifrada y restore drill operativo cerrados con evidencia real; CI/CD versionado publicado; secretos/aprobaciones del entorno de producción y el switch global siguen bloqueados. Outbox/OpenTelemetry y hardenings avanzados permanecen diferidos.

Próximo paso exacto: `P11_PRODUCTION_ENVIRONMENT_SECRETS_AND_APPROVALS_RECON_V1`. Debe relevar workflows reales, gates de tests/build, migraciones/deploy, rollback/release, artefactos, secretos de CI y el gate off-host/encrypted backup antes de implementar automatización adicional.

## Estado funcional P10 al cierre de ARCA WSFE Authorization Runtime Binding V1

P10 mantiene el contrato **venta comercial ≠ comprobante fiscal ≠ autorización fiscal**.

El runtime local de autorización WSFE ya está estructuralmente completo: el adapter existente consulta la secuencia remota, compone el `FeCAEReq` con evidencia fiscal explícita y delega la autorización a un transporte neutral. Los adaptadores runtime obtienen scope WSAA explícito por tenant, reutilizan `WsaaAccessTicketProvider` y consumen los wire boundaries separados de `FECompUltimoAutorizado` y `FECAESolicitar`.

Esto **no habilita ARCA real**: con homologación deshabilitada se falla antes de TA/wire, producción continúa bloqueada y WSASS/identidad real siguen diferidos.

Checkpoint funcional publicado de referencia:
`b8a97ddb84ee64e2858a559798e5a83ad953202a`.

ADR relacionado: `docs/129_ADR_ARCA_WSFE_AUTHORIZATION_RUNTIME_BINDING_V1.md`.

Cierre posterior: P10 quedó **LOCAL_CLOSURE=GREEN** en `7922c51f7f52995c7137094ec7e8be9cbdd32192`; `REAL_ARCA_HOMOLOGATION` y WSASS permanecen como deuda externa diferida, sin reactivar tráfico ARCA.

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

**P11 — Production CI/CD & Release Gates RECON V1** (`P11_PRODUCTION_ENVIRONMENT_SECRETS_AND_APPROVALS_RECON_V1`).

Debe ser estrictamente read-only y focal: verificar workflows/pipelines reales, gates automáticos de tests y build, estrategia de migraciones/deploy/rollback, artefactos y secretos de CI, y el release gate de backup off-host cifrado. No crear workflow, deploy automation ni proveedor remoto antes del relevamiento.

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
