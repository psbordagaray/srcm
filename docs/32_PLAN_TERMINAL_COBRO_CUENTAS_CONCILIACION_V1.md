# SRCM V1 — Terminal de Cobro APB, Cuentas y Conciliación

Estado: **P1–P3.1 + P4A–P4D publicados / P4E en validación / P4F planificado**

Fecha de consolidación inicial: **2026-08-10**
Fecha de actualización: **2026-08-13**

Checkpoint oficial publicado actual:
`1b2187bf5e709f583e3ee79db5ad8df528751116`
— `feat(finance): harden cash operations and attention`

P4E Foundation se valida localmente sobre ese checkpoint y no se considera publicada hasta GRAN PRUEBA + checkpoint.

North Star y roadmap maestro:
`docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`

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

P4E Foundation fija estas verdades:
- `amount_minor` sigue siendo el importe aplicado;
- `tendered_amount_minor` es evidencia del efectivo entregado;
- `change_amount_minor = tendered - applied`;
- el vuelto no cambia la venta;
- los históricos sin captura conservan `NULL`, no evidencia inventada;
- en vuelto efectivo del mismo medio, el libro de caja refleja el efectivo neto retenido.

El diseño queda preparado para futuro **vuelto multimedio**, pero ese caso requerirá separar el efectivo bruto recibido y el desembolso de vuelto por la otra cuenta. No se falseará el total vendido ni se reescribirá `amount_minor`.

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

Estado validado al 2026-08-11: P1, P2, P3 Foundation y P3.1 fueron publicados. La GRAN PRUEBA real llegó hasta Venta #4: cobro Efectivo ARS 2.000 con destino `Caja principal`, `financial_account_id` persistido y salida física enlazada. El cierre P3.1 publicó **462 tests / 4148 assertions** en `7753e61fba147395362109d90ce895d61442562a`.

La cuenta destino expresa pertenencia del cobro declarado; no implica acreditación ni conciliación.

---

## 18. Implementación por bloques — estado actualizado

### P0 — Continuidad documental — IMPLEMENTADO
Este documento + Roadmap + ADRs.

### P1 — Terminal de Cobro APB — IMPLEMENTADO
Overlay, F7/F1/F3, medio explícito, Falta/Excede/Exacto, confirmación final, Enter protegido, backend atómico preservado.

### P2 — Evidencia estructurada de pagos — IMPLEMENTADO
Campos seguros por medio y pagos múltiples. PAN completo/CVV fuera de SRCM.

### P3 — Cuentas financieras y conciliación Foundation — IMPLEMENTADO
Cuentas privadas, movimientos externos, reconciliación provider-neutral e inmutabilidad/idempotencia.

### P3.1 — Cuentas operativas en Terminal — IMPLEMENTADO
Gestión visual de cuentas y `financial_account_id` por pago. Checkpoint publicado: `7753e61fba147395362109d90ce895d61442562a`.

### P4 — Caja operativa, turnos, efectivo y pagos a proveedores — EN IMPLEMENTACIÓN

P4A–P4D están publicados. P4E Foundation incorpora aplicado/entregado/vuelto y queda pendiente de GRAN PRUEBA/checkpoint. P4F es el siguiente frente y reutiliza caja, cuentas, autoridad y Operational Attention sin fusionar recepción con desembolso.
Amplía el antiguo P4 “Efectivo y vuelto”:
- cajas múltiples vinculadas a cuentas `cash_box`;
- apertura/turno/cierre;
- fondo inicial;
- destino efectivo sugerido por turno;
- entregado/aplicado/vuelto;
- ingresos/retiros;
- retiros de seguridad;
- transferencias a caja fuerte/tesorería;
- arqueo esperado vs contado;
- faltantes/sobrantes;
- autoridad escalable;
- pago a proveedor/flete contra recepción conforme;
- recepción, obligación, autorización y ejecución del pago como hechos separados;
- confirmar stock nunca paga automáticamente.

### P5 — Operaciones externas y adaptadores
Contrato común; Mercado Pago como primer adaptador cuando corresponda; API/webhook/polling; idempotencia; IDs y estados externos.

### P6 — Centro de Conciliación
Cobros esperados, movimientos/acreditaciones, bruto/neto, comisiones, matching, diferencias, revisión y resolución.

### P7 — Instituciones sin API
Importadores CSV/XLSX, previsualización, normalización, idempotencia y conciliación contra el mismo motor.

El roadmap completo posterior —devoluciones, CxC/CxP, ARCA, producción/offline/hardware, omnicanalidad, GS1, CRM, forecasting, IA y API— se mantiene en `docs/06_ROADMAP.md` y `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`.

---

## 19. Criterio financiero mínimo antes de declarar SRCM V1.0 listo para uso comercial real

En el frente Venta/Cobro/Finanzas debe existir como mínimo:

1. Terminal de Cobro APB operativa.
2. Medio explícito, sin Efectivo asumido.
3. Pago múltiple usable.
4. Evidencia estructurada básica.
5. Cuentas financieras privadas.
6. Caja operativa con apertura/cierre/arqueo.
7. Diferenciación entre cobro declarado y acreditación verificada.
8. Camino de conciliación.
9. Camino alternativo para instituciones sin API.
10. Auditoría e idempotencia.
11. Venta/pago/inventario protegidos atómicamente.
12. Pagos a proveedores sin acoplar recepción a desembolso.

El criterio global V1.0 —incluyendo fiscalidad, posventa, CxC/CxP, producción, seguridad, backups y continuidad— está definido en el Roadmap maestro.

