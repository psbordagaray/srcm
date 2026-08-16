# ADR 53 — Commercial Post-Sale Request Foundation V1

Estado: Aceptada para P8.1

Checkpoint de partida:
`10e52fd16b416950659cfe9816d792853c7f69cc`

## 1. Contexto

P7 quedó cerrado con importación CSV/XLSX, normalización, mapeo,
idempotencia, conciliación común y fallback manual auditable.

El roadmap maestro define P8 — Posventa comercial completa:

- devoluciones parciales/totales;
- cambios;
- reembolsos;
- crédito/saldo a favor;
- devolución al medio original cuando corresponda;
- diferencia de precio;
- devolución de stock con condición real;
- trazabilidad a venta original;
- nunca editar retrospectivamente la venta original;
- futura integración con notas de crédito fiscales.

## 2. Decisión P8.1

P8.1 separa la solicitud del cliente de los efectos físicos y monetarios.

Se agregan hechos append-only:

- `CommercePostSaleRequest`;
- `CommercePostSaleRequestLine`.

Una solicitud referencia una venta confirmada original y una o más líneas
de producto de esa venta.

Intenciones iniciales:

- `return`;
- `exchange`.

Se admiten cantidades parciales o totales, pero cada cantidad solicitada
individualmente nunca puede superar la cantidad vendida en la línea original.

## 3. Lo que P8.1 deliberadamente NO hace

Registrar una solicitud no:

- modifica `CommerceSale`;
- modifica `CommerceSaleLine`;
- crea `InventoryMovement`;
- devuelve stock;
- decide condición física;
- crea reembolso;
- crea saldo a favor;
- revierte `CommercePayment`;
- cambia conciliaciones;
- calcula diferencia de precio;
- crea nota de crédito fiscal.

Esos efectos requieren hechos posteriores y explícitos.

## 4. Múltiples solicitudes

P8.1 no interpreta una solicitud como devolución cumplida.

El límite acumulado definitivo se aplicará sobre recepciones y resoluciones
confirmadas, no sobre intenciones todavía no ejecutadas.

## 5. Autoridad e idempotencia

Administrador y Operador pueden registrar la solicitud.
Consulta puede leer el historial, pero no crearla.

Cada solicitud posee organización, venta original, intención, motivo,
nota opcional, actor, hora de servidor, clave idempotente y fingerprint.

Repetir la misma clave y contenido devuelve el mismo hecho.
Reutilizar la clave con otro contenido falla cerrado.

## 6. Integridad

La base de datos valida:

- venta confirmada y de la misma organización;
- actor con membresía activa;
- intención permitida;
- línea de producto perteneciente a la venta;
- cantidad mayor que cero y no superior a la vendida;
- inmutabilidad y prohibición de borrado de solicitudes y líneas.

La venta original continúa siendo evidencia histórica inmutable.

## 7. Próximos cortes

P8.2 debe materializar la recepción física de la devolución, incluyendo
cantidad efectivamente recibida y condición real, y recién allí generar
`InventoryMovementType::CustomerReturn` cuando corresponda.

P8.3 podrá resolver el resultado comercial/monetario: reembolso, saldo a
favor, medio original, cambio y diferencia de precio.

La integración fiscal continuará separada hasta P10.
