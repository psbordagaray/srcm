# SRCM — Roadmap maestro

Estado de continuidad: **documento ejecutivo de referencia obligatoria**
Actualizado: **2026-08-20**
Rama de desarrollo: `feature/core-entity`
Base funcional publicada tras P11 S3-Compatible Remote Adapter Foundation CI hotfix:

`cfb7d784d5f898b6c04a72ac473f69e95d91c603`
`fix(resilience): normalize blank S3 backup configuration`

El checkpoint canónico es siempre el `HEAD` de
`origin/feature/core-entity` cuando local/remoto coinciden y el repositorio está
limpio. Este documento debe mantenerse sincronizado con `docs/README.md`.

Puerta de entrada obligatoria para recuperación:

`docs/README.md`

Documento detallado complementario:

`docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`

Documento financiero complementario:

`docs/32_PLAN_TERMINAL_COBRO_CUENTAS_CONCILIACION_V1.md`

---

## Current P11 checkpoint - S3-Compatible Remote Adapter Foundation

Encrypted off-host backup capability remains published at 0a433cb2ed22ea08c44f8c5c299749aacb5feb75.
S3-compatible remote adapter foundation remains published at c154eeda909ba70ba36931fc9d58615db13fd418.
CI hotfix checkpoint: cfb7d784d5f898b6c04a72ac473f69e95d91c603 - fix(resilience): normalize blank S3 backup configuration.
The hotfix only normalizes empty dedicated S3 configuration slots to null.
No provider is selected, no credentials are added, no remote provider I/O is executed, and no schedule is enabled.
Functional GitHub Actions push run: 32435048926 - GREEN.
The authoritative SQLite database remained unchanged.
Production remains blocked and off_host_encrypted_backup=false.
Next exact boundary: P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1.

## 0. Mandato de producto

SRCM debe evolucionar hasta ser una **plataforma full de operación comercial**, capaz de simplificar el trabajo real del comerciante sin sacrificar integridad, trazabilidad ni autoridad.

No se busca agregar funciones por cantidad. Se busca que las funciones compartan una verdad operacional común:

**producto → precio autorizado → stock → venta/compra → cobro/pago → cuenta → verificación → fiscalidad → auditoría**

Principio APB permanente:

> **Automatizar lo inequívoco; preguntar lo ambiguo; bloquear lo peligroso; nunca corregir silenciosamente una decisión humana.**

Los hechos confirmados no se reescriben para “hacer coincidir” la realidad. Se corrigen con hechos posteriores, reversos, reemplazos, diferencias o resoluciones auditables.

---

## 1. Jerarquía de verdad para continuidad

Si una conversación se cuelga, se pierde o debe continuar en otro chat:

1. **Código + migraciones + tests en el checkpoint Git publicado**.
2. **`docs/06_ROADMAP.md`** — mapa ejecutivo y estado.
3. **`docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`** — North Star, alcance completo y criterios.
4. ADRs y planes especializados, especialmente `docs/32_PLAN_TERMINAL_COBRO_CUENTAS_CONCILIACION_V1.md`.
5. RESULT de runners validados.
6. Conversación/memoria, únicamente como apoyo.

Nunca reabrir como pendiente una decisión ya implementada y validada salvo regresión demostrable.

`docs/README.md` se lee primero como índice y puntero de recuperación, pero no
agrega una verdad de dominio ni reemplaza esta jerarquía.

### Gate irrefutable de continuidad documental

Cada paso que cambie el estado real del proyecto debe sincronizar y publicar,
sin excepción, `docs/README.md`, `docs/06_ROADMAP.md` y
`docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`.

Si uno de los tres queda atrasado, no se abre el siguiente corte funcional.

---

## 1.1. Estado maestro tras P11 Production Resilience Baseline V1

El checkpoint funcional `95b9ae392a7c3a038f6c4db55774f238681ff00d` mantiene P1–P10 publicados/cerrados en su alcance local y consolida P11 con Security + Observability + Resilience baselines nativos y testeados.

P10 permanece **LOCAL_CLOSURE=GREEN** en `7922c51f7f52995c7137094ec7e8be9cbdd32192`; `REAL_ARCA_HOMOLOGATION`, WSASS e identidad fiscal real siguen diferidos y no bloquean P11.

Security Baseline V1 en `b712081c550d2fba36704ec75678eba1f5b73ff9` conserva headers globales, producción fail-closed, step-up de alto impacto y secret/config hygiene.

Observability Baseline V1 en `a17b8aec8ee583dbb121931fd58fc54663de46c7` conserva correlación global, JSON logging, señales seguras de queue/jobs/integraciones y readiness operacional.

Resilience Baseline V1 publica:
- snapshot SQLite consistente por `VACUUM INTO`;
- comandos explícitos de backup y restore verification, sin restore real sobre la BD viva;
- scheduler horario y `withoutOverlapping`;
- retención baseline de 168 snapshots;
- SHA-256 + manifiesto + verificación aislada de integridad;
- directorio de producción fuera del árbol del repo;
- freshness gate de 90 minutos en readiness;
- RPO objetivo 60 minutos y RTO objetivo 240 minutos;
- ADR 132 aceptado.

Validación: **6/36 focal, 19/112 regresión Resilience+Observability+Security y 1077 tests / 8059 assertions GREEN**. Baseline real autoritativa preservada: 107 tablas de negocio, fingerprint `D682F392715CFC9EAE886BD1D865DC60415D345E8369B9071EC89FD3436DAC3D`, schema `F2653BE8FF9B9160A6E544868478E39B7C37E57123E096BC97756CE902D92F42` y 93 migraciones con hash lógico `03AC754F8B637811B412AB381F881BB55F3C838D77FCE547748878CB5BA6FC14`.

Durante la validación no se respaldó ni restauró la BD real; se usó SQLite sintética temporal. Off-host encrypted backup, proveedor remoto/KMS, CI/CD pipeline y deploy automation siguen abiertos como release gates. Producción continúa bloqueada.

Próxima frontera exacta:
`P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1`.

CI/CD Release Gates V1 publica workflow versionado con checkout fijado por SHA, permisos mínimos, instalaciones desde lockfiles, `git diff --check`, full suite, build y `srcm:release-preflight --ci`. El preflight inventaría migraciones sin ejecutar migrate/rollback, exige `down()` no vacío y valida `GET /api/health/ready`. Producción continúa fail-closed: backup off-host cifrado, restore drill operativo y secretos/aprobaciones de producción siguen abiertos. ADR: `docs/133_ADR_PRODUCTION_CI_CD_RELEASE_GATES_V1.md`. Validación: **6/32 focal, 31/180 regresión Release+Resilience+Observability+Security, 1083/8091 full + asset build GREEN**.

---

# V1.0 — Operación comercial completa / lista para producción

Objetivo: que un comercio como SULU pueda operar diariamente usando SRCM como sistema principal, con continuidad, seguridad, fiscalidad y control de dinero reales.

## 2. Núcleo ya construido

### Organizaciones, identidad y autoridad
- organizaciones privadas;
- membresías y roles por organización;
- personas e identidad comercial central;
- clientes;
- proveedores;
- auditoría atribuible;
- aislamiento tenant;
- permisos efectivos por membresía activa.

### Catálogo y conocimiento
- categorías;
- marcas;
- fabricantes;
- productos;
- modelos técnicos;
- identificadores;
- compatibilidades;
- knowledge bridge;
- búsqueda global;
- importación CSV/XLSX con previsualización y commit atómico.

### Inventario
- ubicaciones privadas jerárquicas;
- ledger de movimientos;
- proyección de saldos;
- cantidades fraccionables por producto;
- disponibilidad;
- controles de negativos;
- autorizaciones/overrides;
- incidentes y regularizaciones;
- inmutabilidad de hechos confirmados.

### Compras
- proveedores y ofertas;
- órdenes de compra;
- recepciones parciales;
- costos;
- compras afectadas a reparaciones;
- trazabilidad recepción → inventario.

Pendientes de UX ya conocidos:
- costo logístico esperado;
- costo informado/prepoblado editable;
- código de proveedor visible;
- flujo “Compra directa recibida” sin forzar Oferta/Orden cuando no corresponde.

