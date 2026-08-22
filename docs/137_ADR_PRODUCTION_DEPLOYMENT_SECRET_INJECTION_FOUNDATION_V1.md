# ADR 137 — Production Deployment & Secret Injection Foundation V1

Estado: **ACEPTADO**
Fecha: **2026-08-22**
Alcance: P11 — fundación versionada de deploy productivo, todavía fail-closed

## Contexto

El Decision Support de P11 aceptó como arquitectura base un único VPS Linux,
GitHub Actions como operador de despliegue, disparo manual, environment
`production` con reviewer, aprobación auditable y separación entre credenciales
de transporte y secretos runtime. El RECON V2 confirmó que SRCM usa PHP 8.3,
SQLite autoritativa, cola database, sesiones/cache database, filesystem local,
worker persistente, scheduler persistente y `GET /api/health/ready` como contrato
de readiness. También confirmó que no existía workflow de deploy, referencia a
un environment productivo ni unidades runtime versionadas.

## Decisión

Se versiona una fundación de deploy que permanece inutilizable para producción
mientras cualquiera de estos dos límites de autorización siga abierto:

1. `release.external_gates.production_environment_secrets_and_approvals`;
2. `release.production_release_enabled`.

Ambos siguen en `false` en este corte. El workflow es exclusivamente
`workflow_dispatch`, exige SHA exacto y confirmación explícita, usa concurrencia
única `srcm-production-deploy` y referencia el environment `production`. No hay
trigger por push, pull request ni schedule.

Antes de cualquier SSH, el workflow reproduce preflight, build y suite completa
y luego vuelve a leer los dos límites versionados. Con el estado actual aborta
antes de configurar transporte remoto. La mera presencia del workflow no es una
autorización de producción.

## Artefacto y layout

La release se construye en GitHub Actions. El host productivo no necesita Node
ni Composer para activar una release. El layout objetivo es:

- `/srv/srcm/releases/<git-sha>`: código inmutable;
- `/srv/srcm/current`: symlink atómico a la release activa;
- `/srv/srcm/shared/.env`: configuración y secretos runtime fuera de releases;
- `/srv/srcm/shared/database/database.sqlite`: SQLite autoritativa persistente;
- `/srv/srcm/shared/storage`: storage Laravel persistente;
- `/var/backups/srcm`: directorio de backup fuera de releases.

El artefacto rechaza `.env` y `database/database.sqlite`. Su checksum SHA-256
se genera con nombre de archivo relativo para que pueda verificarse luego en el
directorio temporal del host, sin conservar rutas absolutas del runner CI. Las credenciales
runtime de Mercado Pago, ARCA y resiliencia nunca se transportan desde GitHub.
GitHub sólo podrá contener credenciales del canal de deployment y coordenadas
del target. Las credenciales/certificados runtime permanecen en el host
protegido y se resuelven mediante los stores ya existentes.

## Runtime Linux

Se versionan plantillas para:

- Nginx + PHP-FPM 8.3;
- worker `queue:work database` supervisado por systemd;
- `schedule:run` mediante service+timer systemd cada minuto.

El hostname permanece deliberadamente como placeholder porque provider y DNS no
están decididos/provisionados en este corte.

## Migraciones y rollback

El deploy normal no puede crear la primera producción desde una BD vacía. Si
`/srv/srcm/current` no existe, aborta con `initial_production_cutover_must_be_separate`.
El primer cutover de datos será otro procedimiento explícito: restore de un
backup verificado hacia la SQLite productiva.

Para releases posteriores, el endpoint de readiness actual debe responder 200
antes de mantenimiento; ese contrato incluye backup verificado fresco. Luego se
entra en maintenance, se ejecuta `migrate --force`, se conmuta el symlink y se
reinician los procesos persistentes. Si el readiness posterior falla, puede
volver automáticamente el **código** al symlink anterior. La BD nunca recibe
`migrate:rollback` automático: cualquier recuperación de datos requiere
autoridad explícita de Owner o Tech Admin y evidencia de backup/restore.

## Preflight

`ReleasePreflightInspector` ahora considera estáticos y obligatorios:

- workflow productivo versionado;
- trigger manual-only;
- environment `production`;
- concurrencia de producción no cancelable;
- doble autorización versionada;
- ausencia de secretos runtime en el workflow;
- layout inmutable/shared-state;
- unidades web/queue/scheduler.

CI puede quedar GREEN con esta fundación mientras producción permanece
bloqueada por los gates externos.

## Fuera de alcance

Este corte no:

- crea ni contrata VPS;
- elige proveedor;
- define hostname/DNS/TLS real;
- crea o modifica GitHub Environment settings;
- carga reviewers, vars o secrets en GitHub;
- crea usuarios, sudoers, systemd o Nginx en un host real;
- hace SSH/SCP;
- ejecuta deploy;
- ejecuta migrate/rollback sobre la BD real;
- hace restore/cutover de la SQLite productiva;
- cambia `production_environment_secrets_and_approvals` a true;
- cambia `production_release_enabled` a true.
