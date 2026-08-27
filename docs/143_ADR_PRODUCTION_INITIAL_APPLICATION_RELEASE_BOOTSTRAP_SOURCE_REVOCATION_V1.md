# ADR 143 — Production Initial Application Release Bootstrap Source Revocation V1

Estado: **ACEPTADA**
Fecha: **2026-08-27**
Alcance: P11 — revocacion inmediata de los gates fuente temporales usados por el bootstrap inicial inactivo

## Evidencia previa obligatoria

BIPER1 cerro GREEN con SHA-256 `c1e2f4ed9de06c3fad948265810679713938db362818b4b1e2b318ed6ca4893a`. La evidencia fija el bootstrap unico GitHub Actions Run `33112168679`, intento 1, conclusion success, release `fad6f4ff0ddcffeca5230bf3bcbb604262e55dcc`, BD productiva SHA-256 `b07434ffcaaea6c1be8373b2187e725dceb70be40bfbdc3571af5df5ba85595e`, 122 migraciones, `current` ausente y servicios inactivos al cierre de la instalacion. Tambien confirma que no hubo rerun, segundo bootstrap ni deployment normal posterior por workflows versionados. BIPER1 declara honestamente que no existe una sonda SSH post-bootstrap versionada y por eso no afirma estado fuera de banda posterior.

## Recovery de BISR1

El primer intento BISR1 aborto antes de cualquier mutacion por un `PropertyNotFoundException` del runner bajo `Set-StrictMode`. BISRCR1 cerro GREEN con SHA-256 `a923d563202a2996aa38267004c796f15d37861d374e00997a06747a5a56925a` y demostro mediante replay schema-safe que la gobernanza, PR #7, CI49/CI50, Bootstrap Run #1, unicidad, ausencia de deployment normal posterior, Environment live, refs y estado local continuaban exactos. La recuperacion clasifica el defecto como acceso directo a propiedades JSON opcionales y exige accesos schema-safe; no atribuye el fallo a SRCM ni al bootstrap.

## Recovery V2 del runner de revocacion

BISRR1 aborto nuevamente antes de cualquier mutacion con evidencia SHA-256 `022d663709574583316009baf686234b8755693f575018890fbebbc894e9cafb`. La inspeccion del propio paquete identifico la causa exacta: `Get-LiveEnvironmentPolicySnapshot` devolvia `EnvironmentId` y `PolicyMatch`, mientras el stage consumidor exigia ademas `Exists` y `Key`; bajo `Set-StrictMode` la lectura de esas propiedades inexistentes produjo `PropertyNotFoundException`. Recovery V2 corrige el contrato de shape agregando `Exists` y una `Key` deterministica no secreta, y usa `Get-RequiredPropertySafe` en los consumidores. No cambia la decision de revocacion ni el alcance fuente.

## Decision

Este corte revoca exclusivamente la autorizacion temporal y deja el estado fuente en:

- `initial_application_release_bootstrap_enabled=false`;
- `external_gates.production_environment_secrets_and_approvals=false`;
- `production_release_enabled=false`.

La revocacion no elimina la release ya instalada ni la activa. No modifica workflows, servidor, `current`, servicios, migraciones ni base productiva. Su objetivo es cerrar nuevamente la superficie de autorizacion antes de cualquier trabajo posterior de activacion.

## Gobernanza posterior

La release normal permanece fail-closed. Antes de habilitar una activacion o release normal, la gobernanza del Environment debe endurecer `prevent_self_review=true` con un segundo operador elegible y evidencia live concordante. Este corte no realiza ese endurecimiento y no activa la aplicacion.