### Ventas / POS
- venta + pagos + inventario atómicos;
- precios privados y autoridad comercial;
- POS con compositor único;
- lookup guiado;
- carrito compacto;
- cantidades editables;
- Enter protegido;
- F1/F3/F7;
- Terminal de Cobro APB;
- pago múltiple;
- evidencia estructurada;
- precios server-authoritative.

### Finanzas P1–P3.1
Implementado y publicado:
- P1 — Terminal de Cobro APB;
- P2 — evidencia estructurada de pagos;
- P3 — foundation de cuentas financieras y conciliación;
- P3.1 — cuentas operativas y `financial_account_id` por pago.

Verdades financieras separadas:
1. venta;
2. cobro declarado;
3. operación externa;
4. acreditación;
5. conciliación.

Checkpoint P3.1:
`7753e61fba147395362109d90ce895d61442562a`.

### Reparaciones Core
Fundación y superficies operativas ya desarrolladas:
- activo/identificadores;
- orden;
- diagnóstico;
- presupuestos versionados;
- decisión del cliente;
- trabajo propio/tercerizado;
- custodia;
- repuestos;
- control de calidad;
- entrega;
- garantía;
- cancelaciones;
- evidencias privadas;
- venta/cobro de servicio.

---

## 3. P4 — Caja operativa, turnos, efectivo y pagos a proveedores — EN DESARROLLO

P4 amplía el antiguo concepto “Efectivo y vuelto”. Su alcance vinculante es:

### P4A — Cajas operativas
- múltiples cajas por organización/sucursal;
- cada caja física/lógica enlazada a una cuenta financiera `cash_box`;
- caja activa/inactiva sin borrado destructivo;
- identificación clara de terminal/caja.

### P4B — Apertura y turno
- sesión/turno de caja;
- usuario/cajero responsable;
- apertura;
- fondo inicial;
- una política explícita para sesiones concurrentes;
- preselección/sugerencia automática del destino efectivo basada en la caja del turno;
- nunca una preferencia eterna olvidable del usuario.

### P4C — Movimientos de efectivo
- ventas en efectivo;
- ingresos autorizados;
- retiros parciales;
- retiros de seguridad;
- transferencias internas caja → caja fuerte/tesorería;
- pagos operativos autorizados;
- reversos/correcciones append-only;
- motivo y actor obligatorios cuando corresponda.

Un retiro de seguridad **no es un gasto**: el dinero cambia de custodia/cuenta interna.

Hardening vinculante P4C/P4D:
- el cajero solicita el retiro; solicitar no mueve dinero;
- un Administrador/supervisor diferente del solicitante autoriza o rechaza;
- autorizar no mueve dinero;
- el responsable del turno ejecuta físicamente una autorización vigente y de un solo uso;
- `requested_by`, `approved_by` y `executed_by` quedan separados y auditados;
- cambiar turno, caja, origen, destino, importe, moneda, motivo o nota exige nueva autorización;
- una solicitud pendiente/autorizada debe resolverse antes del cierre;
- el `security_drop` histórico previo al hardening se conserva sin reescritura;
- la pantalla de turno expone un selector de operación y nunca mantiene Retiro + Arqueo como formularios sensibles simultáneamente activos.

### P4D — Arqueo y cierre
- efectivo esperado;
- efectivo contado;
- denominaciones opcionales;
- diferencia;
- faltante/sobrante explícito;
- motivo;
- autorización escalable;
- cierre inmutable;
- historial de sesiones.

### P4E — Efectivo entregado/aplicado/vuelto
No confundir:
- importe aplicado a la venta;
- dinero entregado;
- vuelto.

Foundation P4E:
- `commerce_payments.amount_minor` sigue siendo el importe aplicado a la venta;
- en efectivo, SRCM puede conservar por separado `tendered_amount_minor` y `change_amount_minor`;
- el vuelto se deriva como `entregado - aplicado`, nunca altera el total vendido;
- el movimiento de caja registra el importe aplicado/retenido, no infla el esperado con el efectivo transitoriamente recibido antes de dar vuelto;
- cobros históricos sin captura P4E conservan `NULL` y no se les inventa evidencia retrospectiva;
- la Terminal de Cobro muestra y confirma aplicado, entregado y vuelto antes del submit irreversible.

Pendiente posterior: vuelto multimedio sin falsear el total vendido.

Criterio de aceptación P4E:
- venta ARS 9.000 + cliente entrega ARS 10.000 + vuelto ARS 1.000 conserva `aplicado=9.000`, `entregado=10.000`, `vuelto=1.000`;
- en la foundation de vuelto efectivo en el mismo medio, el esperado de caja aumenta por el efectivo neto retenido, no por el efectivo transitorio antes de dar vuelto;
- `entregado` y `vuelto` sólo pertenecen a líneas Efectivo;
- los cobros históricos sin esa captura permanecen explícitamente sin evidencia;
- vuelto multimedio futuro requerirá hechos de movimiento por cada origen/destino y no se resolverá falseando `amount_minor`.

### P4F — Pago a proveedores en recepción
Separar siempre:
1. recepción física;
2. obligación económica;
3. autorización;
4. ejecución del pago;
5. cuenta/caja de origen;
6. beneficiario;
7. evidencia.

Casos:
- mercadería pagada contra entrega;
- pago parcial;
- pago pendiente;
- pago al vendedor/proveedor;
- transportista autorizado;
- flete separado de mercadería;
- límites por rol/importe;
- escalamiento a encargado/administrador/dueño.

**Confirmar una recepción de stock nunca paga automáticamente.**

P4F se construirá por slices compatibles con P9:

**P4F.1 — Obligación Foundation**
- obligación privada por organización;
- origen en recepción/documento/condición comercial explícita;
- mercadería y logística separables;
- proveedor/beneficiario estructurado;
- importe, moneda, vencimiento/condición, estado, idempotencia y fingerprint;
- recepción conforme puede preparar una obligación según política, nunca ejecutar el pago.

Contrato de Foundation P4F.1:
- la obligación se reconoce explícitamente desde una `PurchaseReceipt` confirmada;
- `merchandise` y `logistics` son componentes separados y cada uno puede tener beneficiario distinto;
- importe y moneda derivan de la recepción/orden y no son editables por el usuario;
- una recepción admite como máximo una obligación por componente; el pago parcial futuro se aplica sobre esa obligación, no se crean deudas duplicadas para simular cuotas;
- el beneficiario es `BusinessParty` privada de la organización y por defecto puede ser la identidad del proveedor;
- condición de pago estructurada: contra recepción, vencimiento en fecha u otra condición explicada;
- la obligación es inmutable, idempotente y auditable;
- no crea `CashMovement`, no toca `FinancialAccount`, no cambia Inventario y no representa autorización ni ejecución.

**P4F.2 — Solicitud y autorización**
- solicitud, autorización, rechazo/cancelación y expiración explícitos;
- actores y timestamps separados;
- límites por capacidad/importe;
- segregación de funciones cuando la política lo exija;
- autorización ligada por fingerprint a obligación, beneficiario, importe, moneda y origen propuesto.

Contrato Foundation P4F.2:
- la solicitud nace exclusivamente desde una `PurchaseObligation` reconocida;
- beneficiario y moneda se heredan de la obligación y no son editables;
- el solicitante puede proponer un importe total o parcial mayor que cero y nunca superior a la obligación;
- el origen propuesto es una `FinancialAccount` activa, privada de la organización y de la misma moneda;
- sólo puede existir una solicitud activa (`pending` o `approved`) por obligación;
- Operador/Administrador pueden solicitar; Administrador aprueba, rechaza o expira;
- `solicitante != aprobador` se aplica de forma fail-closed;
- una solicitud aprobada ya no se rechaza: puede cancelarse o expirar antes de la futura ejecución;
- solicitante o Administrador pueden cancelar con motivo explícito;
- solicitud y aprobación tienen claves de idempotencia y fingerprints separados;
- el fingerprint autorizable liga obligación, huella de obligación, beneficiario, importe, moneda, origen y contexto;
- Operational Attention lleva el pendiente al aprobador y el resultado/acción al solicitante;
- ninguna transición P4F.2 crea `CashMovement`, altera `FinancialAccount`, modifica Inventario ni marca la obligación como pagada;
- P4F.3 reforzará el importe disponible con el ledger real de ejecuciones y consumirá la autorización mediante un hecho de desembolso, no reescribiendo la obligación.

