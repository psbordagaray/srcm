# ADR 15 — Control de calidad, entrega y garantía

Fecha: 03/08/2026

Estado: aceptada por Dirección

Checkpoint de partida:

`4f4311a6ca4521c19ba5b1be04c257dba169eeb3`

## 1. Contexto

Completar un trabajo técnico no equivale a poder entregar el activo. Antes de
devolverlo deben comprobarse funcionamiento, estado físico y accesorios. Si
alguna prueba falla, el activo continúa dentro del circuito de reparación. Si
aprueba, la entrega debe transferir custodia a una persona identificable y
materializar la garantía atribuible a cada trabajo realizado.

La cobranza, la factura y los artículos agregados en mostrador pertenecen al
circuito comercial. Este bloque preserva el hecho técnico y físico de la
entrega sin confundirlo con la venta.

## 2. Control de calidad

Cada inspección conserva una revisión inmutable con:

- checklist estructurado, ordenado y con códigos únicos;
- cantidad total y cantidad de comprobaciones fallidas;
- condición final y accesorios presentes;
- motivo obligatorio cuando se requiere retrabajo;
- notas, actor, fecha, idempotencia y huella canónica.

El resultado no se elige libremente: se deriva del checklist. Cero fallos
aprueba la inspección; cualquier fallo exige retrabajo. Sólo puede inspeccionarse
una orden en `quality_control`, con custodia de la organización y todos sus
trabajos completados.

## 3. Retrabajo

Una inspección rechazada devuelve la orden a `in_progress`. El fallo queda
preservado y permite planificar un nuevo trabajo dentro del alcance aprobado.
La orden deberá completar nuevamente sus trabajos y atravesar una nueva
revisión de calidad antes de quedar lista para entregar.

## 4. Aprobación

Una inspección aprobada lleva la orden a `ready_for_delivery`. Esta transición
requiere la última inspección aprobada tanto en el dominio como en la base de
datos. No se puede omitir el control mediante una actualización directa del
estado.

## 5. Entrega y custodia

La entrega requiere:

- última inspección aprobada;
- custodia actual de la organización;
- receptor nominal y, cuando corresponda, parte comercial o documento;
- condición y accesorios efectivamente entregados;
- conformidad del receptor o una observación obligatoria;
- actor, fecha, idempotencia y huella canónica.

Se crea un evento de custodia `delivered` desde la organización hacia el
receptor y luego la orden pasa a `delivered`. La entrega es única e inmutable.

## 6. Garantía atribuible

Al entregar se crea una garantía por cada resultado técnico completado que
haya otorgado plazo. Cada garantía conserva:

- trabajo y resultado que la originan;
- días y términos exactos declarados por quien ejecutó el trabajo;
- inicio en la fecha de entrega y vencimiento calculado;
- vínculo con la entrega y huella de integridad.

Así, una orden con mano de obra propia y tareas tercerizadas puede tener
coberturas diferentes sin ocultar quién realizó cada intervención.

## 7. Integridad y seguridad

Administradores y operadores activos pueden inspeccionar y entregar. Consulta
no puede modificar el circuito. Las claves foráneas compuestas, transiciones
con historia y triggers de SQLite/MySQL rechazan:

- inspecciones fuera del estado correcto o con trabajos pendientes;
- resultados que no coinciden con el conteo de pruebas;
- entregas sin última aprobación, sin custodia o sin observación ante rechazo;
- garantías ajenas a la orden, diferentes del resultado técnico o sin plazo;
- actualización o eliminación de inspecciones, entregas y garantías;
- cambios de estado sin la evidencia técnica correspondiente.

## 8. Consecuencias

SRCM puede demostrar qué se probó, qué falló, por qué volvió a reparación,
quién aprobó la salida, a quién se entregó, en qué condiciones y qué garantía
corresponde a cada trabajo.

Este bloque no cobra ni factura. La venta mixta, la conciliación entre servicio
y artículos, los medios de pago y los controles antifraude se incorporarán en
Reparaciones Core 6.
