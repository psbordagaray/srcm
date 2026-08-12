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
