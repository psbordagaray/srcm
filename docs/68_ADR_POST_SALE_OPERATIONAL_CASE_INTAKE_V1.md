# ADR 68 — Post-Sale Operational Case & Intake V1

Estado: Aceptada para P8.5.1

Checkpoint de partida:
`163e8d97327e430d0e89131263b4bcaf63837839`

## 1. Contexto

P8.1–P8.4 construyeron y protegieron el dominio de posventa:

- solicitud;
- recepción física;
- resolución económica;
- saldo a favor;
- reembolso en caja;
- reembolso externo;
- cambio con selección, diferencia y ejecución.

Hasta este punto la superficie web propia de posventa no estaba materializada.
Los Gates `view-commerce-post-sale` y `record-commerce-post-sale` ya existían,
pero `routes/web.php` sólo exponía la operación ordinaria de ventas.

## 2. Decisión P8.5

P8.5 abre la capa operativa de posventa sin colapsar los hechos separados del
dominio.

P8.5.1 agrega exclusivamente:

1. listado tenant-safe de expedientes;
2. detalle read-only del expediente y su evidencia acumulada;
3. inicio de una solicitud desde una venta confirmada;
4. acceso desde la venta original y navegación;
5. validación HTTP tenant-safe;
6. reutilización íntegra de `CommercePostSaleRequestManager`.

## 3. No existe un estado mágico de expediente

El read surface no crea una nueva tabla ni un agregado mutable de “estado”.

Presenta los hechos existentes:

- cantidad de recepciones;
- cantidad de resoluciones;
- materialización observable de cada outcome.

Esto evita crear una segunda verdad que pueda divergir de los hechos
append-only.

## 4. Intake

La solicitud se registra únicamente contra una venta confirmada de la
organización activa.

El formulario sólo permite seleccionar líneas `product` de la venta original.

La validación HTTP limita forma y pertenencia tenant; las reglas de dominio
siguen siendo autoridad de `CommercePostSaleRequestManager`, incluyendo:

- venta confirmada;
- cantidad positiva;
- cantidad no superior a la venta;
- idempotencia;
- fingerprint;
- actor y organización.

## 5. Seguridad tenant

Todos los endpoints viven dentro de:

- `auth`;
- `verified`;
- `RequireOrganization`.

Lectura usa `view-commerce-post-sale`.

Alta usa `record-commerce-post-sale`.

Los route models se revalidan contra `CurrentOrganization` y el `FormRequest`
aborta 404 ante una venta de otra organización antes de validar líneas.

## 6. Superficie read-only

El detalle muestra de manera descriptiva:

- solicitud;
- líneas solicitadas;
- recepciones físicas confirmadas;
- resoluciones económicas;
- materialización observable de outcomes.

No crea ni modifica hechos P8.2–P8.4.

## 7. No objetivos P8.5.1

Este corte no expone todavía acciones HTTP para:

- confirmar recepción física;
- resolver valor reconocido;
- materializar customer credit;
- ejecutar reembolso en caja;
- instruir/despachar reembolso externo;
- seleccionar reemplazo;
- ejecutar el cambio;
- consumir saldo a favor.

Tampoco migra la BD real.

## 8. Continuidad

Los siguientes cortes P8.5 deben agregar acciones operativas una por una,
conservando exactamente las segregaciones de autoridad ya impuestas por el
dominio.

La recepción física debe ser la próxima acción, porque es el primer hecho
posterior al intake y habilita la resolución económica sin permitir saltos de
etapa.
