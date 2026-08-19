# ADR 120 — WSFE Provider Result Convergence V1

Estado: aceptada para el corte posterior a WSFE Provider Response Normalization
V1.

## Contexto

SRCM ya normaliza la respuesta preservada de `FECAESolicitar` a:

- `Authorized`;
- `Rejected`;
- `Unknown`.

La normalización conserva CAE, `CAEFchVto`, observaciones, Events, Errors y el
resultado provider original, pero el contrato provider-neutral
`FiscalAuthorizationTransportResult` y los hechos persistidos de autorización
todavía sólo transportan `outcome + resultCode`.

Esa diferencia debe cerrarse antes de construir un transport SOAP real.

## Decisión

Se publica una convergencia explícita:

`WsfeFecaeNormalizedResponseData`
→ `WsfeFecaeProviderResultConvergence`
→ `FiscalAuthorizationTransportResult`
→ `FiscalAuthorizationFactData`
→ `FiscalAuthorizationResponse`

El transport result provider-neutral agrega campos opcionales:

- `authorizationCode`;
- `authorizationCodeExpiresOn`;
- `providerEvidence`.

Los argumentos anteriores (`outcome`, `resultCode`) conservan compatibilidad.

## Semántica provider-neutral

`authorizationCode` significa código de autorización emitido por el proveedor.
Para WSFE su fuente es CAE, pero la capa neutral no se llama `cae`.

`authorizationCodeExpiresOn` usa fecha ISO `YYYY-MM-DD`. Para WSFE se deriva de
`CAEFchVto` únicamente cuando la fecha es válida.

`providerEvidence` es evidencia opaca y preservable. La capa neutral no
interpreta sus códigos internos.

## Convergencia WSFE

`WsfeFecaeProviderResultConvergence`:

- conserva el `outcome` fail-closed ya normalizado;
- usa el resultado de cabecera como `resultCode` cuando existe, y sólo cae al
  resultado de detalle si falta la cabecera;
- conserva CAE en `authorizationCode`, incluso en un estado `Unknown`, porque
  conservar evidencia no equivale a aceptar autorización;
- normaliza `CAEFchVto` válida a `YYYY-MM-DD`;
- conserva dentro de `providerEvidence`:
  - provider `arca_wsfe_v1`;
  - resultado de cabecera;
  - resultado de detalle;
  - observaciones;
  - Events;
  - Errors;
  - resultado provider íntegro.

Un vencimiento inválido queda `null` en el campo neutral, pero permanece sin
alteración dentro del resultado provider preservado.

## Hechos persistidos

`FiscalAuthorizationFactData::fromTransportResult()` transfiere la evidencia
neutral sin reinterpretarla.

`fiscal_authorization_responses` agrega de forma nullable:

- `authorization_code`;
- `authorization_code_expires_on`;
- `provider_evidence`.

Los hechos históricos permanecen válidos con `NULL`.

El fingerprint de idempotencia incorpora código, vencimiento y SHA-256 de la
evidencia provider canonicalizada. Cambiar cualquier evidencia con la misma
clave de idempotencia falla cerrado.

La auditoría NO duplica el payload provider completo; registra sólo el hash de
esa evidencia además de los campos neutrales.

## Inmutabilidad

La respuesta fiscal continúa siendo append-only tanto a nivel de modelo como de
SQLite trigger.

La migración es aditiva. Este runner la aplica deliberadamente a la BD real
únicamente después de que focales, regresión y suite completa sean GREEN, y
genera una copia de seguridad local previa dentro del directorio aislado de
ejecución.

## Lo que este corte NO hace

- no crea `SoapClient`;
- no genera XML;
- no carga WSDL;
- no abre HTTP ARCA;
- no interpreta `Obs.Code`, `Evt.Code` ni `Err.Code`;
- no inventa CAE;
- no habilita producción;
- no valida integración ARCA real.

## Continuidad documental

El mismo runner, después de publicar y verificar el commit funcional, actualiza
los tres documentos maestros y crea un segundo commit documental. Los dos
commits permanecen separados aunque se ejecuten en una sola operación
PowerShell.

## Siguiente frontera

`ARCA_WSAA_CREDENTIAL_MATERIAL_READINESS_RECON_V1`

Será estrictamente read-only respecto de secretos y red. Debe relevar la
superficie de almacenamiento/configuración de certificado, clave privada,
OpenSSL/CMS y el provider WSAA concreto antes de materializar credenciales
reales o ejecutar `LoginCms`.
