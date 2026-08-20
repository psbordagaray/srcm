# ADR 133 — Production CI/CD Release Gates V1

Estado: **ACEPTADO**
Fecha: **2026-08-20**
Alcance: P11 — primer corte funcional de release engineering

## Contexto

El RECON de P11 confirmó que SRCM tenía `composer test`, `npm run build`,
`composer.lock` y `package-lock.json`, pero no un workflow CI versionado ni un
preflight de release ejecutable. También confirmó 122 migraciones versionadas,
las 122 con `down()`, 93 aplicadas en la BD canónica y 29 pendientes. La
infraestructura de backup/restore local existe, pero off-host cifrado, restore
drill operativo y secretos/aprobaciones de producción siguen siendo gates
externos abiertos.

## Decisión

Se incorpora un workflow GitHub Actions con permisos mínimos `contents: read`,
checkout fijado por SHA, instalación desde lockfiles, `git diff --check`,
preflight, build de assets y suite completa. En un runner limpio el build debe
preceder a cualquier prueba HTTP que renderice layouts con `@vite`, porque
`public/build` está deliberadamente fuera de Git y el manifest sólo existe
después de `npm run build`. El workflow verifica explícitamente
`public/build/manifest.json` antes de ejecutar `composer test`.

Se incorpora `srcm:release-preflight`, respaldado por
`ReleasePreflightInspector`, que:

- exige los dos lockfiles y el workflow versionado;
- verifica los gates ejecutables de CI;
- inventaría migraciones sin ejecutar `migrate` ni `rollback`;
- exige `down()` no vacío en todas las migraciones versionadas;
- informa aplicadas y pendientes cuando la tabla `migrations` es legible;
- valida que el contrato post-deploy siga siendo `GET /api/health/ready`;
- mantiene producción fail-closed mientras falten gates externos.

`config/release.php` deja deliberadamente en `false` el switch de release y los
tres gates externos. Este V1 no ofrece un camino de configuración para
convertirlos en `true`: desbloquearlos exige otro cambio versionado y revisado.

## Consecuencias

CI puede demostrar reproducibilidad de dependencias, tests, build y preflight
sin desplegar ni tocar la BD de producción. La existencia de migraciones
pendientes no es por sí sola un fallo: el precheck exige que sean conocidas y
reversibles; la ejecución real queda fuera de este corte.

El workflow no contiene deploy, `artisan migrate --force`, rollback, backup
real, restore real ni tráfico ARCA. Un CI verde **no autoriza producción**.

## Gates externos que permanecen abiertos

1. backup off-host cifrado con proveedor/KMS real;
2. restore drill operativo con evidencia;
3. secretos, environment protection y aprobación de producción.

## Fuera de alcance

- deploy real;
- migrate/rollback real;
- proveedor remoto de backup;
- KMS externo;
- backup/restore real;
- ARCA real;
- outbox;
- OpenTelemetry.
