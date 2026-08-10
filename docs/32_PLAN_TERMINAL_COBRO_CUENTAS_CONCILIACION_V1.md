# SRCM V1 — Terminal de Cobro APB, Cuentas y Conciliación

Estado: **Contrato funcional aprobado / implementación por bloques pendiente**

Fecha de consolidación: **2026-08-10**

Checkpoint oficial de código sobre el que se desarrolló el hardening UX:
`ce62ecccea5fca630a990acc1520e09d95a48851`
— `feat(commerce): add private pricing and harden sale authority`

Este documento existe también como **documento de continuidad**: si una conversación se interrumpe, se cuelga o debe continuarse en otro chat, debe leerse antes de rediseñar Venta, Cobro, Cuentas, conciliación o integraciones financieras.

---

## 1. Propósito

SRCM no debe limitarse a “registrar una venta”.

La culminación comercial debe ser rápida para el operador pero difícil de ejecutar mal. El sistema debe distinguir qué se vendió, qué medio dijo recibir el operador, a qué cuenta o instrumento pertenece ese cobro y qué evidencia posterior confirma que el dinero realmente fue acreditado.

Principio operativo:

> **Automatizar lo inequívoco; preguntar lo ambiguo; bloquear lo peligroso; nunca corregir silenciosamente una decisión humana.**

La Terminal de Cobro será la interfaz operativa de ese principio.

---

## 2. Verdades que SRCM debe mantener separadas

Nunca mezclar estas etapas como si fueran el mismo hecho:

1. **Venta** — productos/servicios, cantidades, precio autorizado, total, cliente y contexto.
2. **Cobro declarado** — medio elegido explícitamente, importe declarado y evidencia disponible en ese momento.
3. **Operación externa** — ID y estado devueltos por Mercado Pago, Payway, banco, adquirente u otro proveedor.
4. **Acreditación** — dinero efectivamente acreditado, bruto/neto, fecha, comisiones/retenciones.
5. **Conciliación** — vínculo verificable entre cobro esperado y movimiento/acreditación, coincidencia o diferencia, revisión y evidencia.

Una declaración del cajero de “pagó por transferencia” **no equivale** a una acreditación bancaria verificada.

---

## 3. Venta y Cobro: una sola transacción, dos superficies visuales

### 3.1 Venta

La pantalla Venta conserva compositor de artículos, búsqueda/lookup, cantidad, ubicación/condición, carrito, cliente/referencia y contexto comercial.

**Pagos deja de presentarse como una sección común dentro del formulario.**

### 3.2 Terminal de Cobro

Cobro se abre como una ventana superpuesta de alto protagonismo: Venta queda visible detrás, el fondo se oscurece, no se manipula Venta mientras Cobro está en primer plano y la terminal recibe espacio suficiente para uno o varios medios.

Cerrar/volver no confirma ni descarta silenciosamente información importante.

> “Ya no estoy armando la venta. Estoy recibiendo dinero.”

La separación es visual/operativa. En backend, venta + pagos + salida de inventario conservan la atomicidad ya protegida por SRCM.

---

## 4. Atajos operativos acordados

- **F1 — Nueva venta**
- **F3 — Carga de artículos**
- **F7 — Terminal de Cobro**
- **F5 — no interceptar**; queda reservado al navegador

Reglas: los atajos propios ejecutan `preventDefault()` cuando corresponde; F7 abre Cobro, nunca confirma; Enter nunca confirma el cierre monetario irreversible.

Ayuda visual sugerida: `F1 Nueva venta · F3 Artículos · F7 Cobro`.

---

## 5. Medio de pago: elección obligatoriamente explícita

La Terminal de Cobro **no debe abrir con Efectivo preseleccionado**.

Estado inicial: **¿Cómo paga el cliente?**

Opciones configurables por organización: Efectivo, Transferencia, Débito, Crédito, Billetera digital, Crédito en cuenta u otros medios habilitados.

La rapidez del POS no debe inducir al operador a asumir “Efectivo”.

---

## 6. Resumen monetario permanente

Mostrar siempre: **TOTAL VENTA**, **TOTAL PAGOS PREPARADOS**, **FALTA / EXCEDE / EXACTO**.

Ejemplos:

- `TOTAL $9.000 · CARGADO $7.500 · FALTA $1.500`
- `TOTAL $3.000 · CARGADO $7.500 · EXCEDE $4.500`
- `TOTAL $9.000 · CARGADO $9.000 · ✓ EXACTO`

