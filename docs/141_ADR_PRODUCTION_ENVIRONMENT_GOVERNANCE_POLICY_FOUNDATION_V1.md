# ADR 141 — Production Environment Governance Policy Foundation V1

Estado: **ACEPTADA**
Fecha: **2026-08-27**
Alcance: P11 — contrato versionado del GitHub Environment `production` previo a cualquier autorización del bootstrap inicial

## Contexto

El RECON de autorización del bootstrap confirmó que SRCM/Straleon continúa completamente fail-closed y que el GitHub Environment `production` ya existe con una regla de reviewer obligatorio, sin bypass administrativo, restringido a ramas protegidas y con los nombres de secretos y variables de transporte requeridos presentes. Los valores secretos no fueron leídos ni registrados.

Ese mismo RECON detectó que la fuente sólo versionaba el nombre del Environment y el booleano `production_environment_secrets_and_approvals`; todavía no existía un contrato explícito para reviewers, self-review, bypass, política de ramas ni recursos esperados. Por tanto, un futuro cambio del booleano no debía interpretarse como evidencia suficiente por sí mismo.

El Environment live actualmente permite self-review. La evidencia disponible prueba un reviewer obligatorio, pero no prueba la existencia de un segundo operador independiente capaz de aprobar un dispatch iniciado por el operador principal. Endurecer `prevent_self_review=true` sin esa evidencia podría dejar bloqueada toda operación de producción por ausencia de un aprobador distinto.

## Decisión

Se versiona `release.deployment.environment_governance` con foundation version 1 y estas invariantes:

- Environment exacto `production`;
- al menos un reviewer obligatorio;
- `can_admins_bypass=false`;
- deployments limitados a ramas protegidas;
- nombres exactos de secretos de transporte: `TS_OAUTH_CLIENT_ID`, `TS_AUDIENCE`, `SRCM_DEPLOY_SSH_PRIVATE_KEY` y `SRCM_DEPLOY_KNOWN_HOSTS`;
- variables exactas de transporte: `SRCM_DEPLOY_HOST=straleon-prod-01`, `SRCM_DEPLOY_USER=straleon-deploy` y `SRCM_DEPLOY_PORT=22`;
- los valores de secretos nunca deben ser leídos, versionados ni registrados como evidencia;
- cualquier gate fuente que represente al Environment sólo puede cerrarse después de demostrar que el estado live coincide con esta política.

Para el bootstrap inicial, que instala una release inactiva y no crea `current`, no migra y no inicia servicios, `prevent_self_review=false` queda admitido de manera temporal y explícita. Esto **no representa una segunda persona ni una aprobación independiente**: representa una segunda acción deliberada del operador dentro de una cadena que además exige protected `main`, SHA exacto, source gates separados y confirmación manual.

La excepción termina antes de una activación normal de producción. La política fija `normal_release_requires_prevent_self_review=true`, y `ReleasePreflightInspector` queda fail-closed: si en el futuro `production_release_enabled=true`, la política fuente debe haber cambiado previamente a `prevent_self_review=true`. Ese hardening deberá además demostrarse en el Environment live antes de cerrar el gate externo correspondiente.

## Frontera de este corte

Este corte **no modifica GitHub Environment settings** y no concede ninguna autorización. Permanecen exactamente:

- `production_release_enabled=false`;
- `initial_application_release_bootstrap_enabled=false`;
- `external_gates.production_environment_secrets_and_approvals=false`.

Tampoco ejecuta workflows, SSH, bootstrap, deploy, migraciones, cambios de `current`, inicio de servicios ni mutaciones de la BD productiva.

## Consecuencias y siguientes gates

El significado de “Environment de producción evidenciado” deja de ser una convención y pasa a ser un contrato versionado y probado. Después de CI, revisión y merge protegido de esta fundación, un RECON live deberá verificar nuevamente que el Environment coincide con el contrato antes de considerar cualquier flip de los source gates del bootstrap.

La autorización del bootstrap, cuando corresponda, seguirá siendo un corte separado y revocable: podrá habilitar únicamente `initial_application_release_bootstrap_enabled` y `production_environment_secrets_and_approvals`, manteniendo `production_release_enabled=false`. Tras un bootstrap exitoso, esos permisos temporales deberán revocarse mediante otro corte protegido antes de avanzar a activación.
