# ADR 70 — Post-Sale Economic Resolution HTTP V1

Estado: Aceptada para P8.5.3

Checkpoint de partida:
`8f44ed20532a73b57c2de1fe55e5c57dc4d469e8`

## 1. Contexto

P8.5.1 abrió el expediente e intake HTTP.

P8.5.2 expuso la recepción física confirmada y su `CustomerReturn` real.

P8.3 ya contiene el contrato económico autoritativo mediante
`CommercePostSaleResolutionManager`, pero todavía no tenía superficie operativa.

## 2. Decisión

P8.5.3 expone la resolución económica por HTTP como acto administrativo separado.

Se agregan:

1. Gate explícito `resolve-commerce-post-sale`;
2. formulario Admin-only desde el expediente;
3. selección de líneas físicamente recibidas todavía no resueltas;
4. cantidad a resolver;
5. valor reconocido;
6. motivo obligatorio de ajuste cuando el valor cae respecto del original;
7. outcome `refund`, `customer_credit` o `exchange`;
8. medio original preferido opcional exclusivamente para refund;
9. razón y notas;
10. visualización detallada de la resolución en el expediente.

## 3. Autoridad

`UserRole::canResolveCommercePostSale()` ya define que sólo Admin puede resolver.

P8.5.3 materializa esa regla en un Gate HTTP dedicado:

`resolve-commerce-post-sale`

El controller no reemplaza el manager.

El manager continúa siendo la autoridad final y revalida dentro de transacción:

- organización;
- venta confirmada;
- recepción y solicitud originales;
- acumulado resuelto;
- baseline proporcional;
- importe reconocido;
- motivo de ajuste;
- pago original preferido;
- cliente identificado cuando outcome es saldo a favor;
- idempotencia y fingerprint.

## 4. Hechos físicamente disponibles

El formulario deriva sólo para visualización:

`received quantity - sum(prior resolution quantities)`

La cifra visible no es autoridad de escritura.

El manager vuelve a bloquear las líneas recibidas y calcula el acumulado antes
de confirmar la resolución.

## 5. Valor reconocido

El usuario ingresa importe monetario con máximo dos decimales.

HTTP lo convierte a minor units sin redondeo.

La autoridad económica permanece en P8.3:

- no puede superar el valor original proporcional;
- una reducción exige motivo explícito;
- el total reconocido debe caber en el pago original preferido, si se señaló.

## 6. Preferred original payment

La selección es opcional y sólo describe preferencia para outcome `refund`.

El FormRequest limita la referencia a pagos originales de la misma venta.

El manager rechaza su uso para otros outcomes y vuelve a validar pertenencia e
importe.

Seleccionar un medio no mueve dinero ni crea una instrucción de refund.

## 7. Sin materialización automática

Registrar resolución no:

- crea `CustomerCreditGrant`;
- ejecuta `CashMovement`;
- crea instrucción o dispatch de refund externo;
- selecciona reemplazo;
- ejecuta cambio;
- crea `FinancialExternalMovement`;
- modifica el pago original.

Los motores P8.4 conservan sus permisos, idempotencia y segregaciones propias.

## 8. Tenant boundary

Los endpoints continúan dentro de `auth`, `verified` y `RequireOrganization`.

El expediente se revalida contra `CurrentOrganization`.

Las líneas recibidas permitidas se obtienen exclusivamente del expediente
actual y la organización activa.

El pago preferido debe pertenecer a la misma organización y venta.

## 9. Idempotencia

El navegador recibe una clave idempotente única.

El reintento exacto retorna la misma resolución.

La reutilización conflictiva falla cerrado en el manager.

## 10. No objetivos

P8.5.3 no agrega esquema ni migra la BD real.

No expone todavía HTTP para materializar o ejecutar outcomes.

No llama proveedores externos.

## 11. Continuidad

P8.5.4 debe abrir la materialización operativa de outcomes de forma segregada,
sin convertir la resolución administrativa en una acción monetaria implícita.

Ese corte deberá decidir explícitamente qué outcomes pueden exponerse juntos y
cuáles requieren superficies operativas separadas por sus permisos financieros,
de caja e inventario.
