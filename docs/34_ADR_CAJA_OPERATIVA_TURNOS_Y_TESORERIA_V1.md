# ADR 34 — Caja operativa, turnos y tesorería V1

Estado: **Aceptado / P4A Foundation**

Checkpoint de partida:
`f0e9f58178900edfe90eb4d44047f0b44db2d4b0`
— `docs(roadmap): define SRCM full product vision`

## 1. Decisión

SRCM separa expresamente:

- `FinancialAccount`: cuenta/destino financiero;
- `CashRegister`: caja física/lógica operativa;
- `CashRegisterSession`: turno de caja de un usuario;
- futuros `CashMovement`: hechos append-only de efectivo;
- futuro arqueo/cierre: comparación entre efectivo esperado y contado.

Una cuenta financiera `cash_box` no es, por sí sola, un turno de caja.

## 2. Relación

`Usuario → Turno → Caja operativa → Cuenta financiera cash_box`

Ejemplo:

`Pablo → turno abierto → Caja 3 → Efectivo Caja 3 / ARS`

El destino efectivo de una venta futura se resolverá desde el turno abierto y no desde una preferencia permanente del usuario.

## 3. Fundación P4A

P4A incorpora:

1. cajas operativas privadas por organización;
2. vínculo uno-a-uno con cuenta financiera `cash_box`;
3. apertura de turno con fondo inicial;
4. idempotencia/fingerprint de apertura;
5. un solo turno abierto por caja;
6. un solo turno abierto por usuario;
7. permisos separados de la administración general de cuentas;
8. inmutabilidad de identidad y protección contra borrado;
9. auditoría de alta/cambio/apertura;
10. protección de la cuenta financiera cuando está vinculada a caja.

P4A todavía **no** incorpora movimientos de caja, retiro de seguridad, proveedor/flete, arqueo, cierre ni integración automática del efectivo en Terminal.

## 4. Autoridad inicial

- Administrador: configura cajas, opera cajas y supervisa.
- Operador: puede operar/abrir su turno.
- Consulta: no opera caja.

La autoridad se podrá granularizar por políticas/umbrales sin redefinir el dominio.

## 5. Invariantes

- una caja pertenece a una sola organización;
- una caja usa una cuenta `cash_box` de esa misma organización;
- la misma cuenta no alimenta dos cajas operativas;
- la cuenta debe estar activa al abrir un turno;
- una caja inactiva no abre turno;
- no se inactiva una caja con turno abierto;
- un usuario no abre dos turnos simultáneos;
- una caja no tiene dos turnos simultáneos;
- la apertura no puede tener fondo negativo;
- el retry con misma idempotency key y datos iguales devuelve el mismo hecho;
- retry con misma clave y datos distintos se rechaza;
- no hay borrado físico.

## 6. Próximo bloque

P4B debe incorporar el ledger append-only de efectivo y el contexto operacional:

`turno abierto + medio efectivo → financial_account_id de esa caja`

Luego P4C agregará retiros/transferencias/tesorería y P4D arqueo/cierre; pagos a proveedores/fletes se integrarán sobre el mismo motor de egresos autorizados sin confundir recepción física con pago.

## 7. P4B — operación, Terminal y libro de efectivo

P4B incorpora la primera superficie operativa completa:

- administración visual de `CashRegister`;
- apertura de turno con fondo inicial;
- contexto visible del turno en Terminal;
- Efectivo sólo disponible con turno abierto compatible;
- destino `financial_account_id` derivado de la caja del turno;
- el operador no elige manualmente otra cuenta para Efectivo;
- el backend rechaza un destino de efectivo forjado o desactualizado;
- medios electrónicos continúan funcionando sin turno de caja;
- cada `CommercePayment` en efectivo produce, dentro de la misma transacción de venta, un `CashMovement` append-only;
- el movimiento enlaza organización, turno, caja, cuenta financiera, pago, usuario, importe, moneda y tiempo;
- DB protege las relaciones y la inmutabilidad.

Cadena consolidada:

`Usuario → turno abierto → caja operativa → cash_box → cobro efectivo → CashMovement`

El fondo inicial sigue siendo baseline del turno, no ingreso comercial.

P4B todavía no implementa:
- cierre/arqueo;
- faltantes/sobrantes;
- retiro de seguridad;
- transferencia a caja fuerte/tesorería;
- devoluciones de efectivo;
- pago a proveedor/flete desde caja.

