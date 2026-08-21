# ADR 136 — Resilience External Gates Evidence Closure V1

Estado: **ACEPTADA — DOS GATES DE RESILIENCIA CERRADOS; PRODUCCIÓN AÚN BLOQUEADA**
Fecha: **2026-08-21**
Base: `50c7198e93d56aa93ae2c00f580f3dd737f3ba6e`
Corte: `P11_RESILIENCE_EXTERNAL_GATES_CLOSURE_V1`

## Contexto

P11 incorporó primero la capacidad de backup SQLite verificado, envelope
autenticado AES-256-GCM, transporte remoto S3-compatible y adapter dedicado
para backups. Esas fundaciones se mantuvieron deliberadamente fail-closed
hasta obtener evidencia operacional real.

La evidencia posterior cerró la frontera con Cloudflare R2 sin convertir la
preparación de producción en una habilitación implícita:

- se corrigió y verificó la configuración S3-compatible del bucket dedicado;
- el transporte productivo real pasó PUT/readback/DELETE sintético;
- la clave AES dedicada fue recuperada desde un almacenamiento independiente;
- se creó un backup SQLite real, se verificó por restore aislado y se
  externalizó fuera del repositorio;
- el backup real fue cifrado y retenido en R2 con readback autenticado;
- el objeto remoto real fue descargado, autenticado, descifrado a un SQLite
  temporal aislado y validado con `quick_check` e `integrity_check`;
- el restore operativo confirmó schema, 112 tablas y 93 migraciones;
- el drill quedó dentro del RTO configurado de 240 minutos;
- el SQLite temporal del drill fue eliminado y su ausencia quedó probada.

La evidencia autocontenida final corresponde a
`SRCM_V1_P11_OFF_HOST_BACKUP_RELEASE_GATE_EVIDENCE_RECON_V2_RESULT.txt`,
SHA-256 `f4ea88cfbb12fdbec9982ea6ed6df56ae77676bddb6728dcff6eb4b8f972019a`.

Identidad no secreta del artefacto probado:

- backup local: `srcm-db-20260821T214931Z-c9d7fa18.sqlite`;
- plaintext SHA-256:
  `f9b7f18d513b1a95f5405bb59b942024cd8a91aaf16ab8a66a7b43ecfbf6af3a`;
- remote key:
  `srcm/backups/database/srcm-db-20260821T214931Z-c9d7fa18.sqlite.srcmenc`;
- ciphertext SHA-256:
  `3c413f869538b457055eb11edd36992cb5aba05ba6378c607ca5dd297ac1553b`.

Ninguna credencial ni valor de clave forma parte de este ADR.

## Decisión

### 1. Se cierra el gate `off_host_encrypted_backup`

`release.external_gates.off_host_encrypted_backup` pasa a `true`.

El valor refleja evidencia real de un backup cifrado retenido fuera del host,
con identidad criptográfica conocida y readback autenticado. No implica que
`resilience.off_host.enabled` quede activado por defecto: ese runtime sentinel
continúa `false` hasta una autorización operacional posterior.

### 2. Se cierra el gate `operational_restore_drill`

`release.external_gates.operational_restore_drill` pasa a `true`.

El gate queda respaldado por un restore real desde R2 usando una clave
recuperada desde almacenamiento independiente, sin reemplazar la BD viva, con
validación SQLite completa, RTO cumplido y cleanup temporal probado.

### 3. Producción permanece bloqueada por dos sentinels independientes

Este corte **no** cambia:

- `release.production_release_enabled`, que permanece `false`;
- `release.external_gates.production_environment_secrets_and_approvals`, que
  permanece `false`.

El RECON V2 simuló semántica `production` con solamente los dos gates de
resiliencia en `true` y confirmó:

- `external_green=false`;
- `production_authorized=false`.

Por lo tanto, cerrar estos dos gates no autoriza producción.

### 4. No se agenda ni ejecuta export automático

Este corte no modifica `routes/console.php`, no agrega scheduler y no cambia
el sentinel persistido `SRCM_BACKUP_OFF_HOST_ENABLED=false`.

El objeto real ya retenido en R2 no se modifica durante este corte funcional.

## Consecuencias

La frontera de resiliencia de P11 queda cerrada como evidencia de release:

- `off_host_encrypted_backup=true`;
- `operational_restore_drill=true`;
- `production_environment_secrets_and_approvals=false`;
- `production_release_enabled=false`.

La siguiente frontera obligatoria es un RECON separado de secretos,
configuración y aprobaciones del entorno de producción. Sólo después de cerrar
esa evidencia podrá evaluarse, en otro corte explícito, el switch global de
producción.

Los tres documentos maestros se actualizan únicamente en un commit documental
posterior, después de que este commit funcional pase la suite local, se
publique y obtenga GitHub Actions GREEN.
