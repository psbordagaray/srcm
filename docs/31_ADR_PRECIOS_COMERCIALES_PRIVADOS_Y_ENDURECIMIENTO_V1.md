# ADR 31 — Precios comerciales privados y endurecimiento operativo V1

Estado: **Aceptada**

Checkpoint de decisión: `fe6d879c6bf50fcffc612215c72b1817ebb992fc`

## Contexto

La Gran Prueba operativa previa a SRCM V1.0 demostró que el núcleo de venta,
cobro e inventario confirma transacciones atómicas, pero también expuso
defectos que no pueden llegar al uso comercial real:

- un Operador podía escribir libremente el precio unitario;
- el formulario aceptaba cualquier combinación de tres letras como moneda;
- el rol visible provenía del rol legado del usuario y no de la membresía activa;
- Disponibilidad mostraba por defecto posiciones exactamente en cero;
- el error final de stock era correcto como guard de dominio, pero demasiado
  genérico para una venta;
- Administración necesita visibilidad inmediata de autorizaciones pendientes.

## Decisiones

### Precio

El catálogo maestro de productos sigue siendo compartido. El precio comercial
NO forma parte de `catalog_products`.

Cada organización mantiene revisiones privadas de precio por:

`organización + producto + moneda`

Las revisiones son históricas. Cambiar un precio cierra la vigencia anterior y
crea una nueva. La venta resuelve el precio vigente del servidor y no confía en
un `unit_price` enviado por el navegador. La línea confirmada conserva además
la referencia a la revisión privada de precio que originó el importe, cuando
la operación proviene de la superficie HTTP endurecida.

Bloque 1 admite precios base ARS y USD. Promociones, descuentos, listas y
recargos financieros se montarán sobre esta fundación sin sobrescribir la
historia del precio base.

### Autoridad

Administradores pueden modificar precios comerciales. Operadores pueden
vender usando el precio vigente, pero no definirlo discrecionalmente.

El rol mostrado en la interfaz debe provenir de la membresía activa de la
organización.

Las solicitudes de stock negativo pendientes se destacan en Dashboard para
usuarios con autoridad de Override.

Las autorizaciones operativas generales —por ejemplo exigir aprobación a un
Operador para determinadas transferencias aun con stock suficiente— quedan
reservadas para el siguiente subbloque de autoridad. No se reutiliza de forma
incorrecta el concepto de stock negativo para esas aprobaciones.

### Stock

Disponibilidad oculta por defecto posiciones exactamente en cero, pero conserva
el filtro explícito `Cero`.

El proyector de inventario sigue siendo la última defensa atómica contra
concurrencia. Ventas agrega una validación contextual previa y, si el guard
atómico detecta una carrera, reconstruye un mensaje que identifica producto,
ubicación, solicitado, disponible y faltante.

## No decidido / fuera de este bloque

- Holds temporales POS y concurrencia multicanal.
- Carrito web persistente y recuperación por WhatsApp.
- Promociones, descuentos y listas múltiples.
- Medios de pago estructurados, cuentas financieras y conciliación API.
- Vuelto multimedio.
- Crédito propio / cuentas por cobrar.
- Autorizaciones generales configurables por membresía.

Esos bloques dependen de esta fundación y se implementan por separado.