Esos hechos se incorporarán como nuevos tipos append-only sin reescribir cobros históricos.

## 8. Autoridad temporal operativa

La operación web normal no permite al cajero fechar manualmente una venta o
un cobro. El instante se fija al confirmar usando el reloj del servidor y se
almacena en UTC.

La interfaz muestra la hora mediante `app.display_timezone`, actualmente
`America/Argentina/Buenos_Aires`.

Esto separa:

- tiempo de almacenamiento: UTC;
- tiempo visible para la operación: zona configurada;
- autoridad del evento: servidor;
- importaciones/históricos futuros: flujo excepcional explícito, no el POS.

Una venta confirmada no puede convertirse en una operación retroactiva por
editar un `datetime-local`. Los flujos históricos deberán tener permiso,
motivo y trazabilidad propios.

## 9. P4C — retiros de seguridad y tesorería

P4C extiende `CashMovement` sin reescribir cobros P4B.

Se incorpora `security_drop` como hecho manual append-only:

`turno abierto → cash_box origen → retiro de seguridad → cash_reserve destino`

Reglas:

- el origen nunca lo elige el operador: deriva de su turno abierto;
- el destino debe ser una cuenta privada activa `cash_reserve` de la misma organización y moneda;
- el retiro debe ser mayor que cero y no puede superar el efectivo esperado del turno;
- el motivo es estructurado y la nota es opcional;
- el retry usa idempotency key + fingerprint;
- el movimiento es inmutable y no puede borrarse;
- `commerce_payment_id` queda nulo en retiros de seguridad;
- el retiro reduce el efectivo esperado del turno, pero no modifica ventas ni cobros históricos;
- el fondo inicial continúa siendo baseline, no un movimiento comercial;
- el retiro histórico P4C original permitía registro directo por Operador sobre su propio turno;
- el hardening P4C/P4D posterior reemplaza ese camino por solicitud, autorización y ejecución separadas;
- P4C no implementa pagos a proveedor/flete.

Se incorpora `FinancialAccountType::CashReserve` para representar caja fuerte,
tesorería o reserva física de efectivo sin confundirla con una caja operativa
`cash_box`.

Efectivo esperado del turno:

`fondo inicial + entradas CashMovement − salidas CashMovement`

P4D usará esta proyección como base del arqueo y cierre, sin corregir
silenciosamente diferencias.

## 10. P4D — arqueo, cierre y diferencias

P4D agrega un hecho histórico inmutable `CashRegisterClosure` por turno.

Flujo normal:

`turno open → conteo físico → closing_requested transitorio → cierre → closed`

Reglas:

- `expected_amount_minor` se congela desde `opening_amount_minor + entradas - salidas`;
- `counted_amount_minor` es declaración humana explícita;
- `difference_minor = counted_amount_minor - expected_amount_minor`;
- una diferencia distinta de cero exige motivo estructurado y nota;
- la diferencia no crea ningún `CashMovement` implícito;
- el cierre no modifica ventas, pagos ni retiros previos;
- la hora de cierre la fija el servidor;
- el operador sólo cierra su propio turno;
- supervisión puede revisar el historial de cierres de la organización;
- idempotency key + fingerprint protegen reintentos;
- el cierre y su evidencia no admiten update ni delete;
- el turno cerrado deja de aceptar movimientos de efectivo;
- una caja cerrada vuelve a quedar disponible para una apertura posterior.

La transición `closing_requested` se usa dentro de la transacción de cierre para
congelar el libro antes de calcular el arqueo. No es una corrección contable ni
un movimiento de caja.

P4D no implementa pagos a proveedor/flete, devoluciones de efectivo ni ajustes
automáticos de faltantes o sobrantes.

## 11. Hardening P4C/P4D — selector operativo y autorización de retiros

La GRAN PRUEBA manual detectó dos riesgos de superficie que se corrigen antes del
checkpoint definitivo:

1. `Retiro de seguridad` y `Arqueo y cierre` no deben presentarse como dos
   formularios sensibles simultáneamente activos;
2. un cajero/operador no debe poder decidir y ejecutar unilateralmente una
   extracción de efectivo.

La superficie del turno pasa a ser un **selector de operación**. El usuario elige
una operación y SRCM despliega sólo ese flujo. Por ahora:

