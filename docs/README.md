# SRCM Full â€” Puerta de entrada y continuidad maestra

Estado: **vinculante para recuperaciÃ³n de contexto**
Actualizado: **2026-08-20**
Rama canÃ³nica de desarrollo: `feature/core-entity`

## Current P11 checkpoint - S3-Compatible Remote Adapter Foundation

Encrypted off-host backup capability remains published at 0a433cb2ed22ea08c44f8c5c299749aacb5feb75.
S3-compatible remote adapter foundation remains published at c154eeda909ba70ba36931fc9d58615db13fd418.
CI hotfix checkpoint: cfb7d784d5f898b6c04a72ac473f69e95d91c603 - fix(resilience): normalize blank S3 backup configuration.
The hotfix only normalizes empty dedicated S3 configuration slots to null.
No provider is selected, no credentials are added, no remote provider I/O is executed, and no schedule is enabled.
Functional GitHub Actions push run: 32435048926 - GREEN.
The authoritative SQLite database remained unchanged.
Production remains blocked and off_host_encrypted_backup=false.
Next exact boundary: P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1.

## Empezar siempre aquÃ­

Este archivo existe para que un cambio de conversaciÃ³n, versiÃ³n de asistente o
herramienta no pueda alterar dÃ³nde quedÃ³ SRCM ni hacia dÃ³nde continÃºa.

El checkpoint canÃ³nico actual es siempre el `HEAD` publicado de
`origin/feature/core-entity` cuando local y remoto coinciden y el repositorio
estÃ¡ limpio. La base funcional publicada que este checkpoint documental
sincroniza es:

`cfb7d784d5f898b6c04a72ac473f69e95d91c603`
â€” `fix(resilience): normalize blank S3 backup configuration`

Estado verificado al cierre de **ARCA WSFE Authorization Runtime Binding V1**:

- `FiscalAuthorizationRuntimeScopeStore` queda enlazado a `EnvironmentFiscalAuthorizationRuntimeScopeStore` y toma `service + issuer_cuit` del mismo mapa tenant-scoped WSAA, sin inferir identidad desde perfil fiscal ni venta;
- `FiscalAuthorizationCredentialStore` queda satisfecho por ese scope store explÃ­cito, sin dereferenciar certificado, clave o passphrase;
- `FiscalRemoteSequenceAuthority` â†’ `WsaaBackedFiscalRemoteSequenceAuthority` compone readiness â†’ scope â†’ TA â†’ `FECompUltimoAutorizado`;
- `FiscalAuthorizationTransport` â†’ `WsaaBackedFiscalAuthorizationTransport` compone readiness â†’ scope â†’ TA â†’ `FECAESolicitar` â†’ normalizer â†’ convergence;
- `WsfeFecaeRequestComposerContract`, normalizer y convergence quedan enlazados a sus implementaciones publicadas;
- los wire boundaries DOM/Guzzle de `FECompUltimoAutorizado` y `FECAESolicitar` permanecen separados, inyectables, homologaciÃ³n-only y sin retry automÃ¡tico;
- homologaciÃ³n deshabilitada falla antes de solicitar TA o abrir wire; producciÃ³n sigue bloqueada;
- WSASS y homologaciÃ³n externa real continÃºan diferidos por decisiÃ³n operativa; no hubo certificado/clave/CUIT real, dereferencia de credenciales, CMS, `LoginCms`, `FECompUltimoAutorizado`, `FECAESolicitar` ni HTTP ARCA real;
- ADR relacionado: `docs/129_ADR_ARCA_WSFE_AUTHORIZATION_RUNTIME_BINDING_V1.md`;
- validaciÃ³n funcional: **62/329 focal, 89/461 regresiÃ³n fiscal y 1052 tests / 7911 assertions GREEN**;
- BD autoritativa preservada: **107 tablas de negocio**, fingerprint
  `D682F392715CFC9EAE886BD1D865DC60415D345E8369B9071EC89FD3436DAC3D`, schema
  `F2653BE8FF9B9160A6E544868478E39B7C37E57123E096BC97756CE902D92F42`
  y **93 migraciones** / `03AC754F8B637811B412AB381F881BB55F3C838D77FCE547748878CB5BA6FC14`;
