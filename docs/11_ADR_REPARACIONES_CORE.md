# ADR 11 — Reparaciones Core y orden de servicio genérica

Fecha: 02/08/2026

Estado: aceptada por Dirección

Origen: casos reales de SULU TV y red de colegas relevados entre el 01 y el
02/08/2026

Checkpoint de partida:

`983a13d11787a9e36779ee14827d6a9f770b3fff`

## 1. Contexto

SRCM debe administrar reparaciones propias y tercerizadas sin quedar atado a
un rubro. El mismo núcleo debe servir para celulares, computadoras,
televisores, impresoras, electrodomésticos y vehículos.

Una reparación puede descubrir hechos que el cliente desconocía, requerir un
nuevo presupuesto, consumir repuestos comprados específicamente, pasar por uno
o más especialistas y terminar junto con una venta de productos. La evidencia
técnica, económica y de custodia no puede reducirse a una observación libre ni
sobrescribirse al avanzar la orden.

## 2. Decisión

Se incorpora una orden de servicio genérica como agregado principal. La orden
no es una venta, una compra ni un movimiento de inventario, aunque más adelante
pueda relacionarse con todos ellos.

El primer bloque crea:

- activo de servicio;
- identificadores técnicos privados por organización;
- orden numerada por organización;
- fotografía inmutable del ingreso;
- historia acumulativa de estados;
- historia acumulativa de custodia;
- creación transaccional e idempotente;
- límites de organización y permisos explícitos.

## 3. Activo e identificadores

El activo representa el objeto que recibe servicio. Admite inicialmente:

- celular;
- tablet;
- notebook;
- computadora de escritorio;
- televisor;
- monitor;
- impresora;
- vehículo;
- electrodoméstico;
- otro activo.

Puede identificarse por IMEI, número de serie, código interno, VIN, patente,
número de motor, número de chasis u otro identificador.

Los identificadores se normalizan para evitar duplicados de formato. Una misma
organización no puede asignar el mismo tipo y valor normalizado a dos activos.
Su valor histórico no se edita ni se elimina. La eventual corrección de una
identidad deberá ser un acto trazable, no una reescritura silenciosa.

## 4. Relato declarado y observación técnica

La orden separa desde el ingreso:

- lo que declara el cliente;
- lo que observa quien recibe;
- accesorios entregados;
- apariencia y condición;
- identidad del activo;
- identidad de quien entrega y del propietario declarado;
- disponibilidad o ausencia de contacto.

Así, por ejemplo, «pantalla original» puede quedar en el relato del cliente y
«módulo sin adhesivo, aparentemente reemplazado» en la observación técnica sin
que un hecho borre al otro.

La fotografía del ingreso es inmutable. Los hallazgos posteriores se agregarán
como diagnósticos y evidencias en bloques siguientes.

## 5. Cliente, propietario y contacto

Quien entrega el activo puede no ser su propietario. SRCM conserva ambas
referencias cuando existen y admite una identidad declarada cuando todavía no
hay una ficha formal de cliente.

También admite explícitamente un ingreso sin teléfono u otro medio de contacto.
Si se declara que existe contacto, el medio debe registrarse.

## 6. Custodia

La recepción crea un evento de custodia en la misma transacción que la orden.
El evento indica desde quién, hacia quién, dónde, cuándo, en qué condiciones y
con qué accesorios se transfirió el activo.

Los eventos futuros cubrirán entrega a un colega, retorno, control de calidad y
devolución al cliente. La custodia es acumulativa: un evento no reemplaza al
anterior y no puede editarse ni borrarse.

## 7. Estados iniciales

El vocabulario de la orden contempla:

- recibida;
- en diagnóstico;
- esperando aprobación;
- esperando repuestos;
- en reparación;
- con prestador externo;
- en control de calidad;
- lista para entregar;
- entregada;
- cancelada.

El primer bloque sólo crea la orden en estado `received`. Las transiciones se
incorporarán mediante un servicio de dominio que escriba estado e historia de
forma atómica.

