# SRCM Roadmap

# Versión 1.0 (MVP)

Objetivo:
Que un comercio pueda operar diariamente utilizando únicamente SRCM.

## Núcleo

- Dashboard
  - tablero operativo por organización — ADR 27 aceptada; Bloque 1 implementado
- Organizaciones
- Personas
  - directorio general de identidades — ADR 26 aceptada; Bloque 1 implementado
- Marcas
- Modelos técnicos
- Productos
- Identificadores
- Stock
- Clientes
  - identidad comercial y rol Cliente — ADR 25 aceptada; Bloque 1 implementado
- Proveedores
- Compras
  - auditoría de fundación — completada
  - proveedores y ofertas — fundación existente
  - compras directas afectadas a Reparaciones — fundación existente y congelada
  - órdenes generales, recepciones parciales y costos — ADR 24 aceptada; Bloques 1 y 2 completados
  - UX pendiente: costo logístico esperado, costo informado/prepoblado editable, código de proveedor visible
  - flujo pendiente: «Compra directa recibida» con control físico y recepción atómica, sin obligar a pasar por Oferta/Orden cuando no corresponde
- Ventas
  - venta/cobro/inventario atómicos — fundación implementada
  - precios privados y autoridad comercial — ADR 31 aceptada; Bloque 1 implementado
  - POS operativo: compositor único, lookup guiado, carrito compacto/hoja operativa, cantidades editables y Enter protegido — hardening UX local en curso
  - Terminal de Cobro APB + atajos F1/F3/F7 + medios explícitos — diseño funcional aprobado; implementación pendiente
  - pagos estructurados, cuentas financieras y conciliación — diseño funcional aprobado; implementación por bloques
  - criterio V1.0: SRCM debe distinguir «cobro declarado» de «dinero verificado/acreditado» y disponer de un camino de conciliación aun cuando la institución no tenga API
  - plan de continuidad: `docs/32_PLAN_TERMINAL_COBRO_CUENTAS_CONCILIACION_V1.md`
- Búsqueda
  - búsqueda global operativa — ADR 28 aceptada; Bloque 1 implementado
- Importación Excel/CSV
  - productos CSV/XLSX con previsualización y confirmación atómica — ADR 29 aceptada; Bloque 1 implementado
- Usuarios y permisos
  - membresías y roles por organización — ADR 30 aceptada; Bloque 1 implementado
- Reparaciones Core
  - activos e identificadores técnicos — Core 1 completado
  - órdenes de servicio e ingreso documentado — Core 1 completado
  - diagnóstico y presupuestos versionados — Core 2 completado
  - trabajo propio y tercerizado — Core 3 completado
  - repuestos y compras afectados a la orden — Core 4 completado
  - custodia con especialistas y resultado atribuible — Core 3 completado
  - control de calidad, entrega y garantía atribuible — Core 5 completado
  - venta mixta, pagos y controles antifraude — Core 6 completado
  - recepción web, buscador y expediente operativo inicial — UI 1 completada
  - diagnóstico, presupuesto versionado y decisión del cliente — UI 2 completada
  - cancelación posterior a aprobación, resolución y devolución trazable — Core 7 completado
  - superficie HTTP/UI de cancelación posterior a aprobación — Core 8 completado
  - reclamos de garantía, reingreso y orden correctiva trazable — Core 9 completado
  - superficie HTTP/UI de reclamos de garantía y devolución — Core 10 completado
  - evidencias privadas, fotografías y archivos inmutables — Core 11 completado
  - superficie HTTP/UI segura de evidencias privadas — Core 12 completado
  - superficie HTTP/UI de trabajo propio, tercerizado y custodia — Core 13 completado
  - superficie HTTP/UI de repuestos, compras afectadas y consumos — Core 14 completado
  - superficie HTTP/UI de control de calidad, entrega y garantías — Core 15 completado
  - superficie HTTP/UI de venta, cobro y cierre comercial — Core 16 completado

---

# Versión 2.0

- Casos
- Protocolos
- Dependencias
- Compatibilidades
- Riesgos
- Observaciones
- Evidencias
- Historial técnico

---

# Versión 3.0

- Comunidad
- Reputación
- IA
- Marketplace de conocimiento
- Integraciones
- API pública

### P3 Foundation — cuentas y conciliación

Base iniciada desde `801fbff2a8a80dca3fe3b7fb2b3b2458a293eb4b`: cuentas financieras privadas, movimientos externos inmutables/idempotentes, expediente de conciliación, eventos append-only y asignaciones de evidencia. Mantener separadas venta, cobro declarado, operación externa, acreditación y conciliación. Próximos pasos: destino de cuenta en Terminal, adaptadores/API, Centro de Conciliación e importadores bancarios CSV/XLSX.

### P3.1 — cuentas operativas en Terminal

Desde `373a2d0b83b2559c81d99524c486eda93e790dfd`: gestión visual de `financial_accounts` y `financial_account_id` por pago. Cada nuevo cobro web debe indicar destino privado activo, de la organización y moneda correctas; pagos múltiples admiten destinos distintos. La cuenta es destino declarado, no acreditación ni conciliación. P5 automatizará resolución por adaptadores y P6 mostrará conciliación.