**P4F.3 — Ejecución**
- pago total o parcial;
- cuenta/caja de origen explícita;
- efectivo sólo desde turno válido y crea egreso `CashMovement` al ejecutar;
- obligación o autorización por sí solas no alteran el efectivo esperado;
- medios no efectivo generan su hecho financiero saliente sin inventar un movimiento de caja;
- evidencia segura y hora de servidor.

Contrato Foundation P4F.3 — efectivo:
- una autorización se consume exactamente por el importe autorizado; el ejecutor no puede recortarlo ni ampliarlo silenciosamente;
- un pago parcial de la obligación se modela autorizando un importe parcial, ejecutándolo y luego creando otra solicitud sólo por el saldo económico pendiente;
- `PurchasePaymentExecution` es un hecho separado, inmutable, idempotente y auditable; no reescribe `PurchaseObligation` ni la aprobación P4F.2;
- el saldo ejecutable deriva de `obligación original - SUM(ejecuciones confirmadas)`;
- Foundation ejecuta sólo `cash_box`; banco/billetera quedan para el hecho financiero saliente y verificación externa posteriores;
- el ejecutor debe tener capacidad de ejecución, no puede ser el aprobador y debe poseer un turno abierto propio sobre la caja autorizada;
- al ejecutar se revalida la huella de aprobación, obligación, beneficiario, importe, moneda, origen, turno y efectivo esperado actual;
- la ejecución y su `CashMovement::purchase_payment` nacen en la misma transacción; el movimiento es `out`, sin destino interno, sin `CommercePayment` y sin disfrazarse de retiro de seguridad;
- sólo entonces la solicitud pasa `approved -> executed` y deja de admitir cancelación o vencimiento;
- reintentar la misma clave de ejecución devuelve el mismo hecho; otra clave no duplica el pago;
- la DB bloquea mutación/borrado de ejecución y movimiento y exige vínculo estructurado entre autorización, ejecución y egreso;
- P4F.3 Foundation no inventa ejecución retroactiva para autorizaciones históricas y no crea hechos no efectivo;
- P4F.4 completará la distribución avanzada de ejecución/resultado por Attention y controles adicionales.

**P4F.4 — Atención y control**
- pendientes encuentran al aprobador mediante Operational Attention;
- autorizaciones encuentran al ejecutor;
- rechazo/cancelación vuelve al solicitante;
- pago externo y débito/acreditación verificada permanecen separados hasta conciliación;
- diferencias nunca se corrigen ni pagan silenciosamente.

Contrato Foundation P4F.4:
- una ejecución confirmada vuelve al solicitante como `resultado` acknowledgeable; Attention no crea ni modifica el pago;
- el estado de control forma parte de la clave proyectada: si el solicitante reconoce el resultado con turno abierto y luego el arqueo cambia el control a `exacto` o `con diferencia`, el nuevo resultado puede aparecer sin reescribir el anterior;
- para efectivo, `PurchasePaymentControlReader` deriva control sólo de `PurchasePaymentExecution + CashMovement + CashRegisterSession + CashRegisterClosure`;
- mientras el turno está abierto, el egreso está registrado pero el control físico queda pendiente del arqueo/cierre;
- un cierre sin diferencia confirma el control de Caja, no crea una conciliación financiera adicional;
- un cierre con diferencia la muestra explícitamente y jamás altera, compensa ni vuelve a pagar la ejecución confirmada;
- efectivo no crea `FinancialExternalMovement` ni `payment_reconciliation`: la verificación externa no aplica a un egreso físico de Caja;
- el motor P3 de movimientos externos/conciliación permanece como verdad separada para banco, billetera y procesadores; P4F.4 no fuerza una conciliación de egresos todavía inexistentes;
- cualquier inconsistencia estructural entre ejecución y `CashMovement` se proyecta como anomalía de control; SRCM no inventa movimientos para cuadrarla.

ADR rector: `docs/35_ADR_HECHOS_MONETARIOS_APLICADO_ENTREGADO_VUELTO_OBLIGACION_DESEMBOLSO_V1.md`.

---

## 4. P5 — Operaciones externas y adaptadores

- contrato provider-neutral;
- Mercado Pago como primer adaptador cuando se implemente;
- API/webhook/polling;
- idempotencia;
- firmas/secretos;
- estados externos;
- metadata segura de pago;
- no PAN completo;
- no CVV;
- jobs/reintentos;
- registro de fallos sin duplicar efectos.

**P5.1 — Provider-neutral Ingestion Foundation**
- `financial_provider_connections` vincula organización + `FinancialAccount` + proveedor + ID externo sin guardar secretos;
- una cuenta de efectivo nunca se conecta a un proveedor financiero externo;
- una cuenta conectada conserva tipo, proveedor y moneda como identidad estable;
- `ExternalFinancialProviderAdapter` normaliza payload provider-specific a una observación financiera segura;
- `ExternalFinancialProviderIngestor` admite únicamente API/webhook/polling y siempre termina en `ExternalFinancialMovementRecorder`;
- la ingestión automática no inventa un usuario humano: `created_by_user_id` puede ser NULL y la auditoría conserva el hecho igualmente;
- la misma `financial_account + external_operation_id + status` con mismos importes es idempotente incluso si reaparece por otro canal;
- la misma operación/estado con contenido financiero distinto falla cerrado;
- una transición de estado crea un nuevo `FinancialExternalMovement` inmutable; nunca actualiza el anterior;
- registrar evidencia externa no concilia, no modifica Venta, no modifica Caja y no paga nada;
- P5.2 incorporará el primer adaptador concreto, autenticación/firma, secretos, jobs, retry y observabilidad.

ADR rector: `docs/36_ADR_ADAPTADORES_OPERACIONES_EXTERNAS_PROVIDER_NEUTRAL_V1.md`.

---

## 5. P6 — Centro de Conciliación

- cobro esperado;
- movimiento externo;
- bruto;
- neto;
- comisión;
- retenciones;
- matching;
- diferencia;
- pendiente de revisión;
- asignaciones;
- resolución;
- trazabilidad;
- conciliación parcial y múltiple cuando el dominio lo requiera.

---

## 6. P7 — Instituciones sin API

- importación CSV/XLSX;
- previsualización;
- normalización;
- mapeo configurable;
- detección de duplicados;
- idempotencia;
- conciliación contra el mismo motor;
- fallback manual explícito y auditable.

API primero; importación después; manual sólo cuando no exista alternativa razonable.

---

## 7. P8 — Posventa comercial completa

**Estado V1: CERRADO / GREEN — P8.5.8.**
La implementación V1 quedó cerrada por auditoría integral read-only sobre el checkpoint P8.5.7: contratos, 17 rutas HTTP, outcomes, ejecución final, crédito convergente y guardas regresaron GREEN; la suite completa quedó en 715 tests / 5948 assertions. Mercado Pago Refund conserva su gate DEGRADED/BLOCKED según ADR 65 y no se considera una brecha de P8 mientras SRCM mantenga el comportamiento fail-closed.

- devoluciones parciales/totales;
- cambios;
- reembolsos;
- crédito/saldo a favor;
- devolución al medio original cuando corresponda;
- diferencia de precio;
- devolución de stock con condición real;
- trazabilidad a venta original;
- nunca editar retrospectivamente la venta original;
- integración futura con notas de crédito fiscales.

---

## 8. P9 — Cuentas por cobrar y cuentas por pagar

**Estado actual: P9.1–P9.8 PUBLICADOS / P9 CxC-CxP V1 CERRADO.**

### CxC
- cuenta corriente de cliente;
- ventas a crédito;
- vencimientos;
- límites;
- anticipos/señas;
- cuotas propias;
- cobranzas parciales;
- un cobro aplicado a una o varias deudas;
- saldos a favor;
- aging.

