# SRCM Full — Puerta de entrada y continuidad maestra

Estado: **vinculante para recuperación de contexto**
Actualizado: **2026-08-28**

<!-- P12_CURRENT_CONTINUITY_V1 -->
## Current P12 checkpoint — P12.2 Operational Device Browser Binding Foundation GREEN

<!--
P12_2_BROWSER_BINDING_STATUS=GREEN_PUBLISHED
P12_2_FUNCTIONAL_CHECKPOINT=889823a566a60ca9ff10aad82547cc6d43156b30
P12_2_FUNCTIONAL_PARENT=5fed76aab34dde60d2241d4ca95db624cecebb74
P12_2_FUNCTIONAL_TREE=6a0222e4ebceba14615ac2d3fca85e3b017c428f
P12_2_CI65_RUN_ID=33200967493
P12_2_CI65_JOB_ID=98950027992
P12_2_DB_MIGRATIONS=124
P12_2_NEXT_BOUNDARY=P12_2_RESTRICTED_OFFLINE_READ_MODEL_SNAPSHOT_RECON_V1
-->

Checkpoint funcional publicado:
`889823a566a60ca9ff10aad82547cc6d43156b30` — `feat(offline): add operational device browser binding`.

P12.2 Browser Binding quedó cerrado funcionalmente y reconciliado post-push. CI65 (`33200967493`) y su job único `quality-gates` (`98950027992`) terminaron `completed / success` en primer intento. `main` permanece protegido e intacto en `fda5cf6f2a9a3e181ea4d29106e874808ecde145`.

La secuencia arquitectónica ya comprobada de P12 es:
1. P12.1 — identidad operacional, capabilities y ledger idempotente de claims;
2. P12.2 RECON — confirmó que el cliente actual es Blade + Alpine + Vite, sin Service Worker, IndexedDB ni cola offline, y que Checkout continúa server-authoritative;
3. P12.2 Browser Binding — vincula un navegador autenticado con un `OperationalDevice` mediante una credencial server-issued separada del UUID público.

La fundación Browser Binding establece:
- token aleatorio de 256 bits emitido por servidor;
- sólo SHA-256 del token persistido en BD; el secreto en claro no se almacena;
- cookie `HttpOnly`, `SameSite=Strict` y política `Secure` para producción/HTTPS;
- expiración de 90 días;
- rotación que revoca bindings previos activos del mismo dispositivo;
- revocación explícita y auditable;
- resolución fail-closed por organización activa, binding vigente y dispositivo activo;
- `public_id` del dispositivo como identificador público, **nunca como credencial**;
- identidad del navegador separada de la autorización del usuario humano;
- endpoint runtime read-only bajo la sesión web existente, sin introducir una API paralela;
- payload runtime sin token ni `token_hash`, con `Cache-Control: no-store, private`.

La migración canónica llevó el ledger local de 123 a **124 migraciones** y creó `operational_device_browser_bindings`. El Post-Push CI RECON verificó la tabla presente, su migración exacta y **0 bindings reales**: este corte creó infraestructura, pero no enroló automáticamente ningún dispositivo.

Límites vinculantes que permanecen cerrados:
- Service Worker: no implementado;
- IndexedDB/persistencia local: no implementada;
- cola de mutaciones offline: no implementada;
- venta final offline: bloqueada;
- finalización de pagos offline: bloqueada;
- autorización fiscal offline: bloqueada;
- merge silencioso de conflictos de precio o stock: prohibido;
- drivers de periféricos, kiosk transaccional y RFID/EAS: fronteras posteriores.

Próxima frontera exacta, obligatoriamente RECON antes de persistencia cliente:
`P12_2_RESTRICTED_OFFLINE_READ_MODEL_SNAPSHOT_RECON_V1`.

Ese RECON debe definir el primer read-model offline seguro: qué catálogo, precios autorizados, disponibilidad/stock, configuración operativa y metadatos pueden snapshotearse; cómo se versionan, cuándo caducan, qué información sensible debe excluirse y qué autoridad prevalece al reconectar. No se agrega Service Worker, IndexedDB ni cola de ventas antes de cerrar ese contrato.

Recovery Anchor Protocol V1 continúa obligatorio. Producción permanece fail-closed y este checkpoint no autoriza deploy, bootstrap ni tráfico público.

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