Si el operador escribió `$7.500` y luego cambia el carrito, SRCM **no modifica silenciosamente ese importe**. Puede ofrecer `Completar saldo` o `Ajustar al saldo`, siempre como acciones explícitas.

---

## 7. Pagos múltiples

Los pagos divididos son normales. Cada medio aparece como tarjeta independiente.

Ejemplo:

**TOTAL: ARS 90.000**

**EFECTIVO — ARS 30.000**

**VISA CRÉDITO — ARS 60.000**<br>
•••• 4827<br>
3 cuotas<br>
Procesador: Mercado Pago<br>
Operación #83927461
Estado informado: Aprobada

**TOTAL PAGOS: ARS 90.000 — ✓ EXACTO**

Antes de confirmar, se puede agregar, revisar, editar o quitar medios.

---

## 8. Efectivo: aplicado, entregado y vuelto

No confundir importe aplicado a la venta, dinero entregado y vuelto.

Ejemplo: venta ARS 9.000, cliente entrega ARS 10.000, aplicado ARS 9.000, vuelto ARS 1.000.

El diseño debe quedar preparado para futuro **vuelto multimedio** sin falsear el total vendido.

---

## 9. Tarjetas: evidencia estructurada

Cuando corresponda: débito/crédito, marca/red, últimos 4 dígitos, cuotas, procesador/adquirente, ID externo de operación, código de autorización, estado informado y fecha/hora.

No almacenar como solución normal número completo, CVV/CVC ni datos sensibles innecesarios.

`reference` y `notes` pueden conservar observaciones, pero no reemplazan evidencia estructurada.

---

## 10. Transferencias, billeteras y medios electrónicos

La evidencia puede provenir de API, webhook, consulta posterior, ID de operación, comprobante, extracto bancario, importación o verificación manual autorizada cuando no exista canal automatizable.

La UI registra el cobro declarado y su evidencia disponible. La verificación financiera puede ser inmediata o posterior.

---

## 11. Cuentas financieras configurables por organización

SRCM debe representar cuentas/destinos privados: Caja efectivo local, Mercado Pago, bancos, Payway y futuros procesadores/instrumentos.

La cuenta pertenece a la organización. Un medio puede requerir seleccionar o resolver su cuenta destino.

---

## 12. Motor universal de Cuentas y Conciliación

El Core no se diseña alrededor de un proveedor. Mercado Pago, Payway, un banco, una billetera o un importador de extractos son **adaptadores**.

El motor debe poder representar progresivamente: cuenta, cobro esperado, operación externa, movimiento externo, acreditación, egreso, comisión, retención/deducción, conciliación, diferencia, pendiente de revisión y resolución atribuible.

Ejemplo: Cobro tarjeta esperado ARS 60.000; acreditación neta ARS 56.820; comisión ARS 3.180; resultado conciliado. La diferencia bruto/neto no es un error de venta.

---

## 13. Instituciones sin API

Orden preferente: API/webhook; consulta/polling; importación CSV/XLSX u otro formato utilizable; flujo manual controlado cuando no exista alternativa razonable.

Todos alimentan el **mismo motor de conciliación**. La falta de API de un banco no cambia el dominio.

---

## 14. Reconciliación APB

Debe ser privada por organización, trazable, idempotente, auditable, atribuible, resistente a duplicados y explícita ante diferencias.

No forzar coincidencias, sobrescribir historia, considerar conciliado solo porque el cajero eligió un medio ni borrar operaciones corregidas.

Ante discrepancia: **Pendiente de revisión** con motivo/evidencia.

---

## 15. Confirmación final de Cobro

Pago único:

**CONFIRMAR COBRO — ARS 9.000,00 — EFECTIVO**

`¿Confirmás que recibiste ARS 9.000,00 en efectivo?`

Pago múltiple: mostrar cada medio e importe antes de confirmar.

Reglas: Enter no confirma; F7 no confirma; no existe medio implícito; botón final explícito; antes de cerrar se revalidan total, stock, autoridad y backend; si falla una parte no queda venta/pago/stock a medias.

---

## 16. Relación con reservas, holds y carrito persistente

Pendientes separados: holds temporales POS, concurrencia multicanal, protección contra sobreventa, carrito persistente/recuperable y reservas formales.

