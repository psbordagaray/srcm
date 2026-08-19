# ADR 126 — ARCA WSAA Access Ticket Provider Boundary V1

Estado: **Aceptada**
Fecha: **2026-08-19**

## Contexto

SRCM ya publica las fronteras de scope y Ticket de Acceso WSAA, TRA, resolución de material X.509, firma CMS y transporte SOAP 1.1 `LoginCms`, pero hasta este corte no existía una implementación de `WsaaAccessTicketProvider` que coordinara esas piezas ni una política de digest enlazada.

El RECON previo confirmó que ARCA documenta firma `SHA1+RSA`, que el Ticket de Acceso tiene vigencia de 12 horas y que un TA todavía válido debe reutilizarse. También confirmó que el runtime posee cache database, locks y cifrado, pero que producción y la aceptación provider-real del digest siguen sin validar.

## Decisión

1. `WsaaAccessTicketProvider` se enlaza a `EncryptedCacheWsaaAccessTicketProvider`.
2. El scope exacto del TA es `organization_id + environment + service + issuer_cuit` y sólo su SHA-256 aparece en keys de cache/lock.
3. El cache persiste únicamente un **string cifrado**. `Token` y `Sign` no se guardan en claro y no se serializa el objeto `WsaaAccessTicket`.
4. El provider reutiliza un TA hasta su **expiración real**. No existe refresh preventivo.
5. Un envelope corrupto, ilegible, de scope distinto o no descifrable falla cerrado y **no** dispara un nuevo `LoginCms` automático.
6. Si no existe TA vigente, el provider toma un lock distribuido por el mismo scope, vuelve a leer el cache dentro del lock y recién entonces compone `TRA → material → digest → CMS → LoginCms → TA → cache`.
7. Política de proyecto: lock 60 s, espera máxima 20 s; el envelope se conserva hasta 300 s después de la expiración para observabilidad/coherencia de cache. Estos valores no son hechos ARCA.
8. `WsaaTraClock` se enlaza a reloj UTC de sistema, `WsaaTraUniqueIdProvider` a entero criptográficamente aleatorio unsigned de 32 bits, y la ventana TRA del builder queda en `generationTime = now - 60 s`, `expirationTime = now + 600 s`.
9. `WsaaCmsDigestPolicy` usa `sha1` sólo en homologación por la especificación oficial vigente. Producción falla cerrado; no existe fallback/default SHA-256 y la aceptación provider-real continúa **NO VALIDADA** hasta una prueba separada.
10. No hay retry automático de `LoginCms`. Los faults del transporte siguen su clasificación publicada; `coe.alreadyAuthenticated` se trata operacionalmente como señal de incoherencia de TA/cache/otro cliente, no como permiso para repetir.
11. Si un TA nuevo fue recibido pero su cache write falla, el provider falla cerrado y no devuelve el TA ni reintenta automáticamente.
12. Producción permanece bloqueada antes de cache/credencial/CMS/red.

## Runtime cache

El binding usa explícitamente el store Laravel `database`, porque el RECON comprobó que ese store y su lock provider existen en el runtime actual. El runner de este corte hace una sonda **sintética y autolimpiable** del cache/lock real: escribe ciphertext sintético, lo descifra, adquiere/libera un lock y elimina la fila. El conteo de `cache`/`cache_locks` debe regresar exactamente al valor previo. No usa certificado, clave privada, TA ni HTTP ARCA reales.

La integridad de la BD continúa midiéndose por fingerprint lógico de tablas de negocio + schema + migration ledger. El SHA binario SQLite es sólo canary y puede cambiar ante escrituras transitorias de cache aun cuando el estado lógico final sea idéntico.

## Fuera de alcance

- `LoginCms` real contra homologación;
- lectura de credenciales ARCA reales durante el runner;
- aceptación provider-real del digest;
- habilitación de producción;
- coordinación con clientes externos que compartan el mismo certificado fuera de SRCM;
- retries automáticos;
- cambios de schema/migraciones.

## Siguiente frontera

`ARCA_WSAA_HOMOLOGATION_LOGIN_CMS_RECON_V1`.

Debe preparar una única prueba controlada de homologación real, verificando referencias/configuración sin imprimir secretos y sin ejecutar todavía `LoginCms` durante el RECON.