---

## 20. Principios de continuidad

Si se retoma en otra conversación:

1. Leer `docs/06_ROADMAP.md`.
2. Leer `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`.
3. Leer este documento si se toca Venta/Cobro/Cuentas/Conciliación/Caja.
4. Leer ADR 31 y el ADR específico del dominio afectado.
5. Confirmar rama, HEAD, working tree y staging.
6. Leer el RESULT del último bloque ejecutado.
7. **No reabrir como pendientes decisiones ya implementadas.**
8. Implementar por bloques pequeños, fail-closed y con RESULT verificable.
9. No hacer commit/push de trabajo nuevo hasta aprobación visual/manual cuando corresponda.
10. No corregir silenciosamente decisiones del operador.
11. Jerarquía de verdad: código/checkpoint → Roadmap/ADRs → RESULT → conversación.

### Proveniencia de la evidencia de pago

La evidencia estructurada del cobro debe distinguir su **fuente** sin alterar la verdad financiera:

- **API / automática (preferida):** cuando exista integración con el procesador o proveedor, SRCM recibe metadata segura como marca/red, últimos 4, cuotas, procesador, ID de operación externa, autorización y estado informado. El operador no debe transcribir esos datos y la interfaz debe mostrarlos como evidencia automática / de solo lectura.
- **Respaldo manual:** queda disponible únicamente cuando el proveedor, adquirente, billetera o entidad no pueda consultarse automáticamente. Lo ingresado se registra como snapshot declarado al momento del cobro y debe ser auditable.
- **PAN completo, CVV y códigos de seguridad:** nunca deben entrar a SRCM, ni manualmente, ni por API, ni en logs.
- **Separación de verdades:** evidencia automática o manual no equivale por sí sola a acreditación ni conciliación. Cuentas/Conciliación enlazará posteriormente el cobro esperado con la operación externa y la acreditación real.

Principio APB: **API primero; respaldo manual explícito; nunca datos sensibles; ninguna evidencia declarada se presenta como dinero conciliado.**

---

## 21. Estado de implementación P3 Foundation

Checkpoint de partida publicado: `801fbff2a8a80dca3fe3b7fb2b3b2458a293eb4b`
— `feat(commerce): add structured payment evidence foundation`.

P3 Foundation incorpora el núcleo financiero **provider-neutral** sin confundir las cinco verdades:

1. `financial_accounts`: cuentas privadas por organización (caja, banco, billetera, procesador u otra), con moneda, proveedor, referencia externa, activación, autoridad y auditoría.
2. `financial_external_movements`: hechos externos inmutables e idempotentes provenientes de API/webhook/polling/CSV/XLSX/manual, con bruto, neto, comisión y retenciones.
3. `payment_reconciliations`: expediente inmutable que vincula un cobro declarado con su importe esperado.
4. `payment_reconciliation_events`: historia append-only de matching/diferencias/resoluciones; nunca se sobrescribe el pasado.
5. `payment_reconciliation_allocations`: vínculo inmutable entre un evento y el movimiento externo usado como evidencia.

Regla económica inicial: para un cobro, el matching compara **importe esperado contra bruto externo**. Neto menor por comisión/retención no constituye error de venta. Ejemplo: esperado ARS 60.000; bruto ARS 60.000; neto ARS 56.820; comisión ARS 3.180 ⇒ conciliación exacta.

Autoridad inicial: operador puede utilizar cuentas financieras; sólo administrador configura cuentas, registra movimientos externos manuales y ejecuta/revisa conciliaciones. Los adaptadores automáticos P5 deberán ingresar por contratos de servicio explícitos sin debilitar el aislamiento por organización.

Este Foundation **no agrega todavía** selector de cuenta en Terminal, adaptadores Mercado Pago/Payway/bancos, webhooks, Centro visual de Conciliación ni importador financiero CSV/XLSX. Esos pasos continúan sobre el mismo dominio, sin redefinirlo.

---

## 22. Estado de implementación P3.1 — destino de cobro

Checkpoint P3.1 publicado:
`7753e61fba147395362109d90ce895d61442562a`
— `feat(finance): add operational payment destinations`.

P3.1 lleva `financial_accounts` a la operación diaria:

- existe gestión visual privada de cuentas; operador puede consultarlas y sólo administrador puede crear, editar, activar o inactivar;
- cada pago confirmado desde la Terminal debe declarar una `financial_account_id`;
- la cuenta elegida debe pertenecer a la organización activa, estar activa y usar la misma moneda de la venta;
- pagos múltiples pueden dirigir cada importe a cuentas distintas;
- la Terminal no presupone una cuenta destino: el operador la selecciona explícitamente entre las cuentas válidas de la moneda;
- el destino forma parte del fingerprint/idempotencia del checkout y del snapshot inmutable del cobro;
- pagos históricos previos a P3.1 conservan `financial_account_id = null`; la columna permanece nullable por compatibilidad histórica, pero el flujo web P3.1 no permite nuevos cobros sin destino;
- la cuenta destino expresa **dónde pertenece el cobro declarado**, no acredita que el dinero haya ingresado ni lo marca como conciliado.

P3.1 no introduce todavía asociaciones rígidas medio→tipo de cuenta. Un cobro con tarjeta puede terminar en un procesador, billetera o banco según la configuración real de la organización. Se bloquean tenant, actividad y moneda; los adaptadores P5 resolverán progresivamente destinos automáticos sin redefinir este contrato.
