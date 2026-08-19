# ADR 121 — ARCA WSAA Credential Material Boundary V1

Estado: aceptado.

## Contexto

El RECON `ARCA_WSAA_CREDENTIAL_MATERIAL_READINESS_RECON_V1` confirmó que:

- `WsaaAccessTicketProvider` sigue siendo un contrato puro;
- el `FiscalAuthorizationCredentialStore` publicado sólo responde
  `configuredFor(organizationId)` y no expresa ambiente, servicio ni CUIT;
- `AppServiceProvider` no enlaza ese store ni un proveedor WSAA concreto;
- la configuración global sólo tenía una `certificate_reference`;
- no existían referencias de clave privada ni passphrase;
- `.env.example` no exponía superficie ARCA;
- PHP 8.3.30 tiene OpenSSL/CMS disponible;
- la extensión SOAP no está habilitada;
- no hay credenciales ARCA configuradas en el ambiente local.

No es seguro convertir el store fiscal grueso existente en una implementación global:
podría confundir organización, ambiente, servicio o CUIT. Este V1 no lo enlaza.

## Evidencia oficial estable

ARCA documenta para WSAA que:

- la autenticación se basa en certificados digitales X.509;
- el certificado debe asociarse al Web Service de Negocio autorizado;
- la solicitud de Ticket de Acceso se envía como una estructura CMS/PKCS#7 que
  contiene el TRA, su firma digital separada y el certificado X.509;
- el cliente extrae Token y Sign del TA y los presenta luego al WSN.

Este ADR no convierte esas reglas externas en evidencia de que una credencial real
de SRCM exista, sea válida o esté autorizada.

## Decisión

Se separan explícitamente tres niveles.

### 1. Referencia no secreta

`WsaaCredentialMaterialReference` liga:

- organización;
- ambiente;
- servicio;
- CUIT emisor;
- referencia de certificado;
- referencia de clave privada;
- referencia opcional de passphrase.

Las referencias son privadas, se exponen sólo por accessors explícitos, no se
serializan y aparecen redactadas en debug. No pueden contener PEM inline.

### 2. Store tenant-scoped de referencias

`WsaaCredentialMaterialReferenceStore` expone:

- presencia de al menos una referencia válida por ambiente;
- resolución exacta para un `WsaaAccessTicketRequest`.

`EnvironmentWsaaCredentialMaterialReferenceStore` usa únicamente
`SRCM_ARCA_WSAA_CREDENTIAL_REFERENCES_JSON`.

El mapa está indexado por `organization_id`. Cada entrada debe contener exactamente:

- `service`;
- `issuer_cuit`;
- `certificate_reference`;
- `private_key_reference`;
- `private_key_passphrase_reference`.

El store:

- acepta sólo homologación;
- exige coincidencia exacta de organización, ambiente, servicio y CUIT;
- valida estructura de forma fail-closed;
- no imprime valores;
- no abre archivos;
- no dereferencia secretos;
- no firma;
- no hace HTTP.

La vieja `ARCA_HOMOLOGATION_CERTIFICATE_REFERENCE` global se elimina de
`config/services.php`: una única referencia global no expresa correctamente la
frontera multi-tenant.

`ArcaHomologationReadiness` conserva la configuración global de ambiente
(endpoints + service) pero exige además que exista al menos una entrada
tenant-scoped válida para homologación.

### 3. Material efímero

`WsaaCredentialMaterial` representa certificado PEM, clave privada PEM y
passphrase opcional ya resueltos.

Los tres valores sensibles son privados, no serializables y redactados en debug.

`WsaaCredentialMaterialProvider` define la futura resolución:

`WsaaAccessTicketRequest -> WsaaCredentialMaterial`

Este V1 NO implementa ese provider concreto.

## Decisión explícita sobre FiscalAuthorizationCredentialStore

`FiscalAuthorizationCredentialStore` permanece sin cambios y sin binding.

No se lo adapta silenciosamente al nuevo store porque su firma actual no contiene:

- ambiente;
- servicio;
- CUIT.

Antes de activarlo en una ruta real deberá existir una convergencia explícita que
evite aprobar una configuración de homologación para una operación de otro scope.

## Configuración versionada

`.env.example` sólo publica nombres y forma del mapa. No contiene:

- certificados;
- claves privadas;
- passphrases;
- rutas reales;
- referencias reales.

## Producción

Producción permanece bloqueada.

El store de referencias rechaza producción y no existe una variable equivalente
de producción en este corte.

## Fuera de alcance

- lectura de certificado;
- lectura de clave privada;
- resolución de referencias;
- validación criptográfica X.509;
- comparación del CUIT contra subject/serialNumber del certificado;
- TRA XML;
- firma CMS/PKCS#7;
- `openssl_cms_sign` / `openssl_pkcs7_sign`;
- `SoapClient`;
- LoginCms;
- Token/Sign reales;
- cache de Ticket de Acceso;
- WSFE HTTP;
- CAE real;
- producción.

## Validación

El corte debe demostrar:

- scope tenant/ambiente/servicio/CUIT fail-closed;
- referencias y material sensibles redactados/no serializables;
- ausencia de PEM/secrets en `.env.example`;
- binding únicamente del reference store;
- `WsaaCredentialMaterialProvider` todavía abstracto y sin binding;
- `FiscalAuthorizationCredentialStore` todavía sin binding;
- ausencia de filesystem/OpenSSL/SOAP/HTTP/CMS/LoginCms en el nuevo adapter;
- suite focal, regresión fiscal y suite completa;
- BD real sin cambio lógico.

## Frontera siguiente

Sólo después del checkpoint funcional, verificación remota y sincronización
publicada de los tres documentos maestros:

`ARCA_WSAA_TRA_CMS_SIGNING_RECON_V1`

Ese RECON deberá fijar con evidencia oficial la forma exacta del TRA, los parámetros
de firma CMS compatibles con el runtime local y el contrato de salida, sin ejecutar
LoginCms ni usar credenciales reales.
