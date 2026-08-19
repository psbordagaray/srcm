# ADR 122 — ARCA WSAA TRA/CMS Signing Boundary V1

Estado: aceptado.

## Contexto

El RECON `ARCA_WSAA_TRA_CMS_SIGNING_RECON_V1` cerró GREEN sobre el checkpoint
`2f618bd22367ebc72af7a32b05fef5c088f6028c`.

Confirmó:

- `loginTicketRequest` versión 1.0 como TRA;
- `uniqueId`, `generationTime`, `expirationTime` y `service` como contenido
  requerido del corte;
- `source` y `destination` opcionales;
- `issuerCuit` como metadata de scope SRCM, no como campo TRA;
- `uniqueId` con dominio `unsignedInt` de 32 bits;
- tolerancia máxima documentada de 24 horas hacia atrás para `generationTime`
  y 24 horas hacia adelante para `expirationTime`;
- CMS `SignedData` con contenido attached/embedded y certificado incluido;
- entrada de `LoginCms` como Base64 del CMS, sin wrappers PEM/MIME;
- OpenSSL 3.2.3 local puede producir attached CMS con SHA-256 por defecto y
  SHA-1 explícito;
- `openssl_cms_sign()` local produce attached CMS con SHA-256 en el runtime
  actual;
- la extensión PHP SOAP continúa deshabilitada;
- no hubo credenciales reales, `LoginCms` ni HTTP ARCA.

El mismo RECON detectó una brecha concreta: las clases de scope de SRCM
permitían nombres de servicio más amplios que el XSD oficial.

## Evidencia oficial y ambigüedad de digest

La especificación técnica WSAA 1.2.2 declara SHA1+RSA para la firma TRA.

El manual de desarrollador vigente muestra mecanismos CMS attached para
OpenSSL, PowerShell y PHP sin fijar de forma explícita el digest en esos
ejemplos.

OpenSSL moderno usa normalmente SHA-256 como digest por defecto para RSA cuando
no se configura `-md`.

Por lo tanto:

**capacidad local de generar un digest ≠ aceptación real de ARCA**.

Este V1 no selecciona un digest por defecto ni declara que SHA-1 o SHA-256 esté
validado en homologación.

## Decisión 1 — Identidad de servicio WSAA

`WsaaServiceName` centraliza el contrato del XSD:

- mínimo 3 caracteres;
- máximo 32;
- primer carácter letra ASCII;
- restantes letras ASCII, dígitos o `_`.

El mismo contrato se aplica a:

- `WsaaAccessTicketRequest`;
- `WsaaCredentialMaterialReference`;
- `WsaaCredentialMaterial`;
- readiness de homologación.

Se corrige además el ejemplo de `.env.example` para no documentar una identidad
que el propio dominio rechaza.

## Decisión 2 — TRA

`WsaaTra` representa el `loginTicketRequest` local.

Incluye sólo:

- `uniqueId`;
- `generationTime`;
- `expirationTime`;
- `service`.

No agrega `source`, `destination` ni CUIT.

El XML se genera con versión 1.0, UTF-8 y tiempos normalizados a UTC en
`xsd:dateTime`.

`uniqueId` se valida como entero entre 0 y 4294967295.

## Decisión 3 — Clock, uniqueId y ventana

Se introducen contratos:

- `WsaaTraClock`;
- `WsaaTraUniqueIdProvider`;
- `WsaaTraBuilder`.

`WsaaTraWindowPolicy` es una política local explícita. No tiene valores mágicos
por defecto: el caller debe indicar cuánto retroceder `generationTime` y cuánto
adelantar `expirationTime`.

Ambos márgenes se limitan a la tolerancia máxima documentada de 86400 segundos.

`WsaaLoginTicketRequestBuilder` consume clock, uniqueId provider y window policy
para construir un TRA determinista y testeable.

Este V1 no enlaza implementaciones de clock/uniqueId al container.

## Decisión 4 — Digest explícito

`WsaaCmsDigestAlgorithm` expone técnicamente `sha1` y `sha256`, ambos
demostrados generables por el runtime local.

`WsaaCmsDigestPolicy` permanece como contrato sin implementación ni binding.

No existe digest implícito/default en el dominio.

La futura política de homologación deberá resolverse con evidencia real del
provider.

## Decisión 5 — CMS signer boundary

`WsaaCmsSigner` define:

`TRA + material de credencial + digest explícito -> WsaaSignedCms`

`WsaaSignedCms`:

- contiene Base64 puro;
- no acepta wrappers PEM/MIME;
- conserva el digest elegido;
- mantiene el contenido firmado redactado en debug;
- rechaza serialización PHP.

No se implementa signer concreto en este V1.

## Fail-closed deliberado

Permanecen sin binding:

- `WsaaTraBuilder`;
- `WsaaTraClock`;
- `WsaaTraUniqueIdProvider`;
- `WsaaCredentialMaterialProvider`;
- `WsaaCmsDigestPolicy`;
- `WsaaCmsSigner`.

Por tanto no puede aparecer una ejecución WSAA real por haber agregado estas
clases.

## Fuera de alcance

- resolver referencias reales de certificado/clave;
- leer archivos de credencial;
- secret vault concreto;
- validar X.509 real;
- elegir digest aceptado por ARCA;
- ejecutar OpenSSL/PHP signing desde código productivo;
- `LoginCms`;
- SOAP;
- cache/reuso de Ticket de Acceso;
- WSFE HTTP;
- CAE real;
- producción.

## Validación requerida

- XSD de `service` aplicado en todos los objetos de scope;
- XML TRA exacto;
- UTC;
- límites unsignedInt;
- límites de ventana;
- contratos signer/digest sin binding;
- CMS Base64/redacción/no serialización;
- ausencia de OpenSSL/SOAP/network execution en el nuevo boundary;
- focal;
- regresión fiscal;
- suite completa;
- BD real lógicamente intacta.

## Próxima frontera

`ARCA_WSAA_CREDENTIAL_MATERIAL_RESOLUTION_RECON_V1`

Ese RECON debe decidir, sin imprimir ni copiar secretos, cómo resolver las
referencias opacas ya existentes hacia material PEM real en homologación y qué
mecanismo local de signing podrá consumirlo sin degradar aislamiento,
redacción ni rotación.
