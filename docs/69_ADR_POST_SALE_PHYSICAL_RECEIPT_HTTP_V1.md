# ADR 69 — Post-Sale Physical Receipt HTTP V1

Estado: Aceptada para P8.5.2

Checkpoint de partida:
`abfd36d41d606fe2a4eb37382a5378700bf2af3e`

## 1. Contexto

P8.5.1 abrió el expediente operativo y el intake HTTP sin exponer todavía los
hechos posteriores de posventa.

P8.2 ya posee un contrato de dominio completo para recepción física mediante
`CommercePostSaleReceiptManager`:

- permiso `canReceiveCommercePostSaleReturn`;
- tenant activo;
- idempotencia;
- acumulado por línea;
- condición física real;
- ubicación activa;
- `InventoryMovementType::CustomerReturn`;
- confirmación real del ledger;
- trazabilidad request → receipt → inventory movement;
- inmutabilidad y auditoría.

## 2. Decisión

P8.5.2 expone ese contrato por HTTP sin crear un segundo motor de reglas.

Se agregan:

1. formulario de recepción desde el expediente;
2. cálculo read-only de solicitado, recibido y pendiente por línea;
3. selección explícita de líneas realmente recibidas;
4. cantidad, condición y ubicación de ingreso por línea;
5. notas por línea y generales;
6. confirmación a través de `CommercePostSaleReceiptManager`;
7. visualización ampliada de recepciones confirmadas en el expediente.

## 3. Autoridad

La superficie HTTP usa el Gate `record-commerce-post-sale`.

La autoridad final sigue siendo el manager, que exige además
`canReceiveCommercePostSaleReturn`.

Admin y Operador pueden recibir; Viewer no.

## 4. Tenant boundary

El route model `commercePostSaleRequest` se revalida contra
`CurrentOrganization`.

El `FormRequest` aborta 404 ante expediente extranjero antes de resolver
referencias de líneas o ubicaciones.

Las líneas válidas deben pertenecer exactamente al expediente activo.

Las ubicaciones deben pertenecer a la organización y estar activas.

## 5. Cantidades

El formulario presenta el saldo físico pendiente derivado de hechos existentes:

`requested quantity - sum(confirmed receipt lines)`

Este cálculo es sólo informativo.

La validación de autoridad sobre el acumulado permanece en
`CommercePostSaleReceiptManager`, que bloquea y vuelve a calcular antes de
confirmar.

No existe redondeo silencioso.

## 6. Efecto físico

Una recepción HTTP exitosa produce exactamente el mismo hecho P8.2 que una
ejecución de dominio:

- `CommercePostSaleReceipt`;
- líneas de recepción;
- `InventoryMovement` tipo `customer_return`;
- movimiento confirmado;
- aumento de inventario en la condición y ubicación declaradas.

La venta original permanece confirmada e inmutable.

## 7. Sin efecto económico

P8.5.2 no:

- resuelve valor reconocido;
- crea saldo a favor;
- devuelve efectivo;
- instruye reembolso externo;
- selecciona reemplazo;
- ejecuta cambio;
- modifica pagos originales.

Recepción física y decisión económica siguen siendo etapas separadas.

## 8. Idempotencia

El navegador recibe una clave idempotente única.

Un reintento exacto no duplica recepción ni movimiento de inventario.

La reutilización de la misma clave con contenido distinto falla cerrado por el
manager.

## 9. No objetivos

P8.5.2 no agrega esquema ni migra la BD real.

No habilita resolución económica HTTP.

No habilita ejecución de outcomes.

No usa HTTP de proveedores externos.

## 10. Continuidad

P8.5.3 debe exponer la resolución económica como acto administrativo separado,
consumiendo únicamente cantidades físicamente recibidas y manteniendo la
segregación P8.3.