- `Retiro de seguridad`;
- `Arqueo y cierre`.

Esto constituye el primer caso concreto de una futura superficie construida desde
módulos + capacidades + alcance organizacional.

### Retiro de seguridad autorizado

Flujo vinculante:

`solicitud → autorización administrativa → ejecución física`

Reglas:

- solicitar no crea `CashMovement` ni reduce el efectivo esperado;
- autorizar no crea `CashMovement` ni reduce el efectivo esperado;
- sólo ejecutar una autorización vigente crea exactamente un
  `CashMovement::security_drop`;
- `requested_by`, `approved_by` y `executed_by` se conservan por separado;
- el solicitante no puede autoautorizarse;
- en esta etapa autoriza un Administrador/supervisor;
- la ejecución corresponde al responsable del turno que hizo la solicitud;
- la autorización se vincula por fingerprint a turno, caja, origen, destino,
  importe, moneda, motivo y nota;
- cambiar cualquier dato autorizable exige una nueva solicitud;
- una autorización es de un solo uso;
- `pending`, `approved`, `executed`, `rejected`, `cancelled` y `expired` son
  estados explícitos del workflow;
- una solicitud pendiente o autorizada debe resolverse antes del cierre;
- la DB impide insertar un nuevo `security_drop` sin solicitud aprobada válida;
- el `security_drop` histórico ya registrado antes de este hardening se conserva
  intacto como evidencia real del desarrollo y no se reescribe.

La aprobación expresa autoridad. La ejecución expresa custodia física. Son hechos
diferentes y SRCM no los fusiona.

### Arqueo y cierre

P4D conserva sus invariantes:

`expected = opening + inflows - outflows`

`difference = counted - expected`

Una diferencia sigue siendo evidencia. Nunca se crea un movimiento compensatorio
para hacer cuadrar silenciosamente la caja.

La representación visual de diferencias usa código de moneda explícito, por
ejemplo `− ARS 500,00`, y no símbolos locales ambiguos como `-$`.

## 12. P4E — aplicado, entregado y vuelto

P4E agrega evidencia específica al cobro en efectivo sin redefinir la venta.

Verdades:

- `CommercePayment.amount_minor` continúa siendo el importe aplicado;
- `tendered_amount_minor` registra cuánto efectivo entrega el cliente;
- `change_amount_minor` registra el vuelto;
- en la foundation, `change = tendered - amount`;
- no efectivo no admite estos campos;
- cobros históricos sin captura conservan `NULL`;
- no se infiere vuelto cero como si hubiera sido observado;
- la Terminal presenta aplicado, entregado y vuelto antes de confirmar.

Para vuelto en efectivo del mismo medio, el efecto de caja es el efectivo neto
retenido. El cajero puede recibir físicamente más durante segundos, pero el
esperado del turno no se infla con dinero que se devuelve dentro del mismo acto.

Vuelto multimedio queda fuera de P4E Foundation: si el efectivo entregado se
devuelve por otra cuenta, SRCM necesitará hechos separados de ingreso bruto y
desembolso de vuelto para que cada cuenta refleje su realidad.

## 13. P4F — integración futura de pagos a proveedor/flete

P4F reutilizará la infraestructura de caja sin convertir `CashMovement` en una
cuenta por pagar.

Cadena:

`recepción → obligación → autorización → ejecución → movimiento financiero`

Para efectivo:

`ejecución válida + turno abierto → CashMovement out`

Reglas:

- crear una obligación no toca el esperado de caja;
- autorizar una obligación no toca el esperado de caja;
- sólo ejecutar el pago efectivo crea el egreso;
- un pago parcial crea efecto sólo por el importe ejecutado;
- el beneficiario se conserva estructurado y puede diferir del proveedor;
- mercadería y flete pueden originar obligaciones separadas;
- el origen deriva de una cuenta/caja válida y de la autoridad del actor;
- banco/billetera no crean `CashMovement`; su débito externo se verifica y
  concilia mediante el motor financiero;
- Operational Attention distribuye solicitud, aprobación, ejecución y resultado
  sin convertirse en segunda verdad del pago.

ADR rector para P4E/P4F y evolución P8/P9:
`docs/35_ADR_HECHOS_MONETARIOS_APLICADO_ENTREGADO_VUELTO_OBLIGACION_DESEMBOLSO_V1.md`.
