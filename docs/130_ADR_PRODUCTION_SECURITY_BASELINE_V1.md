# ADR 130 — Production Security Baseline V1

Estado: **ACEPTADA**
Fecha: **2026-08-19**
Base: `9d7c9fceebdaafa431f84aeb8c042f3509dbc679`
Corte: `P11_PRODUCTION_SECURITY_BASELINE_V1`

## Contexto

P10 quedó cerrado localmente y el Post-P10 RECON abrió P11. El RECON focal de
seguridad clasificó auth y throttling como implementados; cookie de sesión,
step-up y secret/config hygiene como parciales; y headers globales como ausentes.
MFA/passkeys, OpenTelemetry, backup automation y outbox están expresamente fuera
de este primer corte.

## Decisión

### 1. Headers globales conservadores

Toda respuesta HTTP recibe, salvo que una capa más específica ya los haya
fijado, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` y
`Referrer-Policy: strict-origin-when-cross-origin`. En producción HTTPS se agrega
HSTS por un año con `includeSubDomains`.

CSP y Permissions-Policy no se improvisan en este corte: requieren inventario de
scripts, assets, cámara/scanner y proveedores antes de imponer una política que
pueda romper POS o futuras superficies hardware.

### 2. Producción fail-closed por configuración

Una request en `production` se bloquea antes del negocio si cualquiera de estas
condiciones falla:

- `APP_DEBUG=false`;
- `APP_KEY` no vacío;
- `APP_URL` con esquema HTTPS;
- cookie de sesión `Secure` y `HttpOnly`;
- sesión cifrada;
- serialización de sesión JSON;
- `SameSite` sólo `lax` o `strict`;
- ventana de confirmación de contraseña entre 60 y 1800 segundos.

El error enumera únicamente claves de configuración, nunca valores secretos.
Los defaults de desarrollo siguen siendo utilizables; producción debe declarar
explícitamente sus overrides.

### 3. Step-up de producción

Se reutiliza la confirmación de contraseña ya existente y se aplica un
middleware específico de producción a operaciones de alto impacto:

- organización / configuración fiscal;
- membresías y cambios de rol/estado;
- cuentas financieras;
- aprobación de retiros de seguridad;
- aprobación y ejecución de pagos a proveedores;
- reembolsos en efectivo;
- instrucción y despacho de reembolsos externos.

Una mutación bloqueada por confirmación vencida **no se reintenta ni se replayea**.
El actor confirma su contraseña y debe volver a ejecutar conscientemente la
operación. Para JSON se responde `423` con `code=step_up_required`.

No se implementa todavía PIN de supervisor ni MFA/passkeys.

### 4. Secret/config hygiene

Los cinco accesos directos actuales a `env()` dentro de `app/` se conservan
porque pertenecen a fronteras explícitas de material/referencias secretas. No se
mueven a `config()` en este corte para evitar materializarlos accidentalmente en
config cache. La prueba focal congela ese allowlist y falla ante nuevos accesos
directos no revisados.

La misma prueba rechaza material criptográfico o `.env` sensible versionado y
verifica que `.env.example` documente el baseline sin valores reales.

## Fuera de alcance

- MFA / passkeys;
- supervisor PIN dedicado;
- CSP / Permissions-Policy;
- OpenTelemetry;
- backup automation / restore drills;
- outbox genérico;
- homologación ARCA real / WSASS.

## Criterio de aceptación

- middleware global registrado;
- producción insegura falla cerrada;
- headers aparecen globalmente;
- rutas críticas cargan el middleware de step-up;
- POST crítico vencido no se replayea;
- allowlist de `env()` y ausencia de key material versionado quedan testeados;
- focal, regresión Auth/Security y suite completa GREEN;
- BD real lógica intacta;
- commit/push exactos y repo limpio.