- SHA binario SQLite continÃºa siendo canary solamente.

Cierre local P10 verificado en checkpoint documental `7922c51f7f52995c7137094ec7e8be9cbdd32192`: `P10_LOCAL_CLOSURE=GREEN`, repo/BD intactos y evidencia funcional vigente **62/329 focal, 89/461 regresiÃ³n fiscal y 1052 tests / 7911 assertions GREEN**.

La Ãºnica deuda P10 remanente es `REAL_ARCA_HOMOLOGATION`; no bloquea P11. WSASS continÃºa diferido y producciÃ³n bloqueada.

P11 ya tiene publicados **Production Security Baseline V1** en `b712081c550d2fba36704ec75678eba1f5b73ff9`, **Production Observability Baseline V1** en `a17b8aec8ee583dbb121931fd58fc54663de46c7` y **Production Resilience Baseline V1** en `95b9ae392a7c3a038f6c4db55774f238681ff00d`. Resiliencia agrega snapshot SQLite consistente, retenciÃ³n, verificaciÃ³n de restaurabilidad aislada, objetivos RPO/RTO y freshness gate de backup en readiness; ADR: `docs/132_ADR_PRODUCTION_RESILIENCE_BASELINE_V1.md`.

ValidaciÃ³n del corte de resiliencia: **6/36 focal, 19/112 regresiÃ³n Resilience+Observability+Security y 1077 tests / 8059 assertions GREEN**. La BD real permaneciÃ³ exacta en el baseline canÃ³nico y las pruebas de backup/restore usaron SQLite sintÃ©tica temporal: no hubo backup ni restore sobre `database/database.sqlite`. RPO objetivo: **60 min**; RTO objetivo: **240 min**; retenciÃ³n baseline: **168 snapshots**; freshness gate: **90 min**.

Remote provider configuration, provider smoke, operational restore drill, production secrets and approvals remain open release gates; production remains blocked.

La deuda P10 `REAL_ARCA_HOMOLOGATION` y WSASS siguen diferidos y no bloquean P11.

PrÃ³ximo paso exacto:
`P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1`.

CI/CD Release Gates V1 publica workflow versionado con checkout fijado por SHA, permisos mÃ­nimos, instalaciones desde lockfiles, `git diff --check`, full suite, build y `srcm:release-preflight --ci`. El preflight inventarÃ­a migraciones sin ejecutar migrate/rollback, exige `down()` no vacÃ­o y valida `GET /api/health/ready`. ProducciÃ³n continÃºa fail-closed: backup off-host cifrado, restore drill operativo y secretos/aprobaciones de producciÃ³n siguen abiertos. ADR: `docs/133_ADR_PRODUCTION_CI_CD_RELEASE_GATES_V1.md`. ValidaciÃ³n: **6/32 focal, 31/180 regresiÃ³n Release+Resilience+Observability+Security, 1083/8091 full + asset build GREEN**.

## JerarquÃ­a de verdad

1. CÃ³digo, migraciones y tests del checkpoint Git publicado.
2. `docs/06_ROADMAP.md` como estado ejecutivo y secuencia.
3. `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md` como North Star y alcance.
4. ADR y planes especializados aplicables.
5. RESULT del Ãºltimo runner formalmente validado.
6. ConversaciÃ³n y memoria informal, sÃ³lo como apoyo.

Una capa inferior no puede contradecir una superior. Una decisiÃ³n implementada
y validada no se reabre salvo regresiÃ³n demostrable o decisiÃ³n explÃ­cita de
DirecciÃ³n.

`docs/README.md` se lee primero porque es el Ã­ndice de recuperaciÃ³n; no crea una
verdad de dominio adicional ni puede reemplazar la jerarquÃ­a anterior.

## Protocolo obligatorio de recuperaciÃ³n

Antes de diseÃ±ar o modificar:

