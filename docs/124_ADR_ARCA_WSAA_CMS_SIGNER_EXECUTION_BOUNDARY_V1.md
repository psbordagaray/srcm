# ADR 124 — ARCA WSAA CMS Signer Execution Boundary V1

Estado: aceptado.

## Contexto

`ARCA_WSAA_CMS_SIGNER_EXECUTION_RECON_V1` cerró GREEN sobre el checkpoint
canónico `f9ce59a4682f414e96a4fec2b642a69124f8b65a`.

El RECON confirmó localmente:

- `WsaaCmsSigner` seguía sin implementación/binding;
- `WsaaCmsDigestPolicy` seguía sin implementación/binding;
- `WsaaCredentialMaterialProvider` ya estaba enlazado;
- OpenSSL CLI 3.2.3 expone `-md`, `-nodetach`, `-binary`, `-outform`, `-passin`,
  `-signer` e `-inkey`;
- CMS attached DER sintético fue válido tanto con SHA-1 explícito como con
  SHA-256 explícito;
- PHP `openssl_cms_sign()` funciona localmente pero no ofrece un parámetro de
  digest explícito y produjo SHA-256 en el probe actual;
- la passphrase sintética pudo viajar por entorno efímero sin aparecer en el
  command line ni en RESULT;
- `icacls.exe` está disponible con soporte para inheritance/grant;
- SOAP continúa deshabilitado;
- no se usó material ARCA real, no hubo `LoginCms` ni HTTP ARCA.

## Decisión 1 — Signer concreto, digest siempre explícito

`OpenSslCliWsaaCmsSigner` implementa `WsaaCmsSigner` mediante OpenSSL CLI.

El comando de signing contiene obligatoriamente:

- `cms -sign`;
- `-nodetach`;
- `-binary`;
- `-outform DER`;
- `-md <digest explícito>`;
- `-signer`;
- `-inkey`;
- `-passin env:<clave efímera>`.

El digest proviene exclusivamente del `WsaaCmsDigestAlgorithm` recibido por el
contrato `sign(...)`. El signer no selecciona SHA-1 ni SHA-256 por defecto.

## Decisión 2 — `WsaaCmsDigestPolicy` permanece sin binding

Que el runtime pueda generar CMS con SHA-1 y SHA-256 no demuestra qué variante
acepta hoy ARCA en homologación para nuestro certificado y contexto reales.

Por eso este V1 **no implementa ni enlaza** `WsaaCmsDigestPolicy`.

La aceptación provider-real del digest sigue pendiente de una verificación
legítima de homologación. No se convierte una capacidad OpenSSL local en verdad
de ARCA.

## Decisión 3 — PHP `openssl_cms_sign()` no es el signer primario

La API PHP local no expone digest explícito. Usarla como signer primario
introduciría un default criptográfico implícito dependiente del runtime.

Se conserva como capacidad técnica disponible, no como ejecución productiva de
esta frontera.

## Decisión 4 — Temporales sensibles fuera del repositorio

El signer crea un workspace aleatorio bajo `sys_get_temp_dir()` y exige que la
raíz temporal y el repositorio sean disjuntos.

El workspace contiene transitoriamente:

- TRA XML;
- certificado PEM;
- clave privada PEM;
- CMS DER de salida.

No se escribe ningún secreto dentro del repositorio.

## Decisión 5 — ACL/permisos antes de material sensible

En Windows:

- se deshabilita inheritance con `icacls`;
- se concede control al usuario de ejecución;
- el directorio se endurece antes de escribir inputs;
- cada archivo se vuelve a endurecer explícitamente.

En sistemas POSIX se exige `0700` para el directorio y `0600` para archivos.

Si el endurecimiento falla, no continúa el signing.

## Decisión 6 — Passphrase fuera del command line

La passphrase de clave privada nunca se concatena al comando.

Para cada firma se genera un nombre de variable de entorno aleatorio y se pasa:

`-passin env:<VARIABLE_EFIMERA>`

El valor existe sólo en el environment del proceso hijo y se elimina del mapa
en memoria inmediatamente después de la ejecución.

## Decisión 7 — Ejecución nativa sin shell ni anonymous pipes

`NativeWsaaCmsProcessRunner` usa `proc_open` con `bypass_shell=true` y
stdin/stdout/stderr dirigidos al dispositivo nulo.

No usa `shell_exec`, `exec` ni anonymous pipes. Esto evita volver a introducir
el deadlock Windows que se observó durante la ejecución de PHPUnit en la
frontera anterior.

Toda operación tiene timeout. En Windows el timeout intenta terminar el árbol
del proceso; en otros entornos termina el proceso directo.

Los errores productivos son deliberadamente genéricos: no incorporan command
line, paths sensibles, stderr OpenSSL ni material criptográfico.

## Decisión 8 — Cleanup obligatorio antes de devolver CMS

El signer no retorna `WsaaSignedCms` hasta que el workspace completo fue
eliminado.

Si la firma falla o la limpieza no puede comprobarse, la operación falla
cerrada.

El DER generado se lee con tamaño acotado, se codifica Base64 en memoria y se
entrega al contrato existente `WsaaSignedCms`.

## Decisión 9 — Producción continúa bloqueada

Este signer sólo acepta `FiscalEnvironment::Homologation`.

No habilita producción ni implica que exista una integración ARCA validada.

## Bindings

Se enlazan:

- `WsaaCmsProcessRunner` → `NativeWsaaCmsProcessRunner`;
- `WsaaCmsSigner` → `OpenSslCliWsaaCmsSigner`.

Permanece deliberadamente sin binding:

- `WsaaCmsDigestPolicy`.

## Fuera de alcance

- seleccionar provider-real SHA-1 o SHA-256;
- certificado/clave ARCA real en tests;
- `LoginCms`;
- SOAP/HTTP WSAA;
- cache de Ticket de Acceso;
- WSFE HTTP;
- CAE real;
- producción.

## Próxima frontera

`ARCA_WSAA_LOGIN_CMS_TRANSPORT_RECON_V1`

Debe reconstruir el wire exacto de `LoginCms`, decidir el transporte concreto
con el runtime disponible y conservar `WsaaSignedCms` como input sensible sin
abrir todavía una falsa validación de homologación.
