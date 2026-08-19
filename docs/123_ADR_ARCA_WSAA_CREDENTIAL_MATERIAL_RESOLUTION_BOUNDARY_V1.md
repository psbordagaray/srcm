# ADR 123 — ARCA WSAA Credential Material Resolution Boundary V1

Estado: aceptado.

## Contexto

El RECON `ARCA_WSAA_CREDENTIAL_MATERIAL_RESOLUTION_RECON_V1` cerró GREEN sobre
el checkpoint canónico
`505607a96561e8f8ca6b5e45974c4ef01217708d`.

Confirmó:

- `WsaaCredentialMaterialProvider` seguía abstracto y sin binding;
- `WsaaCredentialMaterialReferenceStore` ya estaba enlazado a un mapa de
  referencias opacas tenant-scoped;
- no había archivos `.pem`, `.key`, `.p12`, `.pfx`, `.crt` ni `.cer` trackeados;
- la configuración local no contenía todavía referencias ni raíz real ARCA;
- PHP 8.3.30 dispone de OpenSSL y de `openssl_x509_read`,
  `openssl_pkey_get_private` y `openssl_x509_check_private_key`;
- `icacls.exe` está disponible en Windows;
- SOAP sigue deshabilitado;
- no se leyó material real ni se efectuó signing, `LoginCms` o HTTP ARCA.

## Decisión 1 — Referencias concretas

El store de referencias continúa siendo provider-neutral respecto del material:
conserva strings opacos y no lee archivos.

El concrete material provider interpreta únicamente:

- `file:<ruta-relativa>` para certificado;
- `file:<ruta-relativa>` para clave privada;
- `env:<NOMBRE_VARIABLE>` para passphrase opcional.

No se aceptan paths absolutos, backslashes dentro de la referencia, `..`, `.`,
segmentos vacíos, URLs, `vault://` ni passphrases inline en este concrete
provider.

Esto no declara que un vault sea inválido como evolución futura: sólo evita
fingir un backend que SRCM todavía no implementa.

## Decisión 2 — Raíz externa

`SRCM_ARCA_WSAA_CREDENTIAL_ROOT` identifica una raíz dedicada de credenciales.

La raíz:

- debe existir y ser legible;
- se canonicaliza con `realpath`;
- debe quedar completamente aislada del repositorio: ni dentro de él ni como
  ancestro que lo contenga;
- no se persiste en BD;
- no se incorpora a `config/services.php`;
- no se imprime junto con material sensible.

Cada archivo también se canonicaliza con `realpath` y debe permanecer dentro de
la raíz. Un symlink/reparse point que escape de la raíz queda rechazado por la
comprobación de containment.

## Decisión 3 — Lectura limitada y efímera

Cada archivo debe:

- existir;
- ser archivo regular;
- ser legible;
- medir entre 1 byte y 1 MiB.

Se lee sólo al resolver un request WSAA. No existe cache de PEM ni de
passphrase.

`WsaaCredentialMaterial` conserva su contrato privado, redactado y no
serializable.

## Decisión 4 — Validación criptográfica

`WsaaCredentialMaterialValidator` separa la validación criptográfica del acceso
a archivos.

`OpenSslWsaaCredentialMaterialValidator` exige:

1. certificado interpretable por `openssl_x509_read`;
2. clave privada interpretable por `openssl_pkey_get_private`, con passphrase si
   corresponde;
3. `openssl_x509_check_private_key(...) === true`.

Los detalles de la cola de errores OpenSSL se descartan deliberadamente y nunca
se vuelcan en logs o excepciones.

El runner valida esta implementación además con un par certificado/clave
**sintético y efímero**, incluyendo rechazo de una clave que no corresponde.
No se usa ningún material ARCA real.

## Decisión 5 — Binding

Se enlazan:

- `WsaaCredentialMaterialValidator` →
  `OpenSslWsaaCredentialMaterialValidator`;
- `WsaaCredentialMaterialProvider` →
  `EnvironmentWsaaCredentialMaterialProvider`.

El binding no abre tráfico externo: ningún flujo publicado consume todavía ese
provider para firmar o ejecutar `LoginCms`.

`FiscalAuthorizationCredentialStore` sigue sin binding y no se modifica.

## Decisión 6 — Defensa contra commits accidentales

`.gitignore` incorpora patrones globales para:

- `*.pem`;
- `*.key`;
- `*.p12`;
- `*.pfx`;
- `*.crt`;
- `*.cer`.

La raíz externa sigue siendo la defensa principal; el ignore es una barrera
adicional contra incorporación accidental.

## ACL en Windows

Este V1 no interpreta salida localizada de `icacls` desde código productivo.
Antes de introducir material ARCA real, un gate operativo deberá verificar ACL
de la raíz y de los archivos concretos. La disponibilidad de `icacls.exe` ya
quedó probada por RECON.

No se declara una ACL real GREEN porque todavía no hay raíz real configurada.

## Fail-closed preservado

Permanecen sin implementación/binding:

- `WsaaCmsSigner` concreto;
- `WsaaCmsDigestPolicy` concreto;
- `WsaaAccessTicketProvider` concreto;
- SOAP / `LoginCms`;
- WSFE HTTP real.

Producción continúa bloqueada.

## Fuera de alcance

- incorporar certificado o clave ARCA real;
- leer referencias reales en el runner;
- decidir aceptación SHA-1/SHA-256 por ARCA;
- firmar TRA real;
- cachear Ticket de Acceso;
- ejecutar `LoginCms`;
- habilitar SOAP;
- solicitar CAE;
- producción.

## Próxima frontera

`ARCA_WSAA_CMS_SIGNER_EXECUTION_RECON_V1`

Debe decidir el mecanismo concreto de ejecución CMS attached con digest
explícito, archivos temporales seguros y limpieza garantizada, sin confundir
capacidad criptográfica local con aceptación del provider.
