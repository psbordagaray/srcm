# ADR 13 — Trabajo propio, tercerización y custodia

Fecha: 02/08/2026

Estado: aceptada por Dirección

Checkpoint de partida:

`2423b3b83210322f3b6333872f88c31261fa4cbf`

## 1. Contexto

Una reparación puede ejecutarse íntegramente dentro del comercio o derivarse
a un especialista. En SULU, por ejemplo, un colega puede reparar notebooks,
otro placas madre y otro impresoras. El comercio mantiene la relación con el
cliente, pero necesita conservar quién hizo cada trabajo, cuándo recibió el
equipo, cómo lo devolvió y qué garantía respalda el resultado.

La tercerización no debe confundirse con una compra de repuestos ni ocultar la
cadena de custodia. Tampoco debe permitir que un trabajo ajeno al presupuesto
aprobado se incorpore silenciosamente a la orden.

## 2. Decisión

Cada tarea aprobada se representa como un trabajo inmutable con:

- orden y alternativa de presupuesto de origen;
- secuencia, título y descripción del alcance;
- modalidad propia o tercerizada;
- usuario interno responsable o especialista externo, nunca ambos;
- estado actual, historia auditable e idempotencia.

Sólo pueden planificarse trabajos cuando la orden está en ejecución y la
alternativa referida posee una decisión aprobatoria del cliente.

## 3. Ejecución interna

El trabajo propio identifica obligatoriamente a un miembro activo de la
organización. Su recorrido es:

1. `planned`;
2. `in_progress`;
3. `completed` o `unresolved`.

El alcance no puede editarse después de creado. Una corrección futura deberá
agregar un nuevo hecho, no reescribir el original.

## 4. Ejecución tercerizada

El trabajo externo identifica una contraparte privada de la organización. Su
recorrido agrega el estado `with_provider` y exige dos hechos de custodia:

- entrega al especialista (`dispatch`), respaldada por un evento
  `transferred`;
- retorno a la organización (`return`), respaldado por un evento `returned`.

La salida sólo es válida si la organización posee la custodia actual. El
retorno sólo es válido para el mismo trabajo derivado y restituye la custodia
a la ubicación de ingreso de la orden.

Mientras el activo está con el especialista, la orden pasa a
`with_external_provider`. Al retornar vuelve a `in_progress`. Ambas
transiciones requieren un asiento histórico coincidente.

## 5. Resultado técnico y garantía

Cada trabajo admite un único resultado inmutable. El resultado conserva:

- conclusión resumida;
- trabajo efectivamente realizado;
- usuario que lo registró y fecha;
- motivo concreto cuando quedó sin solución;
- días y condiciones de garantía cuando corresponde.

Un resultado sin solución no puede otorgar garantía. Una garantía positiva
debe incluir condiciones. La atribución del especialista permanece en el
trabajo, mientras que el usuario que verificó y registró el resultado queda en
el informe.

Si todos los trabajos terminan correctamente, la orden pasa a
`quality_control`. Si el único frente pendiente termina sin solución, retorna
a `diagnosing` para permitir un nuevo diagnóstico y presupuesto.

## 6. Seguridad e integridad

Administradores y operadores activos pueden planificar, ejecutar y transferir
custodia. El rol de consulta no puede modificar el circuito.

Las claves foráneas compuestas impiden mezclar organizaciones. Los triggers de
SQLite y MySQL rechazan:

- trabajos fuera de la alternativa aprobada;
- asignaciones incompatibles con la modalidad;
- cambios de alcance o estado sin historia;
- vínculos de custodia que no coinciden con la orden, dirección y estado;
- resultados incoherentes con su resultado o garantía;
- actualización o eliminación de historia, custodia o informes.

## 7. Consecuencias

Queda trazable si una reparación fue propia o realizada por un colega, cuándo
salió y volvió el equipo, cuál fue el resultado y quién respalda la garantía.

Este bloque todavía no incorpora pantallas HTTP, compras o repuestos afectados,
control de calidad operativo, entrega al cliente, facturación ni visibilidad
entre organizaciones de una red de colegas. Esos comportamientos se apoyarán
en los hechos preservados aquí.
