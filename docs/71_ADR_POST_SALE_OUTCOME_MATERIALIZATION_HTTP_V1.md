# ADR 71 — Post-Sale Outcome Materialization HTTP V1

Estado: Aceptada para P8.5.4

Checkpoint de partida:
`66977eb65d9d4aadaca1863e63e54b8ef7f7ee74`

## 1. Contexto

P8.5.3 abrió la resolución económica HTTP como acto Admin-only, sin ejecutar
ningún outcome.

P8.4 ya contiene motores separados para:

- materializar saldo a favor;
- ejecutar reembolso en efectivo;
- instruir reembolso externo;
- seleccionar reemplazo;
- ejecutar cambio;
- despachar y seguir evidencia de reembolso externo.

La capa HTTP no debe colapsar esas responsabilidades en una acción genérica.

## 2. Hallazgo operativo

Los motores de reembolso en efectivo y externo exigen un
`preferred_original_payment_id` explícito.

P8.3 permite que una resolución `refund` sea abstracta y no lo exige siempre.

Una resolución confirmada es inmutable. Por lo tanto, permitir desde la UI un
refund sin pago original deja un hecho económico que luego no puede ejecutar
ninguno de los motores existentes.

P8.5.4 endurece exclusivamente la superficie HTTP:

- si `outcome = refund`, el pago original es obligatorio;
- para cualquier otro outcome queda prohibido.

No se cambia el contrato de dominio P8.3.

## 3. Acciones segregadas

P8.5.4 expone cuatro acciones distintas.

### 3.1 Saldo a favor

Gate:
`materialize-commerce-post-sale-customer-credit`

Autoridad:
`canMaterializeCommercePostSaleCustomerCredit()`

Motor:
`CommercePostSaleCustomerCreditManager::grant(...)`

Resultado:
`CustomerCreditGrant` append-only.

No se implementa todavía el consumo de ese saldo.

### 3.2 Reembolso en efectivo

Gate:
`execute-commerce-post-sale-cash-refund`

Autoridad:
`canExecuteCommercePostSaleCashRefund()`

Motor:
`CommercePostSaleCashRefundManager::execute(...)`

El dominio conserva:

- segregación entre resolutor y ejecutor;
- pago original cash explícito;
- cuenta CashBox activa;
- sesión de caja abierta propia del ejecutor;
- límite agregado contra el pago original;
- `CashMovement` de salida append-only.

Esta acción sí representa una salida económica real en el entorno donde se use.

El runner de implementación sólo la prueba sobre la BD efímera de tests.

## 4. Reembolso externo

Gate:
`execute-commerce-post-sale-external-refund`

Autoridad:
`canExecuteCommercePostSaleExternalRefund()`

Motor:
`CommercePostSaleExternalRefundInstructionManager::request(...)`

P8.5.4 crea únicamente la instrucción local.

No llama al proveedor y no crea verdad financiera externa.

El manager conserva el `FinancialProviderAutomationGate`.
Si el provider/capability/health actual no habilita refund, la acción falla
cerrado.

La anomalía sandbox de Mercado Pago documentada en ADR 65 no se reabre ni se
fuerza.

## 5. Cambio

Gate:
`select-commerce-post-sale-exchange`

Autoridad:
`canResolveCommercePostSale()`

Motor:
`CommercePostSaleExchangeSelectionManager::select(...)`

La UI ofrece únicamente productos activos con precio privado vigente para la
organización y moneda de la resolución.

La selección vuelve a resolver el precio en servidor y conserva la
proveniencia de `OrganizationProductPrice`.

No:

- reserva stock;
- selecciona ubicación física;
- entrega reemplazo;
- cobra diferencia;
- devuelve diferencia.

## 6. Ejecuciones aún no expuestas

P8.5.4 no abre HTTP para:

- `CommercePostSaleExchangeExecutionManager`;
- dispatch/submission de refund externo;
- polling de evidencia de refund;
- consumo de saldo a favor.

Esos hechos tienen condiciones financieras, de inventario y de proveedor
adicionales y permanecen separados.

## 7. Tenant boundary

Todos los route models de resolución se revalidan contra
`CurrentOrganization`.

Los Gates se resuelven desde el rol de membership activo.

El manager correspondiente vuelve a verificar tenant y autoridad dentro de la
operación.

## 8. Idempotencia

Cada acción recibe una clave idempotente independiente.

El HTTP no implementa una idempotencia paralela: delega en los motores P8.4.

## 9. Visibilidad operativa

El expediente presenta sólo acciones compatibles con el outcome y con la
materialización actual.

Los errores de dominio se muestran en el expediente/formulario sin convertir
un fallo en un efecto parcial.

## 10. No objetivos

P8.5.4:

- no agrega esquema;
- no migra la BD real;
- no altera pagos originales;
- no llama proveedores externos;
- no ejecuta cambios;
- no despacha refunds externos;
- no consume créditos.

## 11. Continuidad

P8.5.5 debe abordar únicamente los hechos restantes de ejecución:

1. ejecución del cambio:
   stock origen/condición + eventual diferencia;
2. dispatch y evidencia de refund externo detrás del gate vigente;
3. decisión separada futura sobre consumo de saldos a favor.

La anomalía actual de Mercado Pago mantiene el dispatch bloqueado hasta nueva
evidencia, sin impedir que la superficie genérica quede preparada.
