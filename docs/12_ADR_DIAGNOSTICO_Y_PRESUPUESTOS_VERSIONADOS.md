# ADR 12 — Diagnóstico y presupuestos versionados

Fecha: 02/08/2026

Estado: aceptada por Dirección

Checkpoint de partida:

`12b6132fc23f7a6c6dd27cbace83c318d853bb60`

## 1. Contexto

Una orden puede descubrir fallas que no estaban declaradas al ingresar. El
caso de una notebook lenta puede revelar un disco degradado y un teclado que
requiere reemplazo; un teléfono recibido por pantalla rota puede requerir
además saneamiento de software. Esos hallazgos cambian el alcance y el precio,
pero no deben reescribir el relato inicial.

El cliente también puede elegir entre alternativas: reparar sólo lo
indispensable, aprovechar un mismo desarme para una solución integral o
rechazar el presupuesto y solicitar otra propuesta.

## 2. Decisión

Se incorporan cuatro hechos separados y acumulativos:

1. diagnóstico técnico;
2. hallazgos que sustentan ese diagnóstico;
3. presupuesto con una o más alternativas completas;
4. decisión del cliente sobre una revisión concreta.

Todos son privados por organización, transaccionales, idempotentes e
inmutables después de registrarse.

## 3. Diagnóstico acumulativo

El diagnóstico tiene revisión secuencial dentro de la orden. Cada revisión
conserva:

- resumen técnico;
- recomendación;
- riesgos sobre datos;
- técnico responsable y fecha;
- lista ordenada de hallazgos;
- severidad, categoría, descripción y evidencia de cada hallazgo.

Una nueva revisión no reemplaza la anterior. El primer diagnóstico lleva la
orden de `received` a `diagnosing` y registra esa transición en la historia.

## 4. Presupuesto y alternativas

Cada presupuesto referencia el diagnóstico más reciente y recibe su propia
revisión. Una revisión contiene una o más alternativas; cada alternativa es
un paquete completo que el cliente puede aceptar.

Las líneas diferencian:

- mano de obra;
- repuesto;
- logística;
- servicio sobre datos;
- servicio externo;
- otro concepto.

Esto evita confundir, por ejemplo, un SSD comprado para la orden con la mano
de obra propia o con el costo de un especialista.

## 5. Importes exactos

Los importes monetarios se almacenan como unidades menores enteras. Para ARS,
un valor de `1500000` representa $15.000,00. La cantidad admite hasta seis
decimales, pero cantidad por precio unitario debe producir una cantidad exacta
de centavos. No se redondean fracciones silenciosamente.

El total de cada alternativa es la suma exacta y persistida de sus líneas.

## 6. Aprobación y rechazo

La aprobación identifica obligatoriamente una alternativa de la revisión. El
rechazo no selecciona alternativa y requiere motivo. Ambos conservan:

- nombre y referencia del cliente;
- canal de comunicación;
- fecha;
- usuario que registró la decisión;
- motivo o constancia;
- clave de idempotencia y huella de contenido.

Sólo puede decidirse sobre la revisión más reciente y cada presupuesto admite
una única decisión. Una aprobación lleva la orden a `in_progress`; un rechazo
la devuelve a `diagnosing`, desde donde puede emitirse otra revisión.

## 7. Transiciones auditables

El estado actual y la historia se escriben en una misma transacción. La base
de datos rechaza una transición si:

- el par origen/destino no está permitido;
- no existe un asiento histórico coincidente;
- el asiento no es el último de la orden;
- se intenta alterar cualquier otro atributo inmutable de la orden.

En este bloque se habilitan únicamente:

- `received` → `diagnosing`;
- `diagnosing` → `awaiting_approval`;
- `awaiting_approval` → `in_progress`;
- `awaiting_approval` → `diagnosing`.

Las etapas posteriores ampliarán la máquina de estados sin debilitar estas
garantías.

## 8. Seguridad y límites

Administradores y operadores activos pueden diagnosticar, presupuestar y
registrar decisiones. El rol de consulta no puede modificar el circuito.

Las relaciones usan organización y entidad como clave compuesta. No puede
presupuestarse un diagnóstico de otra orden ni aprobarse una alternativa de
otro presupuesto, incluso mediante una escritura SQL directa.

## 9. Consecuencias

Este bloque deja lista la base para:

- tareas propias y tercerizadas;
- compras y repuestos afectados;
- cambios de alcance posteriores;
- control de calidad y garantía;
- facturación mixta con trazabilidad antifraude.

Todavía no incorpora pantallas HTTP, ejecución de tareas, movimientos de
custodia externos, compras ni cobros. Esos comportamientos se construirán
sobre las revisiones y decisiones aquí preservadas.
