# ADR 84 — Purchase Three-Way Match Derived V1

Estado: Aceptada para P9.7b

Checkpoint de partida:
`f7bfa2bf4cba161333ef3dfe07e1ac183ec63a37`

Fecha: 2026-08-17

## Contexto

P9.7a incorporó `SupplierInvoice` como evidencia económica inmutable y separada de:

- la intención comercial de `PurchaseOrder`;
- la verdad física de `PurchaseReceipt`;
- la deuda reconocida en `PurchaseObligation`;
- la ejecución monetaria posterior.

El siguiente requisito de P9 es comparar orden, recepción y documento del proveedor conservando las diferencias explícitas.

## Decisión

P9.7b implementa el 3-way match como **read model derivado**, no como tabla mutable ni snapshot persistido.

La verdad permanece en los hechos ya confirmados:

`PurchaseOrder ↔ PurchaseReceipt ↔ SupplierInvoice`

El reader agrega todas las recepciones confirmadas y todos los documentos registrados contra la orden.

## Contrato

1. El match no modifica orden, recepción, documento, obligación, Inventario, Caja ni cuentas financieras.
2. No existe tabla `purchase_three_way_matches`; no se persiste un estado que pueda quedar obsoleto.
3. Para cada línea de orden se exponen:
   - cantidad ordenada;
   - cantidad recibida acumulada;
   - cantidad documentada acumulada;
   - subtotal de orden;
   - subtotal recibido;
   - subtotal documentado;
   - deltas exactos entre las tres fuentes.
4. Las líneas documentales no vinculadas se muestran como diferencia explícita.
5. Logística y total se comparan también en las tres dimensiones.
6. El estado derivado es:
   - `missing_document`;
   - `pending_receipt`;
   - `exact`;
   - `different`.
7. `exact` exige coincidencia de cantidades, importes, logística y total, sin líneas documentales no vinculadas.
8. `different` es evidencia para revisión; no corrige ni bloquea silenciosamente una decisión humana.
9. Múltiples recepciones y múltiples documentos se agregan progresivamente.
10. Viewer, Operator y Admin pueden leer el match dentro de su organización porque ya poseen `canViewPurchases()`.
11. No se genera `PurchaseObligation` automática desde el match en P9.7b.
12. El pago continúa dependiendo de obligación y autorización explícitas.

## Fuera de alcance P9.7b

- aprobar o resolver diferencias;
- creación automática de obligación;
- notas de crédito del proveedor;
- anticipos;
- pago agrupado;
- impuestos/fiscalidad;
- OCR.

## Próximo corte

P9.7c debe abordar la próxima brecha real de CxP sin reescribir estas fuentes: anticipos del proveedor, notas de crédito y/o agrupación de pagos según la evidencia restante del roadmap.
