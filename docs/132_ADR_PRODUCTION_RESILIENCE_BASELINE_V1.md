# ADR 132 — Production Resilience Baseline V1

Estado: **ACEPTADA**
Fecha: **2026-08-20**
Base: `4b142da1b3041d24942bd877d4c3f569e99cc41d`
Corte: `P11_PRODUCTION_RESILIENCE_BASELINE_V1`

## Contexto

El RECON de resiliencia de P11 confirmó que SRCM no tenía una implementación
operativa de backup ni retención. Existían menciones documentales de restore,
RPO/RTO y disaster recovery, pero no objetivos concretos ni verificación de una
copia restaurable. La superficie de migraciones, en cambio, ya posee `down()`
en las 122 migraciones relevadas y queda fuera de este primer corte.

SRCM usa SQLite como base actual. Copiar el archivo SQLite crudo mientras la
aplicación está viva no es un mecanismo aceptable de backup consistente.

## Decisión

### 1. Snapshot SQLite consistente

`srcm:backup-database` crea el snapshot usando `VACUUM INTO` desde una conexión
SQLite independiente. La BD fuente no se reemplaza, no se migra y no se
modifica deliberadamente. El archivo se publica sólo después de superar
`PRAGMA quick_check`, coincidencia de schema/migration metadata, checksum
SHA-256 y una verificación de restore aislada.

Cada backup publicado tiene tres artefactos:

- `srcm-db-<UTC>-<token>.sqlite`;
- sidecar `.sha256`;
- manifiesto `.json` con timestamp de verificación, schema hash, conteos y
  objetivos RPO/RTO.

No se expone ningún comando que restaure sobre la BD real.

### 2. Restore verification

`srcm:verify-database-backup [filename]` toma un backup del directorio
configurado, valida checksum/manifiesto, lo copia a un target temporal aislado
y ejecuta `PRAGMA integrity_check` sobre esa copia. El target temporal se
elimina al terminar. La BD productiva nunca es destino de este comando.

### 3. Retención y scheduler

El scheduler de Laravel ejecuta `srcm:backup-database` cada hora, sólo en
`production`, con `withoutOverlapping(55)` y también durante maintenance mode.
La retención por defecto conserva los **168 snapshots más recientes**, es decir
siete días si el scheduler funciona con frecuencia horaria.

El host de producción debe ejecutar `php artisan schedule:run` cada minuto. La
definición en código no sustituye esa obligación operativa del host.

### 4. Objetivos

Se fijan los primeros objetivos operativos explícitos:

- **RPO objetivo: <= 60 minutos** una vez activo el scheduler;
- **RTO objetivo: <= 240 minutos** para levantar una instancia equivalente a
  partir de un snapshot previamente verificado;
- freshness gate: un backup verificado de más de **90 minutos** hace fallar el
  readiness de producción.

Estos son objetivos de operación, no una afirmación de DR completo frente a
pérdida total del host.

### 5. Ubicación y cifrado

En producción el directorio de backup debe estar **fuera del árbol del repo**.
Se recomienda un volumen físico separado y cifrado por el sistema operativo.
El default bajo `storage/backups/database` existe sólo para desarrollo/testing.

Este corte no incorpora proveedor remoto ni KMS y no declara resuelta la
supervivencia ante pérdida del host. El backup off-host cifrado sigue siendo un
release gate posterior de resiliencia.

### 6. Readiness y señales

`GET /api/health/ready` agrega `verified_backup`. En producción devuelve fail si
no existe un manifiesto de backup verificado suficientemente fresco. Los
comandos emiten eventos estructurados `resilience.backup_*` y
`resilience.restore_verification_*` sin payload de negocio ni secretos.

## Fuera de alcance

- proveedor de backup remoto/off-host;
- KMS o cifrado aplicativo del snapshot;
- restore automático sobre la BD productiva;
- pipeline CI/CD y deploy automation;
- outbox;
- OpenTelemetry.

La siguiente frontera de resiliencia debe relevar **CI/CD y release gates** una
vez publicado este baseline de backup/restore.