## 8. Idempotencia y numeración

Cada ingreso usa una clave de idempotencia privada por organización y una
huella canónica de sus datos. Repetir exactamente el mismo comando devuelve la
misma orden. Reutilizar la clave con datos diferentes se rechaza.

El número visible de orden es secuencial dentro de cada organización. La
creación bloquea la organización para impedir que dos ingresos concurrentes
reciban el mismo número.

## 9. Límites de organización

Activos, identificadores, órdenes y registros históricos son privados por
organización. Las relaciones críticas usan claves foráneas compuestas para que
un cliente, activo o ubicación de otra organización no pueda asociarse ni por
error ni mediante SQL directo.

Compartir antecedentes técnicos entre colegas no significa compartir la base
privada. El futuro pasaporte técnico de red expondrá solamente afirmaciones
autorizadas, con consentimiento, procedencia y reglas de privacidad explícitas.

## 10. Tercerización y red de colegas

Una orden podrá contener trabajos internos y externos. Cada derivación futura
deberá registrar prestador, fechas, custodia, diagnóstico, costo, resultado y
garantía. Esto cubre los casos reales de Jorge, Horacio, Federico, Checho y
otros especialistas, así como radiadores, alternadores, frenos u otros
subtrabajos de un vehículo.

El cliente de SULU TV mantiene una relación con SULU TV aunque parte del trabajo
sea ejecutada externamente. La atribución interna no se confunde con lo que se
comunica o factura al cliente.

## 11. Diagnóstico y represupuesto

Un hallazgo posterior no modifica el pedido original. Agrega:

- diagnóstico;
- recomendación;
- alternativas;
- presupuesto o cambio de alcance;
- aprobación o rechazo del cliente;
- fecha, actor y medio de consentimiento.

No se inicia un trabajo adicional facturable sin esa decisión, salvo una regla
de emergencia explícita todavía no incorporada.

## 12. Compras, repuestos y costos

Los repuestos pueden:

- salir del stock propio;
- comprarse específicamente para una orden;
- ser provistos por un colega;
- incluir logística o mensajería;
- no ingresar nunca al stock general porque pasan directamente al activo.

La compra afectada a una orden conservará proveedor, costo, cantidad, lote o
serie cuando corresponda y garantía del repuesto. Mano de obra, repuesto y
logística son conceptos distintos aunque integren un mismo presupuesto.

## 13. Entrega, cobro y venta mixta

La entrega técnica y la venta son eventos relacionados pero diferentes. Una
misma operación de cobro podrá incluir:

- servicios realizados;
- repuestos instalados;
- productos adicionales, como funda, vidrio, teclado o auriculares;
- anticipos, saldos y medios de pago.

La orden explica qué se hizo. La venta explica qué se cobró. La entrega explica
qué activo y accesorios cambiaron de custodia.

## 14. Prevención de fraude

No se permitirá sustituir silenciosamente un servicio por un producto para
apropiarse del efectivo o del artículo. El diseño futuro exigirá:

- cada línea de cobro vinculada a su origen real;
- salida física de un producto respaldada por inventario y comprobante;
- servicio respaldado por orden, trabajo y entrega;
- cambios, anulaciones y descuentos con actor, motivo y autorización;
- conciliación entre órdenes cerradas, ventas, caja y movimientos;
- alertas por productos retirados sin cliente o por servicios entregados sin
  línea económica compatible.

## 15. Incorporaciones posteriores del Core

Sobre esta base se construirán, sin cambiar el significado del ingreso:

1. diagnóstico, evidencias y presupuestos versionados;
2. aprobación y cambio de alcance;
3. tareas internas, derivaciones externas y custodia extendida;
4. compras y repuestos afectados a la orden;
5. control de calidad, entrega y garantías;
6. venta mixta y conciliación antifraude;
7. toma de usados y parte de pago;
8. pasaporte técnico compartido con privacidad y consentimiento.

Las nuevas ideas funcionales quedan en backlog para una etapa posterior, salvo
que revelen una falla crítica de seguridad o integridad.
