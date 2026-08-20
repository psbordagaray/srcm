# ADR 135 — Production S3-compatible Remote Adapter Foundation V1

Estado: **ACEPTADA COMO FUNDACIÓN DE ADAPTER; GATE OFF-HOST AÚN ABIERTO**
Fecha: **2026-08-20**
Base: `0a433cb2ed22ea08c44f8c5c299749aacb5feb75`
Corte: `P11_S3_COMPATIBLE_REMOTE_ADAPTER_FOUNDATION_V1`

## Contexto

ADR 134 incorporó el envelope AES-256-GCM, readback autenticado y el contrato
`OffHostBackupTransport`, pero dejó deliberadamente pendiente el adapter remoto
real. El RECON posterior confirmó que SRCM admite `s3`/`sftp`, que existe una
forma genérica de disk S3 con endpoint compatible, y que no hay provider,
credenciales ni adapter Flysystem remoto instalados.

La documentación de Laravel 13 requiere `league/flysystem-aws-s3-v3` para el
driver S3 y permite usar ese mismo driver con servicios S3-compatible mediante
un endpoint específico. El RECON clasificó esa opción como el menor delta de
código para SRCM.

## Decisión

### 1. Se instala únicamente el adapter S3 oficial de Flysystem

`composer.json` incorpora `league/flysystem-aws-s3-v3` con línea 3.x. El
`composer.lock` fija la resolución completa, incluido `aws/aws-sdk-php` como
dependencia transitiva. No se incorpora SFTP en este corte.

La instalación de una librería no constituye contacto con un provider de
backup y no cuenta como evidencia del release gate.

### 2. El backup usa un disk dedicado

Se define `srcm_backup_s3`, separado del disk genérico `s3`. Sus variables son
exclusivas de resiliencia:

- `SRCM_BACKUP_S3_ACCESS_KEY_ID`
- `SRCM_BACKUP_S3_SECRET_ACCESS_KEY`
- `SRCM_BACKUP_S3_REGION`
- `SRCM_BACKUP_S3_BUCKET`
- `SRCM_BACKUP_S3_ENDPOINT`
- `SRCM_BACKUP_S3_USE_PATH_STYLE_ENDPOINT`

Los valores reales permanecen fuera del repositorio. El disk fuerza
`visibility=private` y `throw=true`; no cae hacia credenciales AWS genéricas.

### 3. Selección técnica no equivale a selección de proveedor

`SRCM_BACKUP_REMOTE_DISK` usa `srcm_backup_s3` como default técnico, pero
`SRCM_BACKUP_OFF_HOST_ENABLED` continúa `false`. Este corte elige el protocolo
y adapter, no un vendor, bucket, endpoint ni cuenta concreta.

La configuración vacía debe fallar antes de un smoke operacional autorizado.

### 4. Sin I/O externo ni scheduler

Este corte no ejecuta `srcm:export-backup-off-host`, no crea objetos remotos,
no realiza `PUT`, `GET`, `HEAD`, `LIST` ni `DELETE`, no consulta KMS y no agenda
la exportación. Las pruebas sólo inspeccionan configuración, lockfile y
contratos locales.

## Release gate

Después de este corte siguen siendo obligatorias las evidencias posteriores:

1. seleccionar un provider S3-compatible concreto y configurar credenciales,
   bucket/endpoint y referencia de clave fuera de VCS;
2. ejecutar un smoke controlado que cree **solamente ciphertext**, relea el
   objeto, autentique plaintext/ciphertext/key-id y elimine el objeto de smoke;
3. comprobar política de retención y, cuando el provider lo permita,
   versionado/immutabilidad/lifecycle;
4. sólo entonces evaluar el gate `off_host_encrypted_backup`;
5. el restore drill operacional sigue después y nunca toca la BD viva.

Por tanto:

- `release.external_gates.off_host_encrypted_backup` permanece `false`;
- `operational_restore_drill` permanece bloqueado;
- `production_release_enabled` permanece `false`;
- no se autoriza producción.
