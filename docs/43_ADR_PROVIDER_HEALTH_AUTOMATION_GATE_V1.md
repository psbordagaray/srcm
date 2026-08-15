# ADR 43 — Provider Health Checks & Automation Gate V1

Estado: Aceptada para P5.8.1

Checkpoint de partida:
`22c82dda9733acb71467b2c4e73b816fce3b6129`

## 1. Decisión

P5.8 separa tres dimensiones:

1. compatibilidad global del contrato externo, definida por P5.7;
2. salud runtime de una conexión tenant-specific;
3. decisión de permitir o bloquear una automatización que dependa de una
   capacidad concreta del proveedor.

Una falla del proveedor no degrada caja, inventario, ventas ni el core de
SRCM.

## 2. Health checks append-only

Cada health check es evidencia inmutable de una conexión y una capacidad:

- organización;
- conexión financiera;
- binding de compatibilidad actual, si existe;
- capacidad;
- estado;
- fuente segura;
- código diagnóstico seguro;
- latencia;
- instante de verificación.

No se actualizan ni eliminan health checks anteriores.

## 3. Estados runtime

Estados V1:

- `healthy`
- `degraded`
- `unavailable`
- `unknown`

Estos estados son tenant-specific y no sustituyen el estado global del
Compatibility Registry.

## 4. Diagnósticos seguros

P5.8.1 no persiste mensajes arbitrarios del proveedor.

`source_key` y `diagnostic_code` son códigos estructurados con caracteres
limitados. No se almacenan:

- Access Tokens;
- Webhook Secrets;
- Authorization;
- headers;
- body de respuesta;
- raw payloads;
- mensajes externos arbitrarios.

## 5. Binding-aware health

Cuando existe binding, el health check queda asociado al binding actual.

Después de migrar una conexión a otro snapshot, un health check saludable del
binding anterior no habilita automatizaciones sobre el contrato nuevo.

El nuevo binding necesita nueva evidencia runtime.

## 6. Gate capability-specific

`FinancialProviderAutomationGate` sólo gobierna automatizaciones que dependen
del proveedor.

Para permitir una automatización exige:

- conexión activa;
- binding actual;
- snapshot no retirado;
- sin migración requerida;
- estado global `compatible` o `degraded`;
- capacidad solicitada declarada como `compatible`;
- último health check de esa capacidad y binding en `healthy`.

Cualquier ausencia o incompatibilidad falla cerrado para esa automatización.

## 7. Degradación localizada

Si el proveedor está `degraded` o `unavailable`:

- la automatización provider-dependent se bloquea;
- la conexión y la cuenta no se desactivan automáticamente;
- caja sigue operativa;
- inventario sigue operativo;
- ventas siguen operativas;
- SRCM no inventa un resultado financiero.

El restablecimiento se representa con un nuevo health check `healthy`.

## 8. Conexiones legacy

Una conexión todavía sin binding puede tener evidencia de health para
diagnóstico transicional, pero el gate no permite automatización hasta que
exista un binding compatible.

## 9. Base de datos

La DB impide:

- health check con `organization_id` distinto al de la conexión;
- health check con binding perteneciente a otra conexión;
- UPDATE de evidencia;
- DELETE físico de evidencia.

## 10. Alcance de P5.8.1

P5.8.1 incorpora:

- modelo provider-neutral de health;
- persistencia tenant-specific append-only;
- selección del último check por capacidad + binding;
- policy de automatización fail-closed.

P5.8.2 incorporará probes seguros/read-only concretos y visibilidad
operacional de última verificación/estado.