### CxP
- factura/documento de proveedor;
- orden;
- recepción;
- obligación;
- vencimiento;
- pago parcial;
- pago agrupado;
- anticipos;
- notas de crédito;
- 3-way match progresivo:
  **orden ↔ recepción ↔ documento del proveedor**;
- diferencias explícitas antes de pagar.

### Checkpoints publicados de P9

- P9.1: cuenta por cobrar;
- P9.2: cobranza e imputaciones;
- P9.3: aging y exposición derivada;
- P9.4: política de crédito y override;
- P9.5: cuotas propias;
- P9.6a: anticipos de clientes;
- P9.6b: excedente de cobranza a crédito;
- P9.7a: documento/factura de proveedor;
- P9.7b: 3-way match derivado;
- P9.7c: relevamiento de brechas, sin nueva verdad productiva;
- P9.7d–P9.7e: nota de crédito y aplicación a obligación;
- P9.7f–P9.7g: anticipo a proveedor y convergencia de crédito;
- P9.7h: autorización agrupada;
- P9.7i: desembolso canónico individual/agrupado, cash/non-cash.
- P9.7j: convergencia HTTP/UI y control posterior individual/agrupado,
  cash/non-cash.
- P9.7k: verificación append-only de desembolso non-cash contra
  `FinancialExternalMovement` `Debit + Posted`, con selección Admin,
  exclusividad de evidencia y diferencias explícitas.
- P9.7l: resolución/derivación append-only por observación externa, con
  snapshots de diferencia, comisión y retención, sin modificar CxP ni fingir
  contabilidad inexistente.
- P9.8: exposición y aging CxP derivados, vencimiento efectivo, agregación por
  proveedor/beneficiario/moneda y estado de cuenta sin saldo paralelo.

P9 queda cerrado en V1. P10 RECON confirmó sobre `312b0520` que la venta
comercial ya es una verdad independiente y que la capa fiscal aún no existe.

P10.1 incorpora la configuración fiscal argentina por organización y puntos de
venta por ambiente, sin documento, numeración, autorización ni HTTP externo.

---

## 9. P10 — Fiscalidad argentina / ARCA

Objetivo: integrar la fiscalidad argentina sin convertirla en la verdad primaria del negocio.

Contrato vinculante:

**Venta comercial ≠ comprobante fiscal ≠ autorización fiscal.**

Toda venta confirmada debe existir en SRCM con independencia de que el circuito fiscal esté pendiente, autorizado, rechazado, en contingencia o legalmente no aplicable al caso concreto. La fiscalidad se modela como una capa separada y auditable.

SRCM no debe incorporar mecanismos destinados a ocultar ventas, suprimir operaciones confirmadas ni producir documentación falsa para evadir obligaciones. Sí debe representar fielmente la situación real de cada operación y permitir distinguir, consultar y gestionar su estado fiscal.

Estados fiscales orientativos:
- no iniciado;
- pendiente;
- autorizado;
- rechazado;
- contingencia;
- anulado/corregido mediante documento fiscal posterior;
- no aplicable cuando jurídicamente corresponda.

- configuración fiscal por organización;
- puntos de venta;
- numeración correlativa;
- WSAA;
- WSFEv1 como integración principal cuando corresponda;
- evaluar WSMTXCA si el caso requiere detalle de ítems;
- CAE;
- CAEA/contingencia donde aplique;
- comprobantes A/B/C/M y otros requeridos por el negocio;
- notas de crédito/débito;
- QR;
- comprobante imprimible/digital;
- homologación;
- reintentos idempotentes;
- separación entre venta confirmada y autorización fiscal;
- nunca inventar CAE ni numeración;
- arquitectura preparada para otras jurisdicciones sin rediseñar Comercio.

### P10.1 — Fiscal Configuration Foundation V1

- `FiscalOrganizationProfile` separado de `Organization`;
- CUIT validado, razón social, condición IVA referenciada, IIBB, inicio de
  actividades y domicilio fiscal;
- `FiscalPointOfSale` por organización, ambiente y número;
- homologación y producción explícitamente separadas;
- modos `wsfe_v1` y `wsmtxca` representables sin activación remota;
- identidad del punto inmutable, baja lógica y prohibición de borrado físico;
- administración exclusiva, tenancy, transacción, locks y auditoría;
- cero dependencia desde `CommerceSale` hacia la capa fiscal;
- cero numeración fiscal, WSAA, WSFE, CAE, CAEA o QR en este corte.

### P10.2 — Fiscal Document Core V1

- `FiscalDocument` y sus líneas son evidencia fiscal append-only, separada de la venta comercial;
- snapshots inmutables de emisor, receptor, moneda, importes y líneas;
- el estado es derivado (`pending`) mientras no exista un hecho de autorización;
- sin numeración, CAE, QR, alícuotas, credenciales, adapter ni comunicación ARCA.

### P10.3 — Fiscal Authorization Facts V1

- intentos y respuestas de autorización como hechos separados e inmutables;
- outcomes explícitos y estado fiscal derivado desde la evidencia;
- ningún resultado remoto reescribe la venta ni el documento fiscal.

### P10.4 — Fiscal Document Numbering V1

- numeración fiscal interna separada de `CommerceSale.sale_number`;
- identidad por documento/punto/ambiente y evidencia inmutable;
- la numeración interna nunca reemplaza CAE ni autoridad ARCA.

### P10.5 — Fiscal Authorization Integration Boundary V1

- contratos provider-neutral para adapter, transporte, credenciales, request y result;
- separación entre Core fiscal y proveedor externo;
- todavía sin WSAA, HTTP, secretos ni ejecución remota.

### P10.6 — External Execution / Homologation Readiness

- P10.6 RECON confirmó que transporte HTTP, credential store efectivo y ejecución externa seguían ausentes;
- P10.6.1 publicó readiness/configuración de homologación sin secretos ni llamadas externas;
- P10.6.2 preflight read-only confirmó homologación/producción deshabilitadas;
- ejecución real sigue bloqueada hasta completar credenciales, runtime y homologación controlada.

### P10.7 — Fiscal Classification & Tax Evidence

#### P10.7.1 — Fiscal Recipient & Tax Policy V1
- perfil fiscal de contraparte separado de la identidad comercial;
- política tributaria versionada por organización;
- sin inferir comprobante ni IVA desde la venta.

#### P10.7.2 — Fiscal Tax Composition V1
- bases, tasas e importes tributarios explícitos por documento;
- evidencia inmutable;
- sin recalcular silenciosamente desde precios comerciales.

#### P10.7.3 — Fiscal Voucher Classification V1
- clase/código fiscal explícito e inmutable por documento;
- ninguna regla automática selecciona comprobante desde la venta.

#### P10.7.4 — Fiscal Concept & Service Period V1
- concepto fiscal explícito: productos, servicios o ambos;
- servicios y mixtos exigen período desde/hasta válido;
- evidencia inmutable y separada;
- sin inferencia desde venta y sin activar ARCA.

### P10 — Fiscal Payload Completeness & WSFE Request Readiness — PUBLICADO

Sin inventar subnumeración adicional, quedaron publicados por nombre:

- WSFE Recipient Fiscal Evidence;
- WSFE Fiscal Voucher Date Evidence;
- WSFE Monetary Summary Evidence;
- WSFE Currency & Quotation Evidence;
- WSFE Payment Due Date Evidence;
- Fiscal Credit/Debit Adjustment Foundation;
- WSFE Associated Voucher / Period Evidence;
- WSFE Remote Sequence Authority Boundary;
- WSFE Tax Detail Classification Evidence;
- WSFE FECAE Request Composition;
- WSFE FECAE Transport Input Boundary.

Resultado: el `FeCAEReq` estándar se compone desde evidencia fiscal explícita,
usa candidato de secuencia remota y llega al transport sin secretos ni
dependencia de `FiscalDocumentNumber` local.

### P10 — WSAA / Endpoint / SOAP / Response / Convergence Readiness — PUBLICADO