1. confirmar proyecto SRCM y rama `feature/core-entity`;
2. ejecutar `git fetch` y obtener branch, HEAD, origin, status y staging;
3. exigir HEAD local = origin y repositorio completamente limpio;
4. leer este archivo, Roadmap y North Star;
5. leer el plan especializado y todos los ADR del dominio afectado;
6. leer el RESULT del Ãºltimo bloque;
7. reconstruir modelos, managers, migraciones, permisos, rutas, UI y tests de la frontera;
8. declarar base exacta, invariantes, fuera de alcance y prÃ³ximo paso;
9. implementar mediante ZIP autocontenido y runner PowerShell Ãºnico;
10. validar focales, regresiones, suite completa, BD y gates antes de commit/push.

## Alcance vinculante

SRCM Full es una plataforma horizontal para **retail, mayorista, distribuciÃ³n,
servicios, reparaciÃ³n y omnicanalidad**, desde el comercio unipersonal hasta
supermercados, mayoristas y operaciones multi-sucursal.

El Core comÃºn cubre o integra catÃ¡logo, Knowledge opcional, stock, compras,
proveedores, ventas, clientes, POS, pagos, caja, tesorerÃ­a, CxC, CxP,
fiscalidad, posventa, reparaciones, logÃ­stica, promociones, CRM, fidelizaciÃ³n,
hardware, offline, seguridad, observabilidad, analÃ­tica, IA, API e
integraciones.

Restaurantes, hoteles, clÃ­nicas, farmacias, estaciones de servicio y fÃ¡bricas
MRP completas se construyen como **verticales sobre SRCM Core** cuando sus
reglas propias lo justifiquen. Reutilizan el Core sin contaminarlo ni
degradarlo.

> La potencia total pertenece a SRCM; la complejidad visible pertenece sÃ³lo a
> quien la necesita.

â€œSRCM lo quiero TODOâ€ significa resolver nativamente o integrar sÃ³lidamente el
circuito operativo completo. No significa construir indiscriminadamente todo
desde cero.

## Estado maestro resumido

| Frente | Estado publicado |
| --- | --- |
| Core, Inventario, Reparaciones y Compras base | Implementados y protegidos por ADR/tests |
| P1â€“P3.1 | Terminal, evidencia, cuentas y conciliaciÃ³n foundation publicados |
| P4Aâ€“P4F | Caja, turnos, movimientos, cierre, hechos monetarios y pagos a proveedores publicados |
| P5.1â€“P5.8.2 | Provider-neutral, Mercado Pago, compatibilidad y health gates publicados |
| P6.1â€“P6.3 | Centro de conciliaciÃ³n, decisiÃ³n y resoluciÃ³n publicados |
| P7.1â€“P7.5 | CSV/XLSX, mapping, commit y fallback manual publicados |
| P8 | Posventa V1 cerrada en P8.5.8 |
| P9.1â€“P9.6b | CxC, aging, crÃ©dito, cuotas, anticipos y excedentes publicados |
| P9.7aâ€“P9.7l | Factura proveedor, match, crÃ©ditos, anticipos, grupo, desembolso, verificaciÃ³n y resoluciÃ³n externa publicados |
| P9.8 | ExposiciÃ³n, aging y estado de cuenta CxP derivados publicados; P9 V1 cerrado |
| P10.1â€“P10.7.4 | ConfiguraciÃ³n fiscal, documento, autorizaciÃ³n, numeraciÃ³n, integraciÃ³n, perfil/polÃ­tica, composiciÃ³n, clasificaciÃ³n y concepto/perÃ­odo publicados |
| P10 â€” Payload WSFE | Receptor, fecha, resumen monetario, moneda/cotizaciÃ³n, vencimiento, ajustes, asociaciones, secuencia remota y clasificaciÃ³n tributaria publicados |
| P10 â€” ARCA readiness | `FeCAEReq`, transport, Ticket WSAA, endpoint map, SOAP 1.1, response normalization y provider-result convergence/persistencia neutral publicados; red real aÃºn bloqueada |
| P11 - Production gates and off-host backup | Security, Observability, Resilience and CI/CD published; encrypted off-host capability 0a433cb2ed22ea08c44f8c5c299749aacb5feb75; S3-compatible adapter foundation c154eeda909ba70ba36931fc9d58615db13fd418 plus CI hotfix cfb7d784d5f898b6c04a72ac473f69e95d91c603; provider not configured; P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1 next |

