# ADR 66 — Post-Sale Exchange Selection & Price Difference Foundation V1

Estado: Aceptada para P8.4.4

Checkpoint de partida:
`b9bf4e4841e1a53de0a35221715a234119560d52`

## 1. Contexto

P8.3 separó la resolución económica de una posventa de su ejecución material.

Para `exchange`, la resolución conserva el valor reconocido de la mercadería
devuelta, pero deliberadamente no selecciona un reemplazo, no emite inventario y
no calcula una diferencia sin conocer mercadería concreta.

P8.4.1 materializó saldo a favor.
P8.4.2 materializó reembolso en efectivo.
P8.4.3 y sus subcortes construyeron el reembolso externo.

El resultado pendiente de P8.4 es el cambio.

## 2. Decisión

P8.4.4 crea un hecho privado e inmutable que fija:

- resolución `exchange`;
- productos concretos de reemplazo;
- cantidades;
- precio autorizado vigente por organización para cada producto;
- identidad de `OrganizationProductPrice` usada como provenance;
- valor reconocido de la resolución;
- valor total de reemplazo derivable de sus líneas;
- diferencia de precio derivable y firmada.

Convención de diferencia:

`replacement_amount_minor - recognized_amount_minor`

Por tanto:

- resultado positivo: el cliente deberá completar dinero;
- cero: cambio económico exacto;
- resultado negativo: la organización deberá resolver valor a favor del cliente.

P8.4.4 no ejecuta todavía ninguno de esos flujos monetarios.

## 3. Precio server-authoritative

El precio del reemplazo nunca se recibe del cliente ni de UI como autoridad.

`CommercePostSaleExchangeSelectionManager` consulta
`OrganizationProductPriceReader::priceAt()` usando:

- organización activa;
- producto concreto;
- moneda de la resolución;
- instante de selección generado por servidor.

La línea congela:

- `organization_product_price_id`;
- `unit_price_minor`;
- cantidad;
- `line_amount_minor`.

La base valida que el precio referenciado pertenezca a la organización,
producto y moneda correctos y que haya sido vigente al instante de selección.

## 4. Cantidades

Las cantidades usan `InventoryQuantity` y respetan `quantity_scale` del
producto.

El importe de línea debe resultar exactamente en unidades monetarias menores.
No se redondean silenciosamente fracciones de centavo.

Un mismo producto no puede aparecer duplicado dentro de una selección.

## 5. Idempotencia y unicidad

Existe como máximo una selección confirmada por resolución.

La clave idempotente es única por organización.

La huella liga:

- organización;
- resolución;
- fingerprint de resolución;
- actor;
- notas;
- productos y cantidades normalizados.

Reintentar exactamente la misma operación devuelve el mismo hecho.
Intentar reutilizar la clave o la resolución con otro contenido falla cerrado.

## 6. Autoridad

Seleccionar mercadería de reemplazo y fijar su diferencia económica requiere la
misma autoridad administrativa usada para resolver económicamente la posventa:

`canResolveCommercePostSale()`.

La migración refuerza que `selected_by_user_id` conserve membresía Admin activa
en la organización.

## 7. Inmutabilidad

`commerce_post_sale_exchange_selections` y sus líneas son hechos confirmados.

No admiten update ni delete por modelo ni por SQL directo en SQLite/MySQL.

La resolución original, venta, pagos y recepción no se reescriben.

## 8. Lo que P8.4.4 NO hace

Este corte no:

- crea `InventoryMovement`;
- entrega físicamente el reemplazo;
- reserva stock;
- crea o modifica `CommercePayment`;
- crea `CashMovement`;
- crea `FinancialExternalMovement`;
- concede saldo a favor;
- ejecuta reembolso;
- cobra una diferencia positiva;
- devuelve una diferencia negativa;
- modifica la venta original.

La diferencia es una verdad económica preparada, no una ejecución de dinero.

## 9. Próximo corte

Un corte posterior deberá ejecutar el cambio seleccionado:

1. revalidar la selección inmutable;
2. elegir origen y condición de stock;
3. emitir la salida de inventario del reemplazo;
4. materializar la diferencia:
   - positiva como cobro explícito;
   - cero sin movimiento monetario;
   - negativa mediante una resolución monetaria autorizada;
5. conservar idempotencia, segregación, auditoría y enlaces entre hechos.

P8.4.4 evita mezclar selección comercial, custodia de inventario y dinero en una
sola transacción prematura.