- WSAA Access Ticket Provider, material X.509 boundary, signer CMS y transporte `LoginCms` permanecen publicados para homologación;
- `FiscalAuthorizationRuntimeScopeStore` → `EnvironmentFiscalAuthorizationRuntimeScopeStore` resuelve `service + issuer_cuit` sólo desde configuración WSAA tenant-scoped;
- `FiscalAuthorizationCredentialStore` queda enlazado sin dereferenciar material criptográfico;
- `FiscalRemoteSequenceAuthority` → `WsaaBackedFiscalRemoteSequenceAuthority` usa readiness → scope → TA → `FECompUltimoAutorizado`;
- `FiscalAuthorizationTransport` → `WsaaBackedFiscalAuthorizationTransport` usa readiness → scope → TA → `FECAESolicitar` → normalizer → convergence;
- `WsfeFecaeRequestComposerContract` → `WsfeFecaeRequestComposer`;
- `WsfeFecaeProviderResponseNormalizerContract` y `WsfeFecaeProviderResultConvergenceContract` quedan enlazados;
- los wire boundaries DOM/Guzzle para ambas operaciones permanecen homologación-only, TLS-verified, acotados y sin retry automático;
- homologación deshabilitada corta antes de TA/wire; producción, WSASS, identidad real y homologación externa permanecen bloqueados/diferidos.

Validación del corte: **62/329 focal, 89/461 regresión fiscal y 1052 tests / 7911 assertions GREEN**. BD real lógica y canary binario permanecieron intactos.

**Cierre local P10:** GREEN en `7922c51f7f52995c7137094ec7e8be9cbdd32192`. La arquitectura local fiscal queda completa y testeada; homologación ARCA real y WSASS permanecen como deuda externa diferida. P11 ya fue abierto y su Production Security Baseline V1 está publicado en `b712081c550d2fba36704ec75678eba1f5b73ff9`.

---

## 10. P11 — Producción, seguridad, observabilidad y recuperación

Antes de depender de SRCM como sistema único:

**Estado actual:** Security Baseline V1 `b712081c550d2fba36704ec75678eba1f5b73ff9`, Observability Baseline V1 `a17b8aec8ee583dbb121931fd58fc54663de46c7` y Resilience Baseline V1 `95b9ae392a7c3a038f6c4db55774f238681ff00d` publicados. El último corte validó **6/36 focal, 19/112 regresión Resilience+Observability+Security y 1077 tests / 8059 assertions GREEN** con BD canónica intacta. La próxima frontera es `P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1`.

### Seguridad
- **PUBLICADO baseline V1:** headers globales conservadores; configuración de producción fail-closed; step-up por contraseña reciente en operaciones de alto impacto; guard de secret/config hygiene; ADR 130;
- MFA/passkeys, PIN supervisor dedicado y gestión avanzada de dispositivos — diferidos;
- CSP/Permissions-Policy — diferidos hasta inventario específico de scripts/assets/hardware.

### Observabilidad
- **PUBLICADO baseline V1:** request/correlation IDs globales; contexto de excepción; JSON `stderr_json`; señales de queue/job/integración; readiness `/api/health/ready`; ADR 131;
- OpenTelemetry, métricas externas, tracing distribuido, alert provider y Horizon/Telescope — diferidos.

### Resiliencia
- **PUBLICADO baseline V1:** `VACUUM INTO` para snapshots SQLite consistentes, comando de backup, scheduler horario, SHA/manifiesto, retención 168, restore verification aislada, freshness gate 90 min, RPO 60 min y RTO 240 min; ADR 132;
- el baseline no expone restore automático sobre la BD viva y las pruebas no tocaron la BD real;
- producción exige directorio de backup fuera del árbol SRCM;
- backup off-host cifrado y KMS/proveedor remoto siguen como release gate.

### CI/CD y release engineering
**SIGUIENTE RECON:** `P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1`. Debe verificar, sin mutar:
- workflows/pipelines realmente versionados;
- gate automático de suite, lint/build y dependencias;
- estrategia de migraciones en deploy y rollback técnico;
- artefactos/versionado/promoción entre ambientes;
- secretos y permisos de CI;
- release gate de backup off-host cifrado y restore drill operativo;
- criterios concretos que faltan para desbloquear producción.

No se implementa pipeline, deploy automation ni proveedor remoto por presunción antes del RECON.

### Integraciones robustas
- outbox/eventos internos;
- reintentos;
- idempotency keys;
- dead-letter/revisión;
- webhooks firmados;
- versionado de contratos.

Outbox permanece diferido hasta después de los release gates de P11.

---

## 11. P12 — Continuidad offline y hardware POS

### Offline restringido
- continuidad temporal de venta cuando sea seguro;
- cache mínimo necesario;
- cola local firmada/identificada;
- replay idempotente;
- conflicto explícito al reconectar;
- nunca esconder sobreventas o conflictos;
- fiscalidad offline sólo bajo mecanismos legalmente válidos;
- no depender exclusivamente de una API de navegador de soporte desigual.

### Hardware
Adaptadores, no dependencias rígidas:
- scanner 1D/2D;
- impresora térmica;
- cajón portamonedas;
- balanza;
- impresora de etiquetas;
- display de cliente;
- terminales de pago;
- NFC/QR;
- dispositivos Android/desktop;
- futuras etiquetas electrónicas.

### SRCM Customer Kiosk / Price Checker
Modo autoservicio conectado al mismo catálogo maestro:
- escaneo EAN/UPC/GTIN/QR/DataMatrix;
- nombre, foto y precio vigente;
- promociones;
- características y variantes;
- unidad de venta;
- stock/ubicación cuando la política lo permita;
- compatibilidades y Knowledge Universe;
- productos relacionados;
- consulta de otras sucursales;
- futura reserva/preventa desde kiosco.

Puede desplegarse sobre hardware dedicado o tablet/Android/PC + scanner. El kiosco consulta la misma verdad comercial: no mantiene una segunda lista de precios.

### Seguridad de tienda / Loss Prevention
Integración progresiva con:
- EAS tradicional;
- tags RF/AM;
- RFID UHF/EPC item-level;
- antenas/portales de salida;
- desactivadores/removedores en caja;
- correlación con POS;
- eventos de seguridad;
- futura correlación con CCTV cuando exista integración autorizada.

Un código de barras ordinario no sustituye un tag EAS/RFID.

Progresión:
`EAS básico → RFID/EPC item-level → Smart Exit / Loss Prevention`.

Con identificación item-level, SRCM podrá distinguir salida autorizada de artículo sin venta/transferencia/salida válida y conservar evento, hora, sucursal, puerta y artículo.

---

# Universo comercial objetivo de SRCM Full

SRCM se diseña como plataforma horizontal para **retail + mayorista + distribución + servicios + reparación + omnicanalidad**, con verticales específicos sólo cuando un rubro requiera reglas propias.

Rubros y operaciones objetivo, entre otros:

- kioscos, maxikioscos, almacenes, despensas, minimercados, autoservicios y supermercados;
- bazares, regalerías, jugueterías, librerías, papelerías, perfumerías y comercios multirrubro;
- indumentaria, calzado, marroquinería y accesorios;
- ferreterías, corralones, casas de electricidad, sanitarios, pinturerías, bulonerías, madereras y materiales para obra;
- celulares, computación, electrónica, televisores, audio, electrodomésticos, seguridad y telecomunicaciones;
- autopartes, motopartes, lubricantes, neumáticos, baterías, repuestos agrícolas y talleres con venta;
- servicios técnicos de electrónica, informática, electrodomésticos, herramientas, maquinaria y otros equipos;
- mayoristas, distribuidores, importadores y empresas con múltiples depósitos;
- negocios de productos fraccionados por metro, kilo, litro, unidad u otra magnitud;
- comercios de alimentos/consumo masivo con lote, vencimiento y trazabilidad;
- muebles, decoración, iluminación, hogar, jardín, camping y equipamiento;
- negocios de alto valor con seguimiento por serie/IMEI/SN;
- empresas con varias cajas, varias sucursales y múltiples canales;
- venta por pedido, preventa, reserva, seña y crédito comercial;
- servicios con consumo de materiales/repuestos;
- PYMEs y empresas familiares que hoy operan parcialmente con planillas, Drive, papel y mensajería.

