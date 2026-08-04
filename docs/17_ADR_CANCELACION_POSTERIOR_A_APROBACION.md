# ADR 17 — Cancelación posterior a una aprobación

## Estado

Aceptado para SRCM V1.0.

## Contexto

Una aprobación de presupuesto no vuelve irrevocable una reparación. El cliente
puede desistir porque recibió otro equipo o porque una nueva fecha de entrega ya
no le resulta útil. La aprobación original sigue siendo un hecho verdadero y no
debe editarse ni eliminarse.

Una cancelación simple tampoco alcanza: el comercio puede haber iniciado
trabajos, comprado o consumido repuestos, entregado el equipo a un especialista
o registrado dinero. Además, cancelar comercialmente no significa que el equipo
haya salido físicamente de la custodia del taller.

## Decisión

La cancelación se divide en tres hechos inmutables:

1. **Solicitud:** registra quién revoca, motivo, canal y una fotografía automática
   de trabajos, repuestos, pagos y custodia. La orden pasa a `cancellation_pending`
   y se detienen los trabajos locales no terminados.
2. **Resolución:** un Administrador declara qué sucedió con trabajo, repuestos y
   dinero. Sólo puede registrarse cuando no quedan tareas activas y el equipo está
   nuevamente bajo custodia de la organización. La orden pasa a
   `ready_for_return`.
3. **Devolución:** registra receptor, condición, accesorios y evento de custodia.
   Recién entonces la orden pasa a `cancelled`, cuyo significado visible es
   “Cancelada y devuelta”.

La decisión aprobatoria previa permanece intacta. La cancelación posterior se
agrega cronológicamente al expediente.

## Motivos tipificados

- desistimiento del cliente;
- recibió otro equipo;
- rechazó una nueva fecha prometida;
- repuesto no disponible;
- imposibilidad técnica;
- decisión del comercio;
- otro motivo explicado.

## Resolución económica

Se admiten tres resultados:

- sin cargo;
- cargo acordado con el cliente, con importe y referencia de aceptación;
- costos absorbidos por el comercio.

El núcleo no crea ni altera cobros. Si ya existe una venta comercial, la
cancelación se bloquea hasta disponer de un flujo específico de reversión.

## Trabajo y custodia externa

Los trabajos planificados o internos en ejecución pasan a `cancelled`, pero no
se borran. Si el equipo está con un especialista, la resolución queda bloqueada
hasta registrar su retorno mediante la cadena de custodia; entonces el trabajo
externo también queda cancelado.

## Seguridad APB

- Un operador puede registrar lo solicitado por el cliente.
- Sólo un Administrador puede resolver compromisos y cargos.
- Un operador autorizado puede efectuar la devolución física.
- Solicitud, resolución y devolución son inmutables en aplicación y base.
- Las transiciones de orden y trabajo requieren la evidencia previa correspondiente.
- No se eliminan aprobaciones, compras, consumos, trabajos, pagos ni eventos de
  custodia para simular una cancelación limpia.

## Consecuencias

SRCM distingue con precisión una reparación aprobada y luego revocada de una
reparación nunca autorizada. También diferencia una cancelación pendiente de la
devolución efectiva del activo, evitando que un equipo desaparezca del circuito
operativo mientras todavía se encuentra en el taller o con un colega.