P9.7c fue un relevamiento de brechas; no introdujo una verdad productiva nueva.

## Estado funcional P11 â€” Production Baselines V1

Security checkpoint: `b712081c550d2fba36704ec75678eba1f5b73ff9` â€” `feat(security): add production security baseline`.

Observability checkpoint: `a17b8aec8ee583dbb121931fd58fc54663de46c7` â€” `feat(observability): add production observability baseline`.

Resilience checkpoint: `95b9ae392a7c3a038f6c4db55774f238681ff00d` â€” `feat(resilience): add production backup restore baseline`.

Security conserva headers HTTP globales conservadores, producciÃ³n fail-closed por configuraciÃ³n insegura, step-up por contraseÃ±a reciente en mutaciones de alto impacto y guard de secret/config hygiene.

Observability conserva `request_id`/`correlation_id` globales, contexto de excepciones, canal `stderr_json`, seÃ±ales seguras de queue/jobs/integraciones, limpieza de contexto en workers largos y readiness `GET /api/health/ready`.

Resilience publica:
- `SqliteBackupManager` con snapshot consistente mediante `VACUUM INTO`, sin copiar el archivo SQLite vivo;
- comando `srcm:backup-database` y scheduler horario `withoutOverlapping`;
- SHA-256 + manifiesto por snapshot y retenciÃ³n por defecto de **168** snapshots;
- comando `srcm:verify-database-backup` que verifica checksum e integridad sobre una copia temporal aislada, sin exponer restore real;
- polÃ­tica de producciÃ³n que exige directorio de backup fuera del Ã¡rbol del repo;
- readiness de backup verificado fresco con ventana **90 min**;
- objetivos **RPO 60 min** y **RTO 240 min**;
- ADR 132 aceptado.

ValidaciÃ³n resilience: **6/36 focal, 19/112 regresiÃ³n Resilience+Observability+Security y 1077 tests / 8059 assertions GREEN**. BD canÃ³nica intacta, sin migrate/rollback, sin backup/restore sobre la BD real y sin ARCA real.

Release gates todavÃ­a abiertos: copia off-host cifrada, proveedor remoto/KMS, CI/CD y deploy gates reales, ademÃ¡s de outbox/OpenTelemetry y los hardenings avanzados de seguridad ya diferidos. La existencia de documentaciÃ³n o scripts locales no cuenta como CI/CD implementado.

PrÃ³ximo paso exacto: `P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1`. Debe relevar workflows reales, gates de tests/build, migraciones/deploy, rollback/release, artefactos, secretos de CI y el gate off-host/encrypted backup antes de implementar automatizaciÃ³n adicional.

## Estado funcional P10 al cierre de ARCA WSFE Authorization Runtime Binding V1

P10 mantiene el contrato **venta comercial â‰  comprobante fiscal â‰  autorizaciÃ³n fiscal**.

El runtime local de autorizaciÃ³n WSFE ya estÃ¡ estructuralmente completo: el adapter existente consulta la secuencia remota, compone el `FeCAEReq` con evidencia fiscal explÃ­cita y delega la autorizaciÃ³n a un transporte neutral. Los adaptadores runtime obtienen scope WSAA explÃ­cito por tenant, reutilizan `WsaaAccessTicketProvider` y consumen los wire boundaries separados de `FECompUltimoAutorizado` y `FECAESolicitar`.

Esto **no habilita ARCA real**: con homologaciÃ³n deshabilitada se falla antes de TA/wire, producciÃ³n continÃºa bloqueada y WSASS/identidad real siguen diferidos.

Checkpoint funcional publicado de referencia:
`b8a97ddb84ee64e2858a559798e5a83ad953202a`.