SRCM no se declarará automáticamente especialista vertical de restaurantes, hoteles, clínicas, farmacias, estaciones de servicio o fábricas MRP completas. Esos dominios podrán construirse como verticales cuando exista necesidad real, reutilizando el Core común sin degradarlo.

La meta es profundidad real en el comercio, no convertirse en un producto superficial para todas las industrias.

---

# V1.5 — Retail omnicanal y crecimiento

## 12. P13 — Reservas, holds, concurrencia y carrito persistente
- holds POS temporales;
- reservas formales;
- concurrencia multicanal;
- prevención de sobreventa;
- carrito persistente/recuperable;
- recuperación por cliente/WhatsApp;
- expiraciones;
- prioridad y autoridad;
- no crear movimientos físicos ocultos por cada carrito.

## 13. P14 — Multi-sucursal y fulfillment
- sucursales;
- depósitos;
- cajas por sucursal;
- stock por ubicación;
- transferencias;
- tránsito;
- recepción de transferencias;
- retiro en tienda;
- ship-from-store;
- entrega;
- picking/packing progresivo.

## 14. P15 — Omnicanalidad, publicaciones y SULU Media
- WhatsApp Business;
- Instagram;
- ecommerce;
- marketplaces;
- catálogo compartido;
- stock compartido;
- precio por canal;
- campañas;
- publicación automática;
- pausa por agotado;
- trazabilidad publicación → venta;
- módulo SULU Media/cartelería digital;
- programación de piezas;
- SRCM Player;
- contenido derivado de ficha de producto y campañas.

## 15. P16 — Motor comercial avanzado
- listas de precios;
- minorista/mayorista;
- escalas por cantidad;
- promociones;
- combos;
- cupones;
- descuentos con autoridad;
- margen mínimo;
- reglas por canal;
- precios programados;
- fidelización;
- gift cards;
- puntos/recompensas;
- campañas segmentadas.

## 16. P17 — GS1, 2D, lotes, series y etiquetado
- GTIN/EAN/UPC;
- IMEI/SN;
- lote;
- vencimiento;
- GS1 Application Identifiers;
- QR/DataMatrix;
- GS1 Digital Link progresivo;
- etiquetas;
- trazabilidad de unidad/lote;
- recall cuando aplique.

## 17. P18 — Reposición, planificación y proveedores
- mínimos/máximos;
- punto de pedido;
- lead time;
- stock proyectado;
- órdenes sugeridas;
- estacionalidad;
- forecasting;
- supplier scorecards;
- precio/plazo/calidad;
- compras consolidadas;
- alertas de quiebre.

Primero reglas determinísticas; IA después.

## 18. P19 — CRM y analítica
- ficha 360 del cliente;
- historial;
- frecuencia;
- ticket medio;
- preferencias;
- segmentación;
- consentimiento;
- campañas;
- cohortes;
- margen;
- rotación;
- aging;
- caja;
- compras;
- reparaciones;
- BI/export;
- dashboards por rol.

## P20 — Tablero de Módulos, Capacidades y Superficies

Objetivo: que una misma plataforma SRCM pueda adaptarse desde un comercio pequeño hasta una empresa multisucursal compleja sin bifurcar el producto ni obligar a cada usuario a convivir con funciones que no necesita.

Cadena de autoridad futura:

`plataforma disponible → organización habilita módulos → autoridad delega capacidades → alcance limita dónde → usuario recibe su superficie`

Contrato:
- Dueño/Admin general habilita o deshabilita módulos de la organización;
- administradores de segundo nivel reciben delegación limitada por capacidades y alcance;
- roles como Admin/Operator/Viewer evolucionan a presets iniciales, no a la única fuente de autoridad;
- el menú y las acciones visibles derivan de módulo habilitado + capacidad + alcance;
- ocultar UI nunca sustituye autorización de backend/DB;
- desactivar un módulo nunca borra historia: puede ocultar, dejar read-only o reactivar;
- dependencias entre módulos deben ser explícitas;
- presets por rubro son recomendaciones editables, nunca jaulas;
- módulos podrán habilitarse globalmente o, cuando exista P14, por sucursal/ámbito;
- operaciones sensibles pueden exigir segregación solicitante/autorizador/ejecutor y umbrales futuros.

Presets orientativos:
- Retail general;
- Moda y belleza;
- Electro / tecnología;
- Repuestos / autopartes;
- Servicios y reparaciones;
- Mayorista / distribuidor;
- Configuración personalizada.

El **catálogo comercial universal** —producto, variantes, SKU/códigos, marca, categoría, precio, fotos y stock— debe funcionar plenamente sin Knowledge Universe. Modelos técnicos, compatibilidades, assertions y conocimiento enriquecido son una **capacidad avanzada opcional** que una organización puede habilitar cuando aporta valor real a su rubro.

Principio vinculante:

> **La potencia total pertenece a SRCM; la complejidad visible pertenece sólo a quien la necesita.**

P20 completo se implementará en un bloque propio. Desde ahora cada módulo nuevo debe diseñarse compatible con este contrato.

### Centro de Atención Operativa — capacidad transversal

SRCM debe llevar los pendientes hacia la persona que puede resolverlos y los resultados hacia quien necesita conocerlos. Ningún usuario debería recorrer módulos para descubrir una autorización pendiente, un Override por revisar o el resultado de una decisión que inició.

Base transversal:
- campana superior con contador por actor;
- bandeja de atención con deep-links al hecho exacto;
- bloque `Requiere tu atención` en Dashboard;
- separación entre `acción requerida` y `resultado a conocer`;
- filtrado por organización + actor + capacidad + alcance;
- el hecho de dominio sigue siendo la única fuente de verdad;
- leído/ack, cuando sea necesario, conserva sólo metadata del usuario y nunca duplica el estado de negocio;
- los pendientes accionables desaparecen al cambiar el hecho de estado;
- los resultados terminales pueden reconocerse sin modificar la evidencia original;
- badges de sidebar son una extensión opcional de la misma proyección, no contadores artesanales por módulo.

Primeros proveedores:
1. solicitudes de retiro de seguridad;
2. Overrides de stock negativo.

Extensiones previstas:
- diferencias y excepciones de caja;
- descuentos/precios con autoridad;
- compras, pagos y tesorería;
- recepciones con diferencias;
- cancelaciones y garantías;
- conciliaciones y anomalías;
- cualquier workflow futuro que requiera prontitud operativa.

Principio vinculante:

> **Una decisión pendiente debe encontrar a quien puede resolverla; un resultado relevante debe encontrar a quien lo necesita.**

---

# V2.0 — Operación avanzada / empresa escalable

## 19. Logística y depósito avanzado
- picking;
- packing;
- olas;
- zonas;
- cross-docking cuando corresponda;
- conteos cíclicos;
- inventario móvil;
- recepciones asistidas;
- ruteo/entrega mediante integraciones.

## 20. Plataforma móvil/PWA
- operación responsive;
- inventario móvil;
- recepción móvil;
- conteo;
- fotos/evidencia;
- lector de cámara;
- firma;
- notificaciones.

## 21. Experiencias de tienda
- customer display;
- kiosco/self-service donde tenga sentido;
- consulta de precio;
- turnero;
- etiquetas electrónicas;
- QR de producto;
- recibo digital.

## 22. Integración contable y administrativa
SRCM debe poseer la verdad operacional; no necesita reimplementar todo software commodity.

Integrar progresivamente:
- contabilidad general;
- impuestos/liquidaciones externas;
- bancos;
- payroll;
- couriers;
- ecommerce;
- proveedores especializados.

Construir nativamente sólo cuando hacerlo mejore de verdad la operación o la integridad.

## 23. Datos y gobierno
- exportación completa;
- portabilidad;
- archivado;
- retención;
- privacidad;
- permisos granulares;
- data lineage;
- catálogos de eventos;
- versionado de APIs;
- políticas de eliminación donde legalmente corresponda sin destruir evidencia obligatoria.

---

# SRCM Business Network — red comercial inter-organización

