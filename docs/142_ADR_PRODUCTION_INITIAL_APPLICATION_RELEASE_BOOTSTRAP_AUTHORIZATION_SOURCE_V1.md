# ADR 142 — Production Initial Application Release Bootstrap Authorization Source V1

Estado: **ACEPTADA**
Fecha: **2026-08-27**
Alcance: P11 — apertura temporal y revocable de los gates fuente del bootstrap inicial inactivo

## Evidencia previa obligatoria

PEGLM1 cerro GREEN con SHA-256 `0ebad977329610228180fd2787ee9beff12aae8b849be4210d128dcfc9644672` y demostro que el GitHub Environment `production` live coincide con la policy foundation v1 sin leer ni registrar valores secretos.

## Decision

Este corte cambia exclusivamente el estado fuente a:

- `initial_application_release_bootstrap_enabled=true`;
- `external_gates.production_environment_secrets_and_approvals=true`;
- `production_release_enabled=false`.

Esta combinacion autoriza solamente el bootstrap inicial **inactivo** cuando el commit llegue posteriormente a protected `main` mediante CI, PR y merge gobernado. No autoriza una release normal ni una activacion publica.

## Frontera operativa

El bootstrap no se ejecuta en este corte. No hay workflow dispatch, SSH, mutacion del servidor, despliegue, migracion, creacion de `current`, inicio de servicios ni mutacion de la base productiva. El workflow de bootstrap permanece byte-identico y exige protected `main`, SHA exacto, Environment `production` y `normalRelease === false`.

## Revocacion obligatoria

Tras un bootstrap exitoso, estos permisos temporales requieren **revocacion** en un corte protegido separado antes de cualquier activacion. La release normal sigue bloqueada hasta endurecer `prevent_self_review=true` con evidencia de un segundo operador elegible.
