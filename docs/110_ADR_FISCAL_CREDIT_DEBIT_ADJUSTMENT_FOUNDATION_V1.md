# ADR 110 — Fiscal Credit/Debit Adjustment Foundation V1

Estado: aceptada para el corte posterior a WSFE Payment Due Date Evidence V1.

## Problema

`FiscalDocumentType` ya contiene `invoice`, `credit_note` y `debit_note`, pero el core P10.2 fue creado exclusivamente para facturas nacidas de una `CommerceSale` confirmada.

Por esa razón:

- `FiscalDocumentManager` rechaza cualquier tipo distinto de `Invoice`;
- `fiscal_documents.commerce_sale_id` era obligatorio;
- `fiscal_document_lines.commerce_sale_line_id` era obligatorio;
- el manager de factura copia snapshots, moneda, importes y líneas desde la venta.

Habilitar notas eliminando solamente la guarda `Invoice` convertiría una decisión fiscal en una inferencia desde comercio y sería incorrecto.

## Decisión

Las notas de crédito y débito se crean mediante `FiscalAdjustmentManager`, separado del manager de facturas.

El ajuste exige explícitamente:

- tipo `CreditNote` o `DebitNote`;
- punto de venta fiscal activo;
- snapshot de receptor;
- moneda;
- subtotal de servicios;
- subtotal de productos;
- total;
- una o más líneas con posición, tipo, descripción, cantidad, precio unitario y total;
- clave de idempotencia.

El emisor se congela desde la configuración fiscal del punto de venta, del mismo modo que en una factura.

No se consulta ni copia una `CommerceSale`, una línea comercial, una devolución, una cancelación ni una solicitud posventa.

## Signo e importes

Los importes de una nota se conservan como magnitudes fiscales positivas. La naturaleza `credit_note` o `debit_note` define la semántica del ajuste.

Los subtotales deben ser no negativos, el total debe ser positivo y:

`service_subtotal_minor + product_subtotal_minor = total_minor`.

La suma de las líneas de servicio debe coincidir exactamente con el subtotal de servicios. La suma de las líneas de producto debe coincidir exactamente con el subtotal de productos.

Este foundation no recalcula `line_total_minor` a partir de cantidad y precio unitario; conserva el importe fiscal explícito y sólo verifica consistencia de sumas.

## Evolución del core

`commerce_sale_id` y `commerce_sale_line_id` pasan a ser nullable para permitir documentos fiscales que no nacen de una venta.

Eso no vuelve opcional el origen comercial de una factura:

- una factura sigue requiriendo `commerce_sale_id`;
- una línea de factura sigue requiriendo `commerce_sale_line_id`;
- una nota debe tener ambos vínculos comerciales en `NULL`.

SQLite protege estas reglas mediante triggers de origen además de conservar los triggers de inmutabilidad.

Como SQLite reconstruye físicamente una tabla al modificar nullability, la migración captura desde `sqlite_master` todos los triggers preexistentes cuyo SQL depende de `fiscal_documents` o `fiscal_document_lines`, los retira dentro de una transacción, ejecuta ambos cambios y restaura literalmente su SQL antes de crear las nuevas guardas. Una vista dependiente no se reescribe silenciosamente: la migración falla cerrada y exige tratamiento explícito.

`FiscalDocumentManager` no cambia y continúa siendo invoice-only.

## Asociaciones y autorización

La norma WSFE exige para notas de crédito/débito una base de asociación (`PeriodoAsoc` o al menos un `CbteAsoc`), con reglas especiales para FCE.

Las asociaciones se implementarán en el siguiente subcorte. Hasta entonces una nota creada por este foundation permanece deliberadamente no autorizable.

`FiscalAuthorizationFactManager` rechaza `CreditNote` y `DebitNote`, y SQLite agrega una guarda equivalente sobre `fiscal_authorization_attempts`.

El siguiente subcorte reemplazará ese bloqueo absoluto por un gate que exija evidencia de asociación válida.

## Idempotencia e inmutabilidad

La clave de idempotencia se compara contra un fingerprint construido con:

- punto fiscal;
- tipo de nota;
- emisor congelado;
- receptor explícito;
- moneda;
- subtotales y total;
- líneas explícitas normalizadas.

Una repetición exacta devuelve el mismo documento. Un conflicto falla cerrado.

Los documentos y líneas continúan usando las guardas de inmutabilidad del core fiscal.

## Fuera de alcance

No se implementan en este corte:

- `CbtesAsoc`;
- `PeriodoAsoc`;
- Factura de Crédito MiPyME / FCE;
- derivación automática desde ventas, devoluciones o posventa;
- tax composition específica de la nota;
- concepto/período;
- receptor WSFE DocTipo/DocNro;
- `CbteFch`;
- resumen monetario WSFE;
- moneda/cotización WSFE;
- `FchVtoPago`;
- construcción del payload;
- numeración remota;
- WSAA, WSFE, HTTP, secretos, CAE, CAEA o producción.

Esas evidencias se registrarán explícitamente sobre la nota en los mismos dominios ya existentes cuando corresponda.