SRCM Full debe poder evolucionar desde sistema privado de cada empresa hacia una red comercial opt-in que conecte organizaciones sin mezclar sus datos privados.

> **SRCM no sólo administra una empresa. A escala, puede conectar empresas entre sí conservando la soberanía, autoridad y privacidad de cada organización.**

## Perfil comercial publicable
Cada organización podrá decidir publicar:
- nombre comercial;
- zona de cobertura;
- varios rubros/categorías principales;
- marcas;
- mayorista/minorista/distribuidor/importador/servicio;
- canales de contacto;
- condiciones generales publicables;
- catálogo/ofertas seleccionadas.

Nada privado se publica por inferencia automática.

## Descubrimiento de proveedores
Búsqueda por rubro, producto, marca, código, ubicación, cobertura, condiciones, reputación y disponibilidad/oferta publicada.

## RFQ / cotización B2B
`Necesidad de compra → RFQ → proveedores → ofertas → comparación → selección → SupplierOffer/PurchaseOrder`.

## Catálogo compartible y mapping
Cada empresa conserva su catálogo privado. La red podrá relacionar:
- GTIN/EAN/UPC;
- SKU/código proveedor;
- código fabricante;
- modelo técnico;
- marca;
- Knowledge Universe;
- mapping confirmado.

Un producto desconocido puede generar una propuesta de alta, nunca un alta automática sin revisión/autoridad.

## Documento proveedor → inbound automático
> **El proveedor transmite datos; el comprador controla la realidad física. El documento del proveedor no aumenta stock por sí solo. La recepción física confirmada es la que incorpora mercadería al inventario propio.**

Circuito:
`PurchaseOrder comprador`
→ `SalesOrder proveedor`
→ preparación/despacho
→ `Invoice/Remito/ASN estructurado`
→ `Inbound/Purchase Receipt esperado comprador`
→ control físico
→ confirmación
→ stock propio.

La factura, orden de venta, remito o ASN puede crear/prellenar automáticamente una recepción esperada con proveedor, productos, cantidades, costos informados, documentos, bultos, lotes/series, referencia externa y estado de envío.

**El comprador controla; no vuelve a transcribir.**

## Diferencias de recepción
Ejemplo:
`Proveedor declaró 100 → 97 conformes + 2 dañados + 1 faltante`.

SRCM conserva esperado, recibido, condición, faltante/sobrante, daño, evidencia y reclamo. Sólo lo físicamente confirmado ingresa al stock correspondiente y con condición real.

## 3-way match
`Orden de compra ↔ documento/factura proveedor ↔ recepción física`.

Coincidencia exacta puede dejar preparada la obligación de pago según políticas. Diferencias nunca se corrigen ni pagan silenciosamente.

## ASN — Advance Shipping Notice
Puede transportar artículos, cantidades, bultos, lotes/series, transportista, documentos, ETA y QR/identificador. Al llegar, escanearlo puede abrir directamente la recepción esperada.

## Reputación B2B basada en hechos
Indicadores gobernados por privacidad:
- cumplimiento de cantidades;
- puntualidad;
- diferencias;
- daños;
- cancelaciones;
- calidad de respuesta;
- experiencia por rubro.

Priorizar evidencia operacional sobre estrellas subjetivas.

## Dos representaciones privadas
El mismo intercambio puede ser:
- venta/fulfillment para proveedor;
- compra/recepción/CxP para comprador.

Se comparte documento estructurado, no acceso a la base privada de la contraparte.

## Regla anti doble carga
> **Un dato estructurado creado por una empresa SRCM Network no debe recargarse manualmente por otra cuando pueda transmitirse, mapearse y validarse de forma segura.**

---

# Knowledge Universe — capacidad avanzada transversal y opcional

Knowledge Universe sigue siendo una capacidad diferencial de SRCM, pero no una obligación visible ni una dependencia del catálogo comercial universal. Una organización puede deshabilitar su superficie técnica cuando su rubro no la necesita sin perder productos, ventas, compras, stock ni historia comercial.

Cuando Knowledge está habilitado, los módulos compatibles deben preguntarse:

**¿qué conocimiento produce esta operación y qué conocimiento podría ayudarla?**

Deshabilitar Knowledge no borra entidades ni evidencia histórica. La capacidad puede permanecer oculta/read-only y reactivarse según las políticas futuras del Tablero de Módulos.

Fuentes internas:
- compras;
- ventas;
- devoluciones;
- reparaciones;
- diagnósticos;
- presupuestos;
- garantías;
- fallas;
- compatibilidades;
- identificadores;
- rotación;
- proveedores;
- precios;
- búsquedas sin resultado;
- evidencia aportada por usuarios autorizados.

Fuentes externas:
- fabricantes;
- documentación oficial;
- catálogos de proveedores;
- APIs;
- códigos/GS1;
- marketplaces;
- documentación técnica;
- fuentes públicas de la web cuando su uso sea permitido y verificable.

Modelo conceptual:

`fuente → dato candidato → normalización → entidad SRCM → relación → provenance → confianza → validación → conocimiento utilizable`

Relaciones de conocimiento posibles:
- compatible con;
- incompatible con;
- reemplaza;
- requiere;
- recomienda;
- no recomienda;
- se instala con;
- se repara con;
- presenta riesgo de;
- fue validado por;
- suele fallar por;
- suele comprarse con;
- tiene alternativa equivalente.

Siempre conservar:
- fuente;
- actor;
- contexto;
- evidencia;
- fecha;
- vigencia;
- confianza;
- validación;
- versión.

Privacidad:
- los datos privados de cada organización siguen siendo privados;
- el conocimiento compartible debe separarse de datos comerciales sensibles;
- cualquier agregación entre organizaciones debe diseñarse con reglas explícitas de privacidad, anonimización/consentimiento y gobernanza;
- nunca convertir automáticamente una observación privada en conocimiento público.

La IA podrá extraer, relacionar, detectar contradicciones y proponer conocimiento, pero debe distinguir entre:
- inferencia de IA;
- afirmación de fabricante;
- evidencia de proveedor;
- observación de usuario/comercio;
- validación técnica.

Objetivo: que SRCM sea simultáneamente **sistema de operación** y **memoria intelectual del comercio**.

---

# V3.0 — Inteligencia, automatización y plataforma

## 24. IA operacional gobernada
La IA no será un chatbot decorativo.

Casos:
- sugerir reposición;
- detectar anomalías de caja;
- señalar ventas/compras atípicas;
- identificar repuestos desde foto/código/medidas;
- proponer compatibilidades;
- sugerir precio/margen;
- preparar órdenes de compra;
- explicar variaciones;
- resumir expedientes;
- sugerir campañas;
- detectar garantías repetitivas;
- forecast;
- asistencia al operador.

Regla:
**la IA puede observar, explicar, sugerir y preparar; no debe ejecutar por sí sola dinero, stock, fiscalidad o actos irreversibles sin autoridad definida.**

## 25. Agentes y automatizaciones
- jobs inteligentes;
- aprobaciones;
- reglas;
- workflows;
- eventos;
- agent tools limitadas por permisos;
- dry-run;
- evidencia de inputs/outputs;
- human-in-the-loop;
- rollback lógico mediante hechos compensatorios.

## 26. API pública y ecosistema
- API versionada;
- OpenAPI;
- OAuth/scopes;
- webhooks;
- SDKs;
- marketplace de integraciones;
- conectores;
- permisos por app;
- rate limits;
- sandbox.

## 26.1. SRCM Business Network
- perfiles comerciales opt-in;
- rubros/categorías publicables;
- supplier discovery;
- RFQ;
- catálogos/ofertas compartibles;
- documentos B2B estructurados;
- SalesOrder/Invoice/Remito/ASN → inbound comprador;
- mapping de productos;
- 3-way match;
- reputación basada en hechos;
- privacidad y soberanía por organización;
- eliminación de doble carga.

## 27. Conocimiento y comunidad
- base técnica;
- compatibilidades;
- casos;
- protocolos;
- evidencias;
- reputación;
- conocimiento compartible;
- marketplace de conocimiento;
- IA alimentada sólo por fuentes y permisos válidos.