No resolverlos creando una reserva física oculta por cada línea de carrito. El carrito puede proteger su disponibilidad visual sin modificar prematuramente el stock persistido.

---

## 17. Estado recuperado de la GRAN PRUEBA

Flujo comprobado: `Compra/Orden → recepción → inventario → movimientos → Override → disponibilidad → POS → cobro → venta → salida inventario`.

Aprendizajes: Enter no puede ejecutar venta irreversible; carrito visible; cantidad editable; cantidad no disponible debe advertirse; rapidez no puede asumir medio de pago; un importe manual no se recalcula silenciosamente.

Snapshot de hardening UX 2026-08-10: **451 tests / 3947 assertions**, staging vacío, sin commit/push del hardening UX todavía. El checkpoint oficial continúa en `ce62ecc...` hasta aprobación visual y cierre controlado.

---

## 18. Implementación recomendada por bloques

### P0 — Continuidad documental
Este documento + Roadmap.

### P1 — Terminal de Cobro APB
Overlay, F7/F1/F3, medio explícito, Falta/Excede/Exacto, confirmación final, Enter protegido, backend atómico preservado.

### P2 — Evidencia estructurada de pagos
Campos por medio, tarjeta, transferencia, billeteras, operación externa y pagos múltiples.

### P3 — Cuentas financieras
Cuentas privadas por organización, caja/bancos/procesadores, destino, permisos y auditoría.

### P4 — Efectivo y vuelto
Entregado/aplicado, cálculo de vuelto y preparación de vuelto multimedio.

### P5 — Operaciones externas y adaptadores
Contrato común; Mercado Pago como adaptador; API/webhook/polling; idempotencia; IDs y estados externos.

### P6 — Centro de Conciliación
Cobros esperados, movimientos/acreditaciones, bruto/neto, comisiones, matching, diferencias, revisión y resolución.

### P7 — Instituciones sin API
Importadores CSV/XLSX, previsualización, normalización, idempotencia y conciliación contra el mismo motor.

Posteriores relacionados: holds POS, concurrencia multicanal, carrito persistente, reservas, crédito propio/cuentas por cobrar y vuelto multimedio completo.

---

## 19. Criterio mínimo antes de declarar SRCM V1.0 listo para uso comercial real

No hace falta integrar todos los bancos/procesadores. Sí debe existir como mínimo:

1. Terminal de Cobro APB operativa.
2. Medio explícito, sin Efectivo asumido.
3. Pago múltiple usable.
4. Evidencia estructurada básica.
5. Cuentas financieras privadas.
6. Diferenciación entre cobro declarado y acreditación verificada.
7. Camino de conciliación.
8. Camino alternativo para instituciones sin API.
9. Auditoría e idempotencia.
10. Venta/pago/inventario protegidos atómicamente.

---

## 20. Principios de continuidad

Si se retoma en otra conversación:

1. Leer este documento.
2. Leer ADR 31.
3. Revisar `docs/06_ROADMAP.md`.
4. Confirmar rama, HEAD, estado dirty y staging.
5. **No reabrir como pendientes decisiones ya implementadas.**
6. No hacer commit/push del hardening UX hasta aprobación visual/manual.
7. Implementar por bloques pequeños, fail-closed y con RESULT verificable.
8. No corregir silenciosamente decisiones del operador.

### Proveniencia de la evidencia de pago

La evidencia estructurada del cobro debe distinguir su **fuente** sin alterar la verdad financiera:

- **API / automática (preferida):** cuando exista integración con el procesador o proveedor, SRCM recibe metadata segura como marca/red, últimos 4, cuotas, procesador, ID de operación externa, autorización y estado informado. El operador no debe transcribir esos datos y la interfaz debe mostrarlos como evidencia automática / de solo lectura.
- **Respaldo manual:** queda disponible únicamente cuando el proveedor, adquirente, billetera o entidad no pueda consultarse automáticamente. Lo ingresado se registra como snapshot declarado al momento del cobro y debe ser auditable.
- **PAN completo, CVV y códigos de seguridad:** nunca deben entrar a SRCM, ni manualmente, ni por API, ni en logs.
- **Separación de verdades:** evidencia automática o manual no equivale por sí sola a acreditación ni conciliación. Cuentas/Conciliación enlazará posteriormente el cobro esperado con la operación externa y la acreditación real.

Principio APB: **API primero; respaldo manual explícito; nunca datos sensibles; ninguna evidencia declarada se presenta como dinero conciliado.**