ADR relacionado: `docs/129_ADR_ARCA_WSFE_AUTHORIZATION_RUNTIME_BINDING_V1.md`.

Cierre posterior: P10 quedÃ³ **LOCAL_CLOSURE=GREEN** en `7922c51f7f52995c7137094ec7e8be9cbdd32192`; `REAL_ARCA_HOMOLOGATION` y WSASS permanecen como deuda externa diferida, sin reactivar trÃ¡fico ARCA.

## Estado funcional P9.7l

**P9.7k â€” Supplier Payment External Verification V1** vincula explÃ­citamente
un desembolso non-cash con un `FinancialExternalMovement` `Debit + Posted` de
la misma organizaciÃ³n, cuenta y moneda mediante
`PurchasePaymentExternalVerification` append-only.

El ranking de candidatos sÃ³lo asiste al Administrador; no decide ni concilia
automÃ¡ticamente. La evidencia saliente permanece independiente de
`PaymentReconciliation`, que conserva su semÃ¡ntica entrante
`CommercePayment + Credit`. Un mismo movimiento externo no puede respaldar a
la vez cobro, reembolso y desembolso. Diferencias, comisiones y retenciones se
proyectan de forma explÃ­cita y no se compensan silenciosamente.

`PurchasePaymentExternalResolution` registra una decisiÃ³n append-only sobre la
observaciÃ³n externa vigente: excepciÃ³n de tesorerÃ­a aceptada o seguimiento con
entidad, proveedor o evidencia. Conserva estado e importes, no modifica CxP y
no fabrica un asiento contable.

## Estado funcional P9.8

`SupplierPayableAgingReader` proyecta la exposiciÃ³n abierta por proveedor,
beneficiario y moneda. `SupplierPayableStatementReader` conserva obligaciones y
las cuatro clases de imputaciÃ³n en un estado de cuenta cronolÃ³gico.

P9.8 no agrega migraciÃ³n, tabla, snapshot ni saldo mutable. `due_date` usa
`due_on`, `on_receipt` usa la fecha de recepciÃ³n y `other` permanece sin fecha.
AutorizaciÃ³n y evidencia externa no reducen CxP.

## PrÃ³ximo paso exacto

**P11 â€” Production CI/CD & Release Gates RECON V1** (`P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1`).

Debe ser estrictamente read-only y focal: verificar workflows/pipelines reales, gates automÃ¡ticos de tests y build, estrategia de migraciones/deploy/rollback, artefactos y secretos de CI, y el release gate de backup off-host cifrado. No crear workflow, deploy automation ni proveedor remoto antes del relevamiento.

## Registro mÃ­nimo de cada relevo

Todo RESULT debe dejar: proyecto, fase, fecha, rama, HEAD/base/origin, ADR,
decisiones vinculantes, paths exactos, migraciones, focales, suite, BD pre/post,
HTTP externo, commit/push, estado final y prÃ³ximo paso exacto.

## Gate irrefutable de continuidad documental

**Cada paso que cambie el estado real de SRCM debe actualizar, validar y
publicar los tres documentos maestros de recuperaciÃ³n:**

1. `docs/README.md`;
2. `docs/06_ROADMAP.md`;
3. `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`.

Este gate es obligatorio, irrefutable y excluyente. Si cualquiera de los tres
queda atrasado respecto del checkpoint funcional reciÃ©n cerrado, el avance no
estÃ¡ documentalmente cerrado y **no se abre la siguiente frontera funcional**.

Secuencia obligatoria:

`implementaciÃ³n â†’ validaciÃ³n GREEN â†’ checkpoint/push â†’ verificaciÃ³n remota â†’
sincronizaciÃ³n de los tres maestros â†’ publicaciÃ³n/verificaciÃ³n documental â†’
siguiente frontera`.

La North Star no se reescribe innecesariamente, pero su secciÃ³n dinÃ¡mica de
continuidad se actualiza en cada paso para conservar checkpoint, estado,
invariantes, bloqueos y prÃ³ximo paso exacto.