---

# Principios técnicos permanentes

## 28. Arquitectura
- modular monolith primero; separar servicios sólo cuando exista necesidad real;
- API-first en contratos de dominio;
- web como cliente, no autoridad final;
- provider-neutral;
- tenant-private;
- idempotencia;
- transacciones atómicas donde corresponda;
- eventos/outbox para efectos externos;
- append-only/inmutabilidad para evidencia y hechos financieros;
- bases proyectadas reconstruibles desde hechos confirmados cuando el dominio lo permita.

## 29. UX
- teclado primero en POS;
- móvil cuando la tarea lo requiera;
- accesible;
- no esconder estados críticos;
- no usar texto libre como fuente de verdad cuando existe vocabulario estructurable;
- defaults sólo cuando son inequívocos;
- confirmar explícitamente dinero/stock/acciones peligrosas;
- minimizar trabajo manual repetitivo.

## 30. Autoridad
- rol + organización + contexto;
- límites por importe/tipo;
- supervisor/step-up;
- ninguna autorización inferida de un campo de texto;
- segregación de funciones configurable para comercios grandes.

## 31. Evidencia
- snapshots inmutables;
- archivos privados;
- hash cuando corresponda;
- source/provenance;
- actor;
- fecha/hora;
- idempotencia;
- nunca almacenar datos sensibles innecesarios.

## 32. Calidad
Cada bloque:
1. diagnóstico real;
2. diseño/ADR cuando corresponde;
3. migraciones seguras;
4. tests focales;
5. suite completa;
6. `git diff --check`;
7. GRAN PRUEBA manual si afecta UI/operación;
8. verificador read-only cuando sea útil;
9. commit/push sólo tras aprobación;
10. checkpoint registrado en Roadmap.

Los runners deben ser PowerShell 5.1 compatibles y deben tratar errores fatales impresos como fallo aunque un proceso devuelva exit code incorrecto.

---

# Baseline tecnológico 2026 a vigilar

No son dependencias obligatorias inmediatas; son referencias para no diseñar SRCM con supuestos viejos:

- ARCA: facturación electrónica por Web Services oficiales, autorización por punto de venta y numeración correlativa;
- GS1 Digital Link 1.1.4 / códigos 2D;
- OpenTelemetry para traces/métricas/logs;
- FIDO2/WebAuthn/passkeys para autenticación resistente al phishing;
- POS modernos con sesiones, control de efectivo, offline temporal, hardware integrado y devoluciones;
- APIs/webhooks/event-driven integrations;
- IA operacional con permisos y human-in-the-loop.

Estas referencias deben verificarse de nuevo en fuentes oficiales antes de cada implementación porque normas, APIs y estándares cambian.

---

# Decisiones vinculantes agregadas 2026-08-11

1. **Fiscalidad desacoplada:** la venta comercial y el comprobante fiscal son verdades distintas. La integración con ARCA no debe borrar ni ocultar operaciones comerciales confirmadas.
2. **Mercado objetivo amplio:** SRCM Full cubre horizontalmente retail, mayorista, distribución, servicios, reparación y omnicanalidad; los verticales especiales se construyen cuando el dominio lo justifique.
3. **Knowledge Universe avanzado y opcional:** la plataforma preserva conocimiento, compatibilidades, evidencia y fuentes verificables, pero cada organización decide si esa capacidad forma parte de su superficie operativa; el catálogo comercial universal no depende de ella.
4. **Privacidad por organización:** el conocimiento compartible nunca autoriza mezclar datos comerciales privados sin reglas explícitas.
5. **Operación y conocimiento se retroalimentan cuando Knowledge está habilitado:** comprar, vender, reparar, devolver y garantizar pueden producir conocimiento; esa capa ayuda a comprar, vender, reparar y prevenir mejor sin ser obligatoria para rubros que no la necesitan.
6. **Experiencia de tienda conectada:** price checker/kiosco, EAS y RFID consumen el mismo catálogo, precios, stock y reglas de SRCM.
7. **Business Network opt-in:** empresas pueden descubrirse, cotizar e intercambiar documentos B2B sin exponer datos privados.
8. **Cero doble carga evitable:** datos estructurados del proveedor prellenan compra/recepción del comprador.
9. **Stock sólo por recepción física confirmada:** factura, orden, remito o ASN jamás incrementan stock por sí solos.

---

# Regla final de alcance

**“SRCM lo quiero TODO” no significa construir indiscriminadamente todo desde cero.**

Significa que el comerciante debe poder resolver desde SRCM —de forma nativa o mediante integración sólida— todo su circuito operativo sin volver a planillas, WhatsApp suelto o procesos paralelos para cubrir agujeros esenciales.

La prioridad siempre será:

**menos trabajo manual + más verdad + más seguridad + más velocidad + más trazabilidad.**

<!-- P5.2_MERCADO_PAGO_POINT_ADAPTER_V1 -->
### P5.2 — Mercado Pago Point adapter — PUBLICADO
- primer adaptador concreto montado sobre P5.1 provider-neutral;
- API de Orders vigente para Point;
- normalización de recurso completo Point Order;
- status provider-specific → provider-neutral;
- dinero decimal → minor units sin float ambiguo;
- payload/PII/tokens descartados;
- notificación incompleta fail-closed;
- smoke real opcional sólo lectura para detectar terminales;
- sin credenciales persistidas, sin webhook público y sin cobro real en esta slice.

Checkpoint base P5.1: `97653c38ca416906004e7fd4230756c6ce281115`.
Checkpoint P5.2: `afcc9d863c6a291026fdce5cb74ad3fff7702ec6`.


<!-- P5.3_MERCADO_PAGO_ORDERS_TEST_V1 -->
### P5.3 — Mercado Pago Orders / prueba controlada — PUBLICADO
- transporte HTTP mínimo `POST /v1/orders` + `GET /v1/orders/{id}`;
- `X-Idempotency-Key` UUID v4 obligatorio;
- minor units internos → decimal string sin float;
- hardening de moneda: `AR/ARG → ARS` sólo cuando la order no informa `currency`;
- errores del proveedor sanitizados, sin body ni token;
- smoke opt-in sobre `NEWLAND_N950__SBX0000001`;
- simulación `processed` + GET + normalización por adapter;
- sin Point físico, sin dinero real y sin escritura en el ledger P3.

Checkpoint P5.3: `67b08f0593d5657f5eb634ec2145af6ade6e457e`.


<!-- P5.4_MERCADO_PAGO_WEBHOOK_RESOLUTION_V1 -->
### P5.4 — Mercado Pago Webhook authenticity/resolution — PUBLICADO
- HMAC-SHA256 sobre manifest oficial `data.id + x-request-id + ts`;
- comparación constante y fail-closed ante firma incompleta o inválida;
- body nunca selecciona tenant/cuenta;
- `application_id`, `user_id` y `live_mode` sólo se contrastan contra identidad esperada;
- recurso financiero canónico siempre se obtiene por `GET /v1/orders/{id}`;
- secretos siguen fuera de DB/repo y entran sólo de forma transitoria;
- sin ruta pública ni ingestión al ledger hasta resolver secret store + connection routing + ACK/job.

Checkpoint P5.4: `9d5440f103cca6c940f991bc487760797eafa556`.


<!-- P5.5_MERCADO_PAGO_WEBHOOK_HTTP_QUEUE_V1 -->
### P5.5 — Mercado Pago Webhook HTTP/Queue + secret routing — PUBLICADO
- endpoint stateless `/api/webhooks/finance/mercado-pago/{connectionPublicId}`;
- route UUID selecciona conexión interna, nunca el body;
- secret store fuera de DB/repo mediante contrato reemplazable;
- query raw preserva `data.id` antes de HMAC;
- firma + identidad se validan antes del ACK;
- job serializa sólo connection/resource/notification IDs;
- `HTTP 200` ocurre después de enqueue y antes del GET externo;
- job obtiene order canónica y la ingiere con source Webhook;
- sin URL pública real ni prueba externa en esta slice.

Checkpoint P5.5: `8d26d07a60dd7777fc588e6ba71b968bf6e9ccd5`.
