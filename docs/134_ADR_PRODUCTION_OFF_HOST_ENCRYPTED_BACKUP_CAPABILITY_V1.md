# ADR 134 — Production Off-host Encrypted Backup Capability V1

Estado: **ACEPTADA COMO CAPACIDAD; GATE EXTERNO AÚN ABIERTO**
Fecha: **2026-08-20**
Base: `8c3c827b9747efeee506099633abeb285e53ee84`
Corte: `P11_OFF_HOST_ENCRYPTED_BACKUP_CAPABILITY_V1`

## Contexto

El baseline de resiliencia de P11 ya crea snapshots SQLite consistentes mediante
`VACUUM INTO`, verifica quick/integrity checks, conserva SHA-256 + manifiesto,
prueba restore sobre copia aislada, aplica retención y fija RPO/RTO 60/240.
El Boundary RECON V2 confirmó que esa base es reutilizable y que el faltante
real es la capa de cifrado aplicativo + transporte off-host.

La existencia del disk genérico `s3` de Laravel no es evidencia de backup ni
autoriza producción. El gate `off_host_encrypted_backup` permanece `false`.

## Decisión

### 1. No se reimplementa el snapshot local

`SqliteBackupManager` continúa siendo la autoridad para crear y verificar el
snapshot local. La nueva exportación sólo acepta un backup que haya superado
`verifyRestore()`; no copia la BD viva ni altera el mecanismo `VACUUM INTO`.

### 2. Envelope cifrado autenticado

`AuthenticatedBackupEnvelope` cifra por chunks con **AES-256-GCM** y una clave
de 32 bytes. Cada chunk usa nonce aleatorio, tag GCM y AAD que vincula versión,
metadata e índice del chunk. El envelope incluye sólo metadata operacional
mínima: versión, cipher, key id, filename técnico y SHA-256 del plaintext.

El readback remoto se descifra y autentica **en memoria**. No se escribe una
segunda copia plaintext durante la verificación del transporte.

### 3. Clave por referencia, nunca en código/config versionada

`SRCM_BACKUP_ENCRYPTION_KEY_REFERENCE` acepta únicamente `env:VARIABLE`. La
variable referenciada debe contener base64 de exactamente 32 bytes. El key id
es un identificador no secreto para rotación/auditoría. El valor de la clave
nunca se registra ni forma parte del envelope.

Este V1 no integra un KMS concreto; un provider/KMS real puede sustituir el
resolver manteniendo el contrato de dominio.

### 4. Transporte off-host explícito y fail-closed

`OffHostBackupTransport` separa dominio de proveedor. La implementación Laravel
requiere un disk configurado cuyo driver esté en la allowlist remota (`s3` o
`sftp`). Un disk `local` se rechaza antes de cualquier I/O de provider.

La exportación sube únicamente `*.sqlite.srcmenc`, relee el objeto remoto y
requiere coincidencia del SHA-256 ciphertext, SHA-256 plaintext autenticado,
filename y key id. Si el readback falla o fue alterado, intenta eliminar el
objeto no verificado y falla cerrado.

### 5. Sin provider real ni scheduler en este corte

El comando `srcm:export-backup-off-host [filename]` queda disponible, pero **no
se agenda** todavía. `SRCM_BACKUP_OFF_HOST_ENABLED` defaulta `false`. Los tests
usan un transporte sintético en memoria; no realizan llamadas S3/SFTP/KMS.

La falta actual del adapter S3/SFTP concreto en dependencias no se oculta: el
siguiente corte operacional debe elegir/configurar el provider real, instalar
su adapter si corresponde y ejecutar un smoke controlado.

## Release gate

Este commit crea **capacidad**, no evidencia operacional. Por lo tanto:

- `release.external_gates.off_host_encrypted_backup` permanece `false`;
- `operational_restore_drill` permanece bloqueado;
- `production_release_enabled` permanece `false`;
- ninguna credencial o clave real se incorpora al repo.

El gate off-host sólo podrá cerrarse después de un smoke real que demuestre un
objeto cifrado fuera del host, readback autenticado y política operativa de
retención/credenciales. El restore drill vendrá después y usará un artefacto
real de esa capa off-host.
