# ADR 54 — Physical Post-Sale Return Receipt V1

Estado: Aceptada para P8.2

Checkpoint de partida:
`faea48006f6493cc1810db7fea4741cfe40860c5`

## 1. Contexto

P8.1 registra una solicitud append-only de devolución o cambio, vinculada
a la venta original y a cantidades solicitadas por línea.

La solicitud no prueba que la mercadería haya vuelto físicamente.

P8.2 debe transformar exclusivamente una **recepción física confirmada**
en verdad de inventario.

## 2. Decisión

Se agregan:

- `CommercePostSaleReceipt`;
- `CommercePostSaleReceiptLine`;
- `CommercePostSaleReceiptManager`.

Cada recepción:

- pertenece a una solicitud P8.1;
- registra actor y hora de servidor;
- registra la cantidad efectivamente recibida;
- registra la condición física real;
- registra la ubicación activa de destino;
- crea y confirma exactamente un
  `InventoryMovementType::CustomerReturn`;
- enlaza cada línea comercial con la línea exacta del movimiento de inventario.

No existe un segundo ledger de stock.

## 3. Condición real

La condición de venta original no se reutiliza silenciosamente.

Cada línea recibida exige una `InventoryCondition` explícita:

- nuevo;
- usado;
- reacondicionado;
- dañado o para reparar;
- exhibición o demostración.

La proyección de saldo usa esa condición confirmada.

## 4. Recepciones parciales y acumuladas

Una solicitud puede recibirse en más de un hecho físico.

La suma confirmada de recepciones de una misma línea nunca puede superar
la cantidad solicitada en P8.1.

El límite se controla en dominio y nuevamente en DB.

## 5. Atomicidad

La recepción se ejecuta en una transacción exterior:

1. bloquea solicitud, líneas y recepciones previas;
2. valida cantidad acumulada y ubicaciones;
3. crea `CustomerReturn` con el ledger existente;
4. confirma el movimiento;
5. persiste el hecho comercial de recepción y su vínculo línea a línea;
6. audita.

Si cualquier paso falla, no queda una recepción comercial sin stock ni un
CustomerReturn huérfano.

## 6. Idempotencia

La recepción posee clave idempotente privada por organización y fingerprint.

El movimiento de inventario utiliza una clave derivada por SHA-256 para
mantener el límite del ledger y conservar la misma identidad lógica.

Repetir el mismo contenido devuelve el mismo hecho.
Reutilizar la clave con otro contenido falla cerrado.

## 7. Integridad DB

Los triggers verifican:

- solicitud y organización;
- membresía activa del receptor;
- `CustomerReturn` confirmado;
- source type/id exactos;
- mismo actor de creación y confirmación;
- línea de solicitud y producto originales;
- línea exacta del movimiento;
- condición y ubicación de destino;
- cantidad exacta y límite acumulado;
- inmutabilidad y no borrado.

## 8. Sin efecto monetario

P8.2 no:

- reembolsa;
- crea saldo a favor;
- revierte un pago;
- modifica conciliaciones;
- calcula diferencia de precio;
- modifica la venta original.

La resolución comercial/monetaria queda para P8.3.

## 9. Próximo corte

P8.3 debe resolver de forma explícita el resultado económico/comercial de la
posventa usando la venta original y las recepciones físicas confirmadas como
evidencia, sin reescribir ninguna de ellas.
