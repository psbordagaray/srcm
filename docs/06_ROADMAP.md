# SRCM â€” Roadmap maestro

Estado de continuidad: **documento ejecutivo de referencia obligatoria**
Actualizado: **2026-08-20**
Rama de desarrollo: `feature/core-entity`
Base funcional publicada tras P11 S3-Compatible Remote Adapter Foundation CI hotfix:

`cfb7d784d5f898b6c04a72ac473f69e95d91c603`
`fix(resilience): normalize blank S3 backup configuration`

El checkpoint canÃ³nico es siempre el `HEAD` de
`origin/feature/core-entity` cuando local/remoto coinciden y el repositorio estÃ¡
limpio. Este documento debe mantenerse sincronizado con `docs/README.md`.

Puerta de entrada obligatoria para recuperaciÃ³n:

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

SRCM debe evolucionar hasta ser una **plataforma full de operaciÃ³n comercial**, capaz de simplificar el trabajo real del comerciante sin sacrificar integridad, trazabilidad ni autoridad.

No se busca agregar funciones por cantidad. Se busca que las funciones compartan una verdad operacional comÃºn:

**producto â†’ precio autorizado â†’ stock â†’ venta/compra â†’ cobro/pago â†’ cuenta â†’ verificaciÃ³n â†’ fiscalidad â†’ auditorÃ­a**

Principio APB permanente:

> **Automatizar lo inequÃ­voco; preguntar lo ambiguo; bloquear lo peligroso; nunca corregir silenciosamente una decisiÃ³n humana.**

Los hechos confirmados no se reescriben para â€œhacer coincidirâ€ la realidad. Se corrigen con hechos posteriores, reversos, reemplazos, diferencias o resoluciones auditables.

---

## 1. JerarquÃ­a de verdad para continuidad

Si una conversaciÃ³n se cuelga, se pierde o debe continuar en otro chat:

1. **CÃ³digo + migraciones + tests en el checkpoint Git publicado**.
2. **`docs/06_ROADMAP.md`** â€” mapa ejecutivo y estado.
3. **`docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`** â€” North Star, alcance completo y criterios.
4. ADRs y planes especializados, especialmente `docs/32_PLAN_TERMINAL_COBRO_CUENTAS_CONCILIACION_V1.md`.
5. RESULT de runners validados.
6. ConversaciÃ³n/memoria, Ãºnicamente como apoyo.

Nunca reabrir como pendiente una decisiÃ³n ya implementada y validada salvo regresiÃ³n demostrable.

`docs/README.md` se lee primero como Ã­ndice y puntero de recuperaciÃ³n, pero no
agrega una verdad de dominio ni reemplaza esta jerarquÃ­a.

### Gate irrefutable de continuidad documental

Cada paso que cambie el estado real del proyecto debe sincronizar y publicar,
sin excepciÃ³n, `docs/README.md`, `docs/06_ROADMAP.md` y
`docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`.

Si uno de los tres queda atrasado, no se abre el siguiente corte funcional.

---

## 1.1. Estado maestro tras P11 Production Resilience Baseline V1

El checkpoint funcional `95b9ae392a7c3a038f6c4db55774f238681ff00d` mantiene P1â€“P10 publicados/cerrados en su alcance local y consolida P11 con Security + Observability + Resilience baselines nativos y testeados.

P10 permanece **LOCAL_CLOSURE=GREEN** en `7922c51f7f52995c7137094ec7e8be9cbdd32192`; `REAL_ARCA_HOMOLOGATION`, WSASS e identidad fiscal real siguen diferidos y no bloquean P11.

Security Baseline V1 en `b712081c550d2fba36704ec75678eba1f5b73ff9` conserva headers globales, producciÃ³n fail-closed, step-up de alto impacto y secret/config hygiene.

Observability Baseline V1 en `a17b8aec8ee583dbb121931fd58fc54663de46c7` conserva correlaciÃ³n global, JSON logging, seÃ±ales seguras de queue/jobs/integraciones y readiness operacional.

Resilience Baseline V1 publica:
- snapshot SQLite consistente por `VACUUM INTO`;
- comandos explÃ­citos de backup y restore verification, sin restore real sobre la BD viva;
- scheduler horario y `withoutOverlapping`;
- retenciÃ³n baseline de 168 snapshots;
- SHA-256 + manifiesto + verificaciÃ³n aislada de integridad;
- directorio de producciÃ³n fuera del Ã¡rbol del repo;
- freshness gate de 90 minutos en readiness;
- RPO objetivo 60 minutos y RTO objetivo 240 minutos;
- ADR 132 aceptado.

ValidaciÃ³n: **6/36 focal, 19/112 regresiÃ³n Resilience+Observability+Security y 1077 tests / 8059 assertions GREEN**. Baseline real autoritativa preservada: 107 tablas de negocio, fingerprint `D682F392715CFC9EAE886BD1D865DC60415D345E8369B9071EC89FD3436DAC3D`, schema `F2653BE8FF9B9160A6E544868478E39B7C37E57123E096BC97756CE902D92F42` y 93 migraciones con hash lÃ³gico `03AC754F8B637811B412AB381F881BB55F3C838D77FCE547748878CB5BA6FC14`.

Durante la validaciÃ³n no se respaldÃ³ ni restaurÃ³ la BD real; se usÃ³ SQLite sintÃ©tica temporal. Off-host encrypted backup, proveedor remoto/KMS, CI/CD pipeline y deploy automation siguen abiertos como release gates. ProducciÃ³n continÃºa bloqueada.

PrÃ³xima frontera exacta:
`P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1`.

CI/CD Release Gates V1 publica workflow versionado con checkout fijado por SHA, permisos mÃ­nimos, instalaciones desde lockfiles, `git diff --check`, full suite, build y `srcm:release-preflight --ci`. El preflight inventarÃ­a migraciones sin ejecutar migrate/rollback, exige `down()` no vacÃ­o y valida `GET /api/health/ready`. ProducciÃ³n continÃºa fail-closed: backup off-host cifrado, restore drill operativo y secretos/aprobaciones de producciÃ³n siguen abiertos. ADR: `docs/133_ADR_PRODUCTION_CI_CD_RELEASE_GATES_V1.md`. ValidaciÃ³n: **6/32 focal, 31/180 regresiÃ³n Release+Resilience+Observability+Security, 1083/8091 full + asset build GREEN**.

---

# V1.0 â€” OperaciÃ³n comercial completa / lista para producciÃ³n

Objetivo: que un comercio como SULU pueda operar diariamente usando SRCM como sistema principal, con continuidad, seguridad, fiscalidad y control de dinero reales.

## 2. NÃºcleo ya construido

### Organizaciones, identidad y autoridad
- organizaciones privadas;
- membresÃ­as y roles por organizaciÃ³n;
- personas e identidad comercial central;
- clientes;
- proveedores;
- auditorÃ­a atribuible;
- aislamiento tenant;
- permisos efectivos por membresÃ­a activa.

### CatÃ¡logo y conocimiento
- categorÃ­as;
- marcas;
- fabricantes;
- productos;
- modelos tÃ©cnicos;
- identificadores;
- compatibilidades;
- knowledge bridge;
- bÃºsqueda global;
- importaciÃ³n CSV/XLSX con previsualizaciÃ³n y commit atÃ³mico.

### Inventario
- ubicaciones privadas jerÃ¡rquicas;
- ledger de movimientos;
- proyecciÃ³n de saldos;
- cantidades fraccionables por producto;
- disponibilidad;
- controles de negativos;
- autorizaciones/overrides;
- incidentes y regularizaciones;
- inmutabilidad de hechos confirmados.

### Compras
- proveedores y ofertas;
- Ã³rdenes de compra;
- recepciones parciales;
- costos;
- compras afectadas a reparaciones;
- trazabilidad recepciÃ³n â†’ inventario.

Pendientes de UX ya conocidos:
- costo logÃ­stico esperado;
- costo informado/prepoblado editable;
- cÃ³digo de proveedor visible;
- flujo â€œCompra directa recibidaâ€ sin forzar Oferta/Orden cuando no corresponde.

### Ventas / POS
- venta + pagos + inventario atÃ³micos;
- precios privados y autoridad comercial;
- POS con compositor Ãºnico;
- lookup guiado;
- carrito compacto;
- cantidades editables;
- Enter protegido;
- F1/F3/F7;
- Terminal de Cobro APB;
- pago mÃºltiple;
- evidencia estructurada;
- precios server-authoritative.

### Finanzas P1â€“P3.1
Implementado y publicado:
- P1 â€” Terminal de Cobro APB;
- P2 â€” evidencia estructurada de pagos;
- P3 â€” foundation de cuentas financieras y conciliaciÃ³n;
- P3.1 â€” cuentas operativas y `financial_account_id` por pago.

Verdades financieras separadas:
1. venta;
2. cobro declarado;
3. operaciÃ³n externa;
4. acreditaciÃ³n;
5. conciliaciÃ³n.

Checkpoint P3.1:
`7753e61fba147395362109d90ce895d61442562a`.

### Reparaciones Core
FundaciÃ³n y superficies operativas ya desarrolladas:
- activo/identificadores;
- orden;
- diagnÃ³stico;
- presupuestos versionados;
- decisiÃ³n del cliente;
- trabajo propio/tercerizado;
- custodia;
- repuestos;
- control de calidad;
- entrega;
- garantÃ­a;
- cancelaciones;
- evidencias privadas;
- venta/cobro de servicio.

---

## 3. P4 â€” Caja operativa, turnos, efectivo y pagos a proveedores â€” EN DESARROLLO

P4 amplÃ­a el antiguo concepto â€œEfectivo y vueltoâ€. Su alcance vinculante es:

### P4A â€” Cajas operativas
- mÃºltiples cajas por organizaciÃ³n/sucursal;
- cada caja fÃ­sica/lÃ³gica enlazada a una cuenta financiera `cash_box`;
- caja activa/inactiva sin borrado destructivo;
- identificaciÃ³n clara de terminal/caja.

### P4B â€” Apertura y turno
- sesiÃ³n/turno de caja;
- usuario/cajero responsable;
- apertura;
- fondo inicial;
- una polÃ­tica explÃ­cita para sesiones concurrentes;
- preselecciÃ³n/sugerencia automÃ¡tica del destino efectivo basada en la caja del turno;
- nunca una preferencia eterna olvidable del usuario.

### P4C â€” Movimientos de efectivo
- ventas en efectivo;
- ingresos autorizados;
- retiros parciales;
- retiros de seguridad;
- transferencias internas caja â†’ caja fuerte/tesorerÃ­a;
- pagos operativos autorizados;
- reversos/correcciones append-only;
- motivo y actor obligatorios cuando corresponda.

Un retiro de seguridad **no es un gasto**: el dinero cambia de custodia/cuenta interna.

Hardening vinculante P4C/P4D:
- el cajero solicita el retiro; solicitar no mueve dinero;
- un Administrador/supervisor diferente del solicitante autoriza o rechaza;
- autorizar no mueve dinero;
- el responsable del turno ejecuta fÃ­sicamente una autorizaciÃ³n vigente y de un solo uso;
- `requested_by`, `approved_by` y `executed_by` quedan separados y auditados;
- cambiar turno, caja, origen, destino, importe, moneda, motivo o nota exige nueva autorizaciÃ³n;
- una solicitud pendiente/autorizada debe resolverse antes del cierre;
- el `security_drop` histÃ³rico previo al hardening se conserva sin reescritura;
- la pantalla de turno expone un selector de operaciÃ³n y nunca mantiene Retiro + Arqueo como formularios sensibles simultÃ¡neamente activos.

### P4D â€” Arqueo y cierre
- efectivo esperado;
- efectivo contado;
- denominaciones opcionales;
- diferencia;
- faltante/sobrante explÃ­cito;
- motivo;
- autorizaciÃ³n escalable;
- cierre inmutable;
- historial de sesiones.

### P4E â€” Efectivo entregado/aplicado/vuelto
No confundir:
- importe aplicado a la venta;
- dinero entregado;
- vuelto.

Foundation P4E:
- `commerce_payments.amount_minor` sigue siendo el importe aplicado a la venta;
- en efectivo, SRCM puede conservar por separado `tendered_amount_minor` y `change_amount_minor`;
- el vuelto se deriva como `entregado - aplicado`, nunca altera el total vendido;
- el movimiento de caja registra el importe aplicado/retenido, no infla el esperado con el efectivo transitoriamente recibido antes de dar vuelto;
- cobros histÃ³ricos sin captura P4E conservan `NULL` y no se les inventa evidencia retrospectiva;
- la Terminal de Cobro muestra y confirma aplicado, entregado y vuelto antes del submit irreversible.

Pendiente posterior: vuelto multimedio sin falsear el total vendido.

Criterio de aceptaciÃ³n P4E:
- venta ARS 9.000 + cliente entrega ARS 10.000 + vuelto ARS 1.000 conserva `aplicado=9.000`, `entregado=10.000`, `vuelto=1.000`;
- en la foundation de vuelto efectivo en el mismo medio, el esperado de caja aumenta por el efectivo neto retenido, no por el efectivo transitorio antes de dar vuelto;
- `entregado` y `vuelto` sÃ³lo pertenecen a lÃ­neas Efectivo;
- los cobros histÃ³ricos sin esa captura permanecen explÃ­citamente sin evidencia;
- vuelto multimedio futuro requerirÃ¡ hechos de movimiento por cada origen/destino y no se resolverÃ¡ falseando `amount_minor`.

### P4F â€” Pago a proveedores en recepciÃ³n
Separar siempre:
1. recepciÃ³n fÃ­sica;
2. obligaciÃ³n econÃ³mica;
3. autorizaciÃ³n;
4. ejecuciÃ³n del pago;
5. cuenta/caja de origen;
6. beneficiario;
7. evidencia.

Casos:
- mercaderÃ­a pagada contra entrega;
- pago parcial;
- pago pendiente;
- pago al vendedor/proveedor;
- transportista autorizado;
- flete separado de mercaderÃ­a;
- lÃ­mites por rol/importe;
- escalamiento a encargado/administrador/dueÃ±o.

**Confirmar una recepciÃ³n de stock nunca paga automÃ¡ticamente.**

P4F se construirÃ¡ por slices compatibles con P9:

**P4F.1 â€” ObligaciÃ³n Foundation**
- obligaciÃ³n privada por organizaciÃ³n;
- origen en recepciÃ³n/documento/condiciÃ³n comercial explÃ­cita;
- mercaderÃ­a y logÃ­stica separables;
- proveedor/beneficiario estructurado;
- importe, moneda, vencimiento/condiciÃ³n, estado, idempotencia y fingerprint;
- recepciÃ³n conforme puede preparar una obligaciÃ³n segÃºn polÃ­tica, nunca ejecutar el pago.

Contrato de Foundation P4F.1:
- la obligaciÃ³n se reconoce explÃ­citamente desde una `PurchaseReceipt` confirmada;
- `merchandise` y `logistics` son componentes separados y cada uno puede tener beneficiario distinto;
- importe y moneda derivan de la recepciÃ³n/orden y no son editables por el usuario;
- una recepciÃ³n admite como mÃ¡ximo una obligaciÃ³n por componente; el pago parcial futuro se aplica sobre esa obligaciÃ³n, no se crean deudas duplicadas para simular cuotas;
- el beneficiario es `BusinessParty` privada de la organizaciÃ³n y por defecto puede ser la identidad del proveedor;
- condiciÃ³n de pago estructurada: contra recepciÃ³n, vencimiento en fecha u otra condiciÃ³n explicada;
- la obligaciÃ³n es inmutable, idempotente y auditable;
- no crea `CashMovement`, no toca `FinancialAccount`, no cambia Inventario y no representa autorizaciÃ³n ni ejecuciÃ³n.

**P4F.2 â€” Solicitud y autorizaciÃ³n**
- solicitud, autorizaciÃ³n, rechazo/cancelaciÃ³n y expiraciÃ³n explÃ­citos;
- actores y timestamps separados;
- lÃ­mites por capacidad/importe;
- segregaciÃ³n de funciones cuando la polÃ­tica lo exija;
- autorizaciÃ³n ligada por fingerprint a obligaciÃ³n, beneficiario, importe, moneda y origen propuesto.

Contrato Foundation P4F.2:
- la solicitud nace exclusivamente desde una `PurchaseObligation` reconocida;
- beneficiario y moneda se heredan de la obligaciÃ³n y no son editables;
- el solicitante puede proponer un importe total o parcial mayor que cero y nunca superior a la obligaciÃ³n;
- el origen propuesto es una `FinancialAccount` activa, privada de la organizaciÃ³n y de la misma moneda;
- sÃ³lo puede existir una solicitud activa (`pending` o `approved`) por obligaciÃ³n;
- Operador/Administrador pueden solicitar; Administrador aprueba, rechaza o expira;
- `solicitante != aprobador` se aplica de forma fail-closed;
- una solicitud aprobada ya no se rechaza: puede cancelarse o expirar antes de la futura ejecuciÃ³n;
- solicitante o Administrador pueden cancelar con motivo explÃ­cito;
- solicitud y aprobaciÃ³n tienen claves de idempotencia y fingerprints separados;
- el fingerprint autorizable liga obligaciÃ³n, huella de obligaciÃ³n, beneficiario, importe, moneda, origen y contexto;
- Operational Attention lleva el pendiente al aprobador y el resultado/acciÃ³n al solicitante;
- ninguna transiciÃ³n P4F.2 crea `CashMovement`, altera `FinancialAccount`, modifica Inventario ni marca la obligaciÃ³n como pagada;
- P4F.3 reforzarÃ¡ el importe disponible con el ledger real de ejecuciones y consumirÃ¡ la autorizaciÃ³n mediante un hecho de desembolso, no reescribiendo la obligaciÃ³n.

**P4F.3 â€” EjecuciÃ³n**
- pago total o parcial;
- cuenta/caja de origen explÃ­cita;
- efectivo sÃ³lo desde turno vÃ¡lido y crea egreso `CashMovement` al ejecutar;
- obligaciÃ³n o autorizaciÃ³n por sÃ­ solas no alteran el efectivo esperado;
- medios no efectivo generan su hecho financiero saliente sin inventar un movimiento de caja;
- evidencia segura y hora de servidor.

Contrato Foundation P4F.3 â€” efectivo:
- una autorizaciÃ³n se consume exactamente por el importe autorizado; el ejecutor no puede recortarlo ni ampliarlo silenciosamente;
- un pago parcial de la obligaciÃ³n se modela autorizando un importe parcial, ejecutÃ¡ndolo y luego creando otra solicitud sÃ³lo por el saldo econÃ³mico pendiente;
- `PurchasePaymentExecution` es un hecho separado, inmutable, idempotente y auditable; no reescribe `PurchaseObligation` ni la aprobaciÃ³n P4F.2;
- el saldo ejecutable deriva de `obligaciÃ³n original - SUM(ejecuciones confirmadas)`;
- Foundation ejecuta sÃ³lo `cash_box`; banco/billetera quedan para el hecho financiero saliente y verificaciÃ³n externa posteriores;
- el ejecutor debe tener capacidad de ejecuciÃ³n, no puede ser el aprobador y debe poseer un turno abierto propio sobre la caja autorizada;
- al ejecutar se revalida la huella de aprobaciÃ³n, obligaciÃ³n, beneficiario, importe, moneda, origen, turno y efectivo esperado actual;
- la ejecuciÃ³n y su `CashMovement::purchase_payment` nacen en la misma transacciÃ³n; el movimiento es `out`, sin destino interno, sin `CommercePayment` y sin disfrazarse de retiro de seguridad;
- sÃ³lo entonces la solicitud pasa `approved -> executed` y deja de admitir cancelaciÃ³n o vencimiento;
- reintentar la misma clave de ejecuciÃ³n devuelve el mismo hecho; otra clave no duplica el pago;
- la DB bloquea mutaciÃ³n/borrado de ejecuciÃ³n y movimiento y exige vÃ­nculo estructurado entre autorizaciÃ³n, ejecuciÃ³n y egreso;
- P4F.3 Foundation no inventa ejecuciÃ³n retroactiva para autorizaciones histÃ³ricas y no crea hechos no efectivo;
- P4F.4 completarÃ¡ la distribuciÃ³n avanzada de ejecuciÃ³n/resultado por Attention y controles adicionales.

**P4F.4 â€” AtenciÃ³n y control**
- pendientes encuentran al aprobador mediante Operational Attention;
- autorizaciones encuentran al ejecutor;
- rechazo/cancelaciÃ³n vuelve al solicitante;
- pago externo y dÃ©bito/acreditaciÃ³n verificada permanecen separados hasta conciliaciÃ³n;
- diferencias nunca se corrigen ni pagan silenciosamente.

Contrato Foundation P4F.4:
- una ejecuciÃ³n confirmada vuelve al solicitante como `resultado` acknowledgeable; Attention no crea ni modifica el pago;
- el estado de control forma parte de la clave proyectada: si el solicitante reconoce el resultado con turno abierto y luego el arqueo cambia el control a `exacto` o `con diferencia`, el nuevo resultado puede aparecer sin reescribir el anterior;
- para efectivo, `PurchasePaymentControlReader` deriva control sÃ³lo de `PurchasePaymentExecution + CashMovement + CashRegisterSession + CashRegisterClosure`;
- mientras el turno estÃ¡ abierto, el egreso estÃ¡ registrado pero el control fÃ­sico queda pendiente del arqueo/cierre;
- un cierre sin diferencia confirma el control de Caja, no crea una conciliaciÃ³n financiera adicional;
- un cierre con diferencia la muestra explÃ­citamente y jamÃ¡s altera, compensa ni vuelve a pagar la ejecuciÃ³n confirmada;
- efectivo no crea `FinancialExternalMovement` ni `payment_reconciliation`: la verificaciÃ³n externa no aplica a un egreso fÃ­sico de Caja;
- el motor P3 de movimientos externos/conciliaciÃ³n permanece como verdad separada para banco, billetera y procesadores; P4F.4 no fuerza una conciliaciÃ³n de egresos todavÃ­a inexistentes;
- cualquier inconsistencia estructural entre ejecuciÃ³n y `CashMovement` se proyecta como anomalÃ­a de control; SRCM no inventa movimientos para cuadrarla.

ADR rector: `docs/35_ADR_HECHOS_MONETARIOS_APLICADO_ENTREGADO_VUELTO_OBLIGACION_DESEMBOLSO_V1.md`.

---

## 4. P5 â€” Operaciones externas y adaptadores

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

**P5.1 â€” Provider-neutral Ingestion Foundation**
- `financial_provider_connections` vincula organizaciÃ³n + `FinancialAccount` + proveedor + ID externo sin guardar secretos;
- una cuenta de efectivo nunca se conecta a un proveedor financiero externo;
- una cuenta conectada conserva tipo, proveedor y moneda como identidad estable;
- `ExternalFinancialProviderAdapter` normaliza payload provider-specific a una observaciÃ³n financiera segura;
- `ExternalFinancialProviderIngestor` admite Ãºnicamente API/webhook/polling y siempre termina en `ExternalFinancialMovementRecorder`;
- la ingestiÃ³n automÃ¡tica no inventa un usuario humano: `created_by_user_id` puede ser NULL y la auditorÃ­a conserva el hecho igualmente;
- la misma `financial_account + external_operation_id + status` con mismos importes es idempotente incluso si reaparece por otro canal;
- la misma operaciÃ³n/estado con contenido financiero distinto falla cerrado;
- una transiciÃ³n de estado crea un nuevo `FinancialExternalMovement` inmutable; nunca actualiza el anterior;
- registrar evidencia externa no concilia, no modifica Venta, no modifica Caja y no paga nada;
- P5.2 incorporarÃ¡ el primer adaptador concreto, autenticaciÃ³n/firma, secretos, jobs, retry y observabilidad.

ADR rector: `docs/36_ADR_ADAPTADORES_OPERACIONES_EXTERNAS_PROVIDER_NEUTRAL_V1.md`.

---

## 5. P6 â€” Centro de ConciliaciÃ³n

- cobro esperado;
- movimiento externo;
- bruto;
- neto;
- comisiÃ³n;
- retenciones;
- matching;
- diferencia;
- pendiente de revisiÃ³n;
- asignaciones;
- resoluciÃ³n;
- trazabilidad;
- conciliaciÃ³n parcial y mÃºltiple cuando el dominio lo requiera.

---

## 6. P7 â€” Instituciones sin API

- importaciÃ³n CSV/XLSX;
- previsualizaciÃ³n;
- normalizaciÃ³n;
- mapeo configurable;
- detecciÃ³n de duplicados;
- idempotencia;
- conciliaciÃ³n contra el mismo motor;
- fallback manual explÃ­cito y auditable.

API primero; importaciÃ³n despuÃ©s; manual sÃ³lo cuando no exista alternativa razonable.

---

## 7. P8 â€” Posventa comercial completa

**Estado V1: CERRADO / GREEN â€” P8.5.8.**
La implementaciÃ³n V1 quedÃ³ cerrada por auditorÃ­a integral read-only sobre el checkpoint P8.5.7: contratos, 17 rutas HTTP, outcomes, ejecuciÃ³n final, crÃ©dito convergente y guardas regresaron GREEN; la suite completa quedÃ³ en 715 tests / 5948 assertions. Mercado Pago Refund conserva su gate DEGRADED/BLOCKED segÃºn ADR 65 y no se considera una brecha de P8 mientras SRCM mantenga el comportamiento fail-closed.

- devoluciones parciales/totales;
- cambios;
- reembolsos;
- crÃ©dito/saldo a favor;
- devoluciÃ³n al medio original cuando corresponda;
- diferencia de precio;
- devoluciÃ³n de stock con condiciÃ³n real;
- trazabilidad a venta original;
- nunca editar retrospectivamente la venta original;
- integraciÃ³n futura con notas de crÃ©dito fiscales.

---

## 8. P9 â€” Cuentas por cobrar y cuentas por pagar

**Estado actual: P9.1â€“P9.8 PUBLICADOS / P9 CxC-CxP V1 CERRADO.**

### CxC
- cuenta corriente de cliente;
- ventas a crÃ©dito;
- vencimientos;
- lÃ­mites;
- anticipos/seÃ±as;
- cuotas propias;
- cobranzas parciales;
- un cobro aplicado a una o varias deudas;
- saldos a favor;
- aging.

### CxP
- factura/documento de proveedor;
- orden;
- recepciÃ³n;
- obligaciÃ³n;
- vencimiento;
- pago parcial;
- pago agrupado;
- anticipos;
- notas de crÃ©dito;
- 3-way match progresivo:
  **orden â†” recepciÃ³n â†” documento del proveedor**;
- diferencias explÃ­citas antes de pagar.

### Checkpoints publicados de P9

- P9.1: cuenta por cobrar;
- P9.2: cobranza e imputaciones;
- P9.3: aging y exposiciÃ³n derivada;
- P9.4: polÃ­tica de crÃ©dito y override;
- P9.5: cuotas propias;
- P9.6a: anticipos de clientes;
- P9.6b: excedente de cobranza a crÃ©dito;
- P9.7a: documento/factura de proveedor;
- P9.7b: 3-way match derivado;
- P9.7c: relevamiento de brechas, sin nueva verdad productiva;
- P9.7dâ€“P9.7e: nota de crÃ©dito y aplicaciÃ³n a obligaciÃ³n;
- P9.7fâ€“P9.7g: anticipo a proveedor y convergencia de crÃ©dito;
- P9.7h: autorizaciÃ³n agrupada;
- P9.7i: desembolso canÃ³nico individual/agrupado, cash/non-cash.
- P9.7j: convergencia HTTP/UI y control posterior individual/agrupado,
  cash/non-cash.
- P9.7k: verificaciÃ³n append-only de desembolso non-cash contra
  `FinancialExternalMovement` `Debit + Posted`, con selecciÃ³n Admin,
  exclusividad de evidencia y diferencias explÃ­citas.
- P9.7l: resoluciÃ³n/derivaciÃ³n append-only por observaciÃ³n externa, con
  snapshots de diferencia, comisiÃ³n y retenciÃ³n, sin modificar CxP ni fingir
  contabilidad inexistente.
- P9.8: exposiciÃ³n y aging CxP derivados, vencimiento efectivo, agregaciÃ³n por
  proveedor/beneficiario/moneda y estado de cuenta sin saldo paralelo.

P9 queda cerrado en V1. P10 RECON confirmÃ³ sobre `312b0520` que la venta
comercial ya es una verdad independiente y que la capa fiscal aÃºn no existe.

P10.1 incorpora la configuraciÃ³n fiscal argentina por organizaciÃ³n y puntos de
venta por ambiente, sin documento, numeraciÃ³n, autorizaciÃ³n ni HTTP externo.

---

## 9. P10 â€” Fiscalidad argentina / ARCA

Objetivo: integrar la fiscalidad argentina sin convertirla en la verdad primaria del negocio.

Contrato vinculante:

**Venta comercial â‰  comprobante fiscal â‰  autorizaciÃ³n fiscal.**

Toda venta confirmada debe existir en SRCM con independencia de que el circuito fiscal estÃ© pendiente, autorizado, rechazado, en contingencia o legalmente no aplicable al caso concreto. La fiscalidad se modela como una capa separada y auditable.

SRCM no debe incorporar mecanismos destinados a ocultar ventas, suprimir operaciones confirmadas ni producir documentaciÃ³n falsa para evadir obligaciones. SÃ­ debe representar fielmente la situaciÃ³n real de cada operaciÃ³n y permitir distinguir, consultar y gestionar su estado fiscal.

Estados fiscales orientativos:
- no iniciado;
- pendiente;
- autorizado;
- rechazado;
- contingencia;
- anulado/corregido mediante documento fiscal posterior;
- no aplicable cuando jurÃ­dicamente corresponda.

- configuraciÃ³n fiscal por organizaciÃ³n;
- puntos de venta;
- numeraciÃ³n correlativa;
- WSAA;
- WSFEv1 como integraciÃ³n principal cuando corresponda;
- evaluar WSMTXCA si el caso requiere detalle de Ã­tems;
- CAE;
- CAEA/contingencia donde aplique;
- comprobantes A/B/C/M y otros requeridos por el negocio;
- notas de crÃ©dito/dÃ©bito;
- QR;
- comprobante imprimible/digital;
- homologaciÃ³n;
- reintentos idempotentes;
- separaciÃ³n entre venta confirmada y autorizaciÃ³n fiscal;
- nunca inventar CAE ni numeraciÃ³n;
- arquitectura preparada para otras jurisdicciones sin rediseÃ±ar Comercio.

### P10.1 â€” Fiscal Configuration Foundation V1

- `FiscalOrganizationProfile` separado de `Organization`;
- CUIT validado, razÃ³n social, condiciÃ³n IVA referenciada, IIBB, inicio de
  actividades y domicilio fiscal;
- `FiscalPointOfSale` por organizaciÃ³n, ambiente y nÃºmero;
- homologaciÃ³n y producciÃ³n explÃ­citamente separadas;
- modos `wsfe_v1` y `wsmtxca` representables sin activaciÃ³n remota;
- identidad del punto inmutable, baja lÃ³gica y prohibiciÃ³n de borrado fÃ­sico;
- administraciÃ³n exclusiva, tenancy, transacciÃ³n, locks y auditorÃ­a;
- cero dependencia desde `CommerceSale` hacia la capa fiscal;
- cero numeraciÃ³n fiscal, WSAA, WSFE, CAE, CAEA o QR en este corte.

### P10.2 â€” Fiscal Document Core V1

- `FiscalDocument` y sus lÃ­neas son evidencia fiscal append-only, separada de la venta comercial;
- snapshots inmutables de emisor, receptor, moneda, importes y lÃ­neas;
- el estado es derivado (`pending`) mientras no exista un hecho de autorizaciÃ³n;
- sin numeraciÃ³n, CAE, QR, alÃ­cuotas, credenciales, adapter ni comunicaciÃ³n ARCA.

### P10.3 â€” Fiscal Authorization Facts V1

- intentos y respuestas de autorizaciÃ³n como hechos separados e inmutables;
- outcomes explÃ­citos y estado fiscal derivado desde la evidencia;
- ningÃºn resultado remoto reescribe la venta ni el documento fiscal.

### P10.4 â€” Fiscal Document Numbering V1

- numeraciÃ³n fiscal interna separada de `CommerceSale.sale_number`;
- identidad por documento/punto/ambiente y evidencia inmutable;
- la numeraciÃ³n interna nunca reemplaza CAE ni autoridad ARCA.

### P10.5 â€” Fiscal Authorization Integration Boundary V1

- contratos provider-neutral para adapter, transporte, credenciales, request y result;
- separaciÃ³n entre Core fiscal y proveedor externo;
- todavÃ­a sin WSAA, HTTP, secretos ni ejecuciÃ³n remota.

### P10.6 â€” External Execution / Homologation Readiness

- P10.6 RECON confirmÃ³ que transporte HTTP, credential store efectivo y ejecuciÃ³n externa seguÃ­an ausentes;
- P10.6.1 publicÃ³ readiness/configuraciÃ³n de homologaciÃ³n sin secretos ni llamadas externas;
- P10.6.2 preflight read-only confirmÃ³ homologaciÃ³n/producciÃ³n deshabilitadas;
- ejecuciÃ³n real sigue bloqueada hasta completar credenciales, runtime y homologaciÃ³n controlada.

### P10.7 â€” Fiscal Classification & Tax Evidence

#### P10.7.1 â€” Fiscal Recipient & Tax Policy V1
- perfil fiscal de contraparte separado de la identidad comercial;
- polÃ­tica tributaria versionada por organizaciÃ³n;
- sin inferir comprobante ni IVA desde la venta.

#### P10.7.2 â€” Fiscal Tax Composition V1
- bases, tasas e importes tributarios explÃ­citos por documento;
- evidencia inmutable;
- sin recalcular silenciosamente desde precios comerciales.

#### P10.7.3 â€” Fiscal Voucher Classification V1
- clase/cÃ³digo fiscal explÃ­cito e inmutable por documento;
- ninguna regla automÃ¡tica selecciona comprobante desde la venta.

#### P10.7.4 â€” Fiscal Concept & Service Period V1
- concepto fiscal explÃ­cito: productos, servicios o ambos;
- servicios y mixtos exigen perÃ­odo desde/hasta vÃ¡lido;
- evidencia inmutable y separada;
- sin inferencia desde venta y sin activar ARCA.

### P10 â€” Fiscal Payload Completeness & WSFE Request Readiness â€” PUBLICADO

Sin inventar subnumeraciÃ³n adicional, quedaron publicados por nombre:

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

Resultado: el `FeCAEReq` estÃ¡ndar se compone desde evidencia fiscal explÃ­cita,
usa candidato de secuencia remota y llega al transport sin secretos ni
dependencia de `FiscalDocumentNumber` local.

### P10 â€” WSAA / Endpoint / SOAP / Response / Convergence Readiness â€” PUBLICADO

- WSAA Access Ticket Provider, material X.509 boundary, signer CMS y transporte `LoginCms` permanecen publicados para homologaciÃ³n;
- `FiscalAuthorizationRuntimeScopeStore` â†’ `EnvironmentFiscalAuthorizationRuntimeScopeStore` resuelve `service + issuer_cuit` sÃ³lo desde configuraciÃ³n WSAA tenant-scoped;
- `FiscalAuthorizationCredentialStore` queda enlazado sin dereferenciar material criptogrÃ¡fico;
- `FiscalRemoteSequenceAuthority` â†’ `WsaaBackedFiscalRemoteSequenceAuthority` usa readiness â†’ scope â†’ TA â†’ `FECompUltimoAutorizado`;
- `FiscalAuthorizationTransport` â†’ `WsaaBackedFiscalAuthorizationTransport` usa readiness â†’ scope â†’ TA â†’ `FECAESolicitar` â†’ normalizer â†’ convergence;
- `WsfeFecaeRequestComposerContract` â†’ `WsfeFecaeRequestComposer`;
- `WsfeFecaeProviderResponseNormalizerContract` y `WsfeFecaeProviderResultConvergenceContract` quedan enlazados;
- los wire boundaries DOM/Guzzle para ambas operaciones permanecen homologaciÃ³n-only, TLS-verified, acotados y sin retry automÃ¡tico;
- homologaciÃ³n deshabilitada corta antes de TA/wire; producciÃ³n, WSASS, identidad real y homologaciÃ³n externa permanecen bloqueados/diferidos.

ValidaciÃ³n del corte: **62/329 focal, 89/461 regresiÃ³n fiscal y 1052 tests / 7911 assertions GREEN**. BD real lÃ³gica y canary binario permanecieron intactos.

**Cierre local P10:** GREEN en `7922c51f7f52995c7137094ec7e8be9cbdd32192`. La arquitectura local fiscal queda completa y testeada; homologaciÃ³n ARCA real y WSASS permanecen como deuda externa diferida. P11 ya fue abierto y su Production Security Baseline V1 estÃ¡ publicado en `b712081c550d2fba36704ec75678eba1f5b73ff9`.

---

## 10. P11 â€” ProducciÃ³n, seguridad, observabilidad y recuperaciÃ³n

Antes de depender de SRCM como sistema Ãºnico:

**Estado actual:** Security Baseline V1 `b712081c550d2fba36704ec75678eba1f5b73ff9`, Observability Baseline V1 `a17b8aec8ee583dbb121931fd58fc54663de46c7` y Resilience Baseline V1 `95b9ae392a7c3a038f6c4db55774f238681ff00d` publicados. El Ãºltimo corte validÃ³ **6/36 focal, 19/112 regresiÃ³n Resilience+Observability+Security y 1077 tests / 8059 assertions GREEN** con BD canÃ³nica intacta. La prÃ³xima frontera es `P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1`.

### Seguridad
- **PUBLICADO baseline V1:** headers globales conservadores; configuraciÃ³n de producciÃ³n fail-closed; step-up por contraseÃ±a reciente en operaciones de alto impacto; guard de secret/config hygiene; ADR 130;
- MFA/passkeys, PIN supervisor dedicado y gestiÃ³n avanzada de dispositivos â€” diferidos;
- CSP/Permissions-Policy â€” diferidos hasta inventario especÃ­fico de scripts/assets/hardware.

### Observabilidad
- **PUBLICADO baseline V1:** request/correlation IDs globales; contexto de excepciÃ³n; JSON `stderr_json`; seÃ±ales de queue/job/integraciÃ³n; readiness `/api/health/ready`; ADR 131;
- OpenTelemetry, mÃ©tricas externas, tracing distribuido, alert provider y Horizon/Telescope â€” diferidos.

### Resiliencia
- **PUBLICADO baseline V1:** `VACUUM INTO` para snapshots SQLite consistentes, comando de backup, scheduler horario, SHA/manifiesto, retenciÃ³n 168, restore verification aislada, freshness gate 90 min, RPO 60 min y RTO 240 min; ADR 132;
- el baseline no expone restore automÃ¡tico sobre la BD viva y las pruebas no tocaron la BD real;
- producciÃ³n exige directorio de backup fuera del Ã¡rbol SRCM;
- backup off-host cifrado y KMS/proveedor remoto siguen como release gate.

### CI/CD y release engineering
**SIGUIENTE RECON:** `P11_OFF_HOST_S3_PROVIDER_CONFIGURATION_RECON_V1`. Debe verificar, sin mutar:
- workflows/pipelines realmente versionados;
- gate automÃ¡tico de suite, lint/build y dependencias;
- estrategia de migraciones en deploy y rollback tÃ©cnico;
- artefactos/versionado/promociÃ³n entre ambientes;
- secretos y permisos de CI;
- release gate de backup off-host cifrado y restore drill operativo;
- criterios concretos que faltan para desbloquear producciÃ³n.

No se implementa pipeline, deploy automation ni proveedor remoto por presunciÃ³n antes del RECON.

### Integraciones robustas
- outbox/eventos internos;
- reintentos;
- idempotency keys;
- dead-letter/revisiÃ³n;
- webhooks firmados;
- versionado de contratos.

Outbox permanece diferido hasta despuÃ©s de los release gates de P11.

---

## 11. P12 â€” Continuidad offline y hardware POS

### Offline restringido
- continuidad temporal de venta cuando sea seguro;
- cache mÃ­nimo necesario;
- cola local firmada/identificada;
- replay idempotente;
- conflicto explÃ­cito al reconectar;
- nunca esconder sobreventas o conflictos;
- fiscalidad offline sÃ³lo bajo mecanismos legalmente vÃ¡lidos;
- no depender exclusivamente de una API de navegador de soporte desigual.

### Hardware
Adaptadores, no dependencias rÃ­gidas:
- scanner 1D/2D;
- impresora tÃ©rmica;
- cajÃ³n portamonedas;
- balanza;
- impresora de etiquetas;
- display de cliente;
- terminales de pago;
- NFC/QR;
- dispositivos Android/desktop;
- futuras etiquetas electrÃ³nicas.

### SRCM Customer Kiosk / Price Checker
Modo autoservicio conectado al mismo catÃ¡logo maestro:
- escaneo EAN/UPC/GTIN/QR/DataMatrix;
- nombre, foto y precio vigente;
- promociones;
- caracterÃ­sticas y variantes;
- unidad de venta;
- stock/ubicaciÃ³n cuando la polÃ­tica lo permita;
- compatibilidades y Knowledge Universe;
- productos relacionados;
- consulta de otras sucursales;
- futura reserva/preventa desde kiosco.

Puede desplegarse sobre hardware dedicado o tablet/Android/PC + scanner. El kiosco consulta la misma verdad comercial: no mantiene una segunda lista de precios.

### Seguridad de tienda / Loss Prevention
IntegraciÃ³n progresiva con:
- EAS tradicional;
- tags RF/AM;
- RFID UHF/EPC item-level;
- antenas/portales de salida;
- desactivadores/removedores en caja;
- correlaciÃ³n con POS;
- eventos de seguridad;
- futura correlaciÃ³n con CCTV cuando exista integraciÃ³n autorizada.

Un cÃ³digo de barras ordinario no sustituye un tag EAS/RFID.

ProgresiÃ³n:
`EAS bÃ¡sico â†’ RFID/EPC item-level â†’ Smart Exit / Loss Prevention`.

Con identificaciÃ³n item-level, SRCM podrÃ¡ distinguir salida autorizada de artÃ­culo sin venta/transferencia/salida vÃ¡lida y conservar evento, hora, sucursal, puerta y artÃ­culo.

---

# Universo comercial objetivo de SRCM Full

SRCM se diseÃ±a como plataforma horizontal para **retail + mayorista + distribuciÃ³n + servicios + reparaciÃ³n + omnicanalidad**, con verticales especÃ­ficos sÃ³lo cuando un rubro requiera reglas propias.

Rubros y operaciones objetivo, entre otros:

- kioscos, maxikioscos, almacenes, despensas, minimercados, autoservicios y supermercados;
- bazares, regalerÃ­as, jugueterÃ­as, librerÃ­as, papelerÃ­as, perfumerÃ­as y comercios multirrubro;
- indumentaria, calzado, marroquinerÃ­a y accesorios;
- ferreterÃ­as, corralones, casas de electricidad, sanitarios, pinturerÃ­as, bulonerÃ­as, madereras y materiales para obra;
- celulares, computaciÃ³n, electrÃ³nica, televisores, audio, electrodomÃ©sticos, seguridad y telecomunicaciones;
- autopartes, motopartes, lubricantes, neumÃ¡ticos, baterÃ­as, repuestos agrÃ­colas y talleres con venta;
- servicios tÃ©cnicos de electrÃ³nica, informÃ¡tica, electrodomÃ©sticos, herramientas, maquinaria y otros equipos;
- mayoristas, distribuidores, importadores y empresas con mÃºltiples depÃ³sitos;
- negocios de productos fraccionados por metro, kilo, litro, unidad u otra magnitud;
- comercios de alimentos/consumo masivo con lote, vencimiento y trazabilidad;
- muebles, decoraciÃ³n, iluminaciÃ³n, hogar, jardÃ­n, camping y equipamiento;
- negocios de alto valor con seguimiento por serie/IMEI/SN;
- empresas con varias cajas, varias sucursales y mÃºltiples canales;
- venta por pedido, preventa, reserva, seÃ±a y crÃ©dito comercial;
- servicios con consumo de materiales/repuestos;
- PYMEs y empresas familiares que hoy operan parcialmente con planillas, Drive, papel y mensajerÃ­a.

SRCM no se declararÃ¡ automÃ¡ticamente especialista vertical de restaurantes, hoteles, clÃ­nicas, farmacias, estaciones de servicio o fÃ¡bricas MRP completas. Esos dominios podrÃ¡n construirse como verticales cuando exista necesidad real, reutilizando el Core comÃºn sin degradarlo.

La meta es profundidad real en el comercio, no convertirse en un producto superficial para todas las industrias.

---

# V1.5 â€” Retail omnicanal y crecimiento

## 12. P13 â€” Reservas, holds, concurrencia y carrito persistente
- holds POS temporales;
- reservas formales;
- concurrencia multicanal;
- prevenciÃ³n de sobreventa;
- carrito persistente/recuperable;
- recuperaciÃ³n por cliente/WhatsApp;
- expiraciones;
- prioridad y autoridad;
- no crear movimientos fÃ­sicos ocultos por cada carrito.

## 13. P14 â€” Multi-sucursal y fulfillment
- sucursales;
- depÃ³sitos;
- cajas por sucursal;
- stock por ubicaciÃ³n;
- transferencias;
- trÃ¡nsito;
- recepciÃ³n de transferencias;
- retiro en tienda;
- ship-from-store;
- entrega;
- picking/packing progresivo.

## 14. P15 â€” Omnicanalidad, publicaciones y SULU Media
- WhatsApp Business;
- Instagram;
- ecommerce;
- marketplaces;
- catÃ¡logo compartido;
- stock compartido;
- precio por canal;
- campaÃ±as;
- publicaciÃ³n automÃ¡tica;
- pausa por agotado;
- trazabilidad publicaciÃ³n â†’ venta;
- mÃ³dulo SULU Media/cartelerÃ­a digital;
- programaciÃ³n de piezas;
- SRCM Player;
- contenido derivado de ficha de producto y campaÃ±as.

## 15. P16 â€” Motor comercial avanzado
- listas de precios;
- minorista/mayorista;
- escalas por cantidad;
- promociones;
- combos;
- cupones;
- descuentos con autoridad;
- margen mÃ­nimo;
- reglas por canal;
- precios programados;
- fidelizaciÃ³n;
- gift cards;
- puntos/recompensas;
- campaÃ±as segmentadas.

## 16. P17 â€” GS1, 2D, lotes, series y etiquetado
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

## 17. P18 â€” ReposiciÃ³n, planificaciÃ³n y proveedores
- mÃ­nimos/mÃ¡ximos;
- punto de pedido;
- lead time;
- stock proyectado;
- Ã³rdenes sugeridas;
- estacionalidad;
- forecasting;
- supplier scorecards;
- precio/plazo/calidad;
- compras consolidadas;
- alertas de quiebre.

Primero reglas determinÃ­sticas; IA despuÃ©s.

## 18. P19 â€” CRM y analÃ­tica
- ficha 360 del cliente;
- historial;
- frecuencia;
- ticket medio;
- preferencias;
- segmentaciÃ³n;
- consentimiento;
- campaÃ±as;
- cohortes;
- margen;
- rotaciÃ³n;
- aging;
- caja;
- compras;
- reparaciones;
- BI/export;
- dashboards por rol.

## P20 â€” Tablero de MÃ³dulos, Capacidades y Superficies

Objetivo: que una misma plataforma SRCM pueda adaptarse desde un comercio pequeÃ±o hasta una empresa multisucursal compleja sin bifurcar el producto ni obligar a cada usuario a convivir con funciones que no necesita.

Cadena de autoridad futura:

`plataforma disponible â†’ organizaciÃ³n habilita mÃ³dulos â†’ autoridad delega capacidades â†’ alcance limita dÃ³nde â†’ usuario recibe su superficie`

Contrato:
- DueÃ±o/Admin general habilita o deshabilita mÃ³dulos de la organizaciÃ³n;
- administradores de segundo nivel reciben delegaciÃ³n limitada por capacidades y alcance;
- roles como Admin/Operator/Viewer evolucionan a presets iniciales, no a la Ãºnica fuente de autoridad;
- el menÃº y las acciones visibles derivan de mÃ³dulo habilitado + capacidad + alcance;
- ocultar UI nunca sustituye autorizaciÃ³n de backend/DB;
- desactivar un mÃ³dulo nunca borra historia: puede ocultar, dejar read-only o reactivar;
- dependencias entre mÃ³dulos deben ser explÃ­citas;
- presets por rubro son recomendaciones editables, nunca jaulas;
- mÃ³dulos podrÃ¡n habilitarse globalmente o, cuando exista P14, por sucursal/Ã¡mbito;
- operaciones sensibles pueden exigir segregaciÃ³n solicitante/autorizador/ejecutor y umbrales futuros.

Presets orientativos:
- Retail general;
- Moda y belleza;
- Electro / tecnologÃ­a;
- Repuestos / autopartes;
- Servicios y reparaciones;
- Mayorista / distribuidor;
- ConfiguraciÃ³n personalizada.

El **catÃ¡logo comercial universal** â€”producto, variantes, SKU/cÃ³digos, marca, categorÃ­a, precio, fotos y stockâ€” debe funcionar plenamente sin Knowledge Universe. Modelos tÃ©cnicos, compatibilidades, assertions y conocimiento enriquecido son una **capacidad avanzada opcional** que una organizaciÃ³n puede habilitar cuando aporta valor real a su rubro.

Principio vinculante:

> **La potencia total pertenece a SRCM; la complejidad visible pertenece sÃ³lo a quien la necesita.**

P20 completo se implementarÃ¡ en un bloque propio. Desde ahora cada mÃ³dulo nuevo debe diseÃ±arse compatible con este contrato.

### Centro de AtenciÃ³n Operativa â€” capacidad transversal

SRCM debe llevar los pendientes hacia la persona que puede resolverlos y los resultados hacia quien necesita conocerlos. NingÃºn usuario deberÃ­a recorrer mÃ³dulos para descubrir una autorizaciÃ³n pendiente, un Override por revisar o el resultado de una decisiÃ³n que iniciÃ³.

Base transversal:
- campana superior con contador por actor;
- bandeja de atenciÃ³n con deep-links al hecho exacto;
- bloque `Requiere tu atenciÃ³n` en Dashboard;
- separaciÃ³n entre `acciÃ³n requerida` y `resultado a conocer`;
- filtrado por organizaciÃ³n + actor + capacidad + alcance;
- el hecho de dominio sigue siendo la Ãºnica fuente de verdad;
- leÃ­do/ack, cuando sea necesario, conserva sÃ³lo metadata del usuario y nunca duplica el estado de negocio;
- los pendientes accionables desaparecen al cambiar el hecho de estado;
- los resultados terminales pueden reconocerse sin modificar la evidencia original;
- badges de sidebar son una extensiÃ³n opcional de la misma proyecciÃ³n, no contadores artesanales por mÃ³dulo.

Primeros proveedores:
1. solicitudes de retiro de seguridad;
2. Overrides de stock negativo.

Extensiones previstas:
- diferencias y excepciones de caja;
- descuentos/precios con autoridad;
- compras, pagos y tesorerÃ­a;
- recepciones con diferencias;
- cancelaciones y garantÃ­as;
- conciliaciones y anomalÃ­as;
- cualquier workflow futuro que requiera prontitud operativa.

Principio vinculante:

> **Una decisiÃ³n pendiente debe encontrar a quien puede resolverla; un resultado relevante debe encontrar a quien lo necesita.**

---

# V2.0 â€” OperaciÃ³n avanzada / empresa escalable

## 19. LogÃ­stica y depÃ³sito avanzado
- picking;
- packing;
- olas;
- zonas;
- cross-docking cuando corresponda;
- conteos cÃ­clicos;
- inventario mÃ³vil;
- recepciones asistidas;
- ruteo/entrega mediante integraciones.

## 20. Plataforma mÃ³vil/PWA
- operaciÃ³n responsive;
- inventario mÃ³vil;
- recepciÃ³n mÃ³vil;
- conteo;
- fotos/evidencia;
- lector de cÃ¡mara;
- firma;
- notificaciones.

## 21. Experiencias de tienda
- customer display;
- kiosco/self-service donde tenga sentido;
- consulta de precio;
- turnero;
- etiquetas electrÃ³nicas;
- QR de producto;
- recibo digital.

## 22. IntegraciÃ³n contable y administrativa
SRCM debe poseer la verdad operacional; no necesita reimplementar todo software commodity.

Integrar progresivamente:
- contabilidad general;
- impuestos/liquidaciones externas;
- bancos;
- payroll;
- couriers;
- ecommerce;
- proveedores especializados.

Construir nativamente sÃ³lo cuando hacerlo mejore de verdad la operaciÃ³n o la integridad.

## 23. Datos y gobierno
- exportaciÃ³n completa;
- portabilidad;
- archivado;
- retenciÃ³n;
- privacidad;
- permisos granulares;
- data lineage;
- catÃ¡logos de eventos;
- versionado de APIs;
- polÃ­ticas de eliminaciÃ³n donde legalmente corresponda sin destruir evidencia obligatoria.

---

# SRCM Business Network â€” red comercial inter-organizaciÃ³n

SRCM Full debe poder evolucionar desde sistema privado de cada empresa hacia una red comercial opt-in que conecte organizaciones sin mezclar sus datos privados.

> **SRCM no sÃ³lo administra una empresa. A escala, puede conectar empresas entre sÃ­ conservando la soberanÃ­a, autoridad y privacidad de cada organizaciÃ³n.**

## Perfil comercial publicable
Cada organizaciÃ³n podrÃ¡ decidir publicar:
- nombre comercial;
- zona de cobertura;
- varios rubros/categorÃ­as principales;
- marcas;
- mayorista/minorista/distribuidor/importador/servicio;
- canales de contacto;
- condiciones generales publicables;
- catÃ¡logo/ofertas seleccionadas.

Nada privado se publica por inferencia automÃ¡tica.

## Descubrimiento de proveedores
BÃºsqueda por rubro, producto, marca, cÃ³digo, ubicaciÃ³n, cobertura, condiciones, reputaciÃ³n y disponibilidad/oferta publicada.

## RFQ / cotizaciÃ³n B2B
`Necesidad de compra â†’ RFQ â†’ proveedores â†’ ofertas â†’ comparaciÃ³n â†’ selecciÃ³n â†’ SupplierOffer/PurchaseOrder`.

## CatÃ¡logo compartible y mapping
Cada empresa conserva su catÃ¡logo privado. La red podrÃ¡ relacionar:
- GTIN/EAN/UPC;
- SKU/cÃ³digo proveedor;
- cÃ³digo fabricante;
- modelo tÃ©cnico;
- marca;
- Knowledge Universe;
- mapping confirmado.

Un producto desconocido puede generar una propuesta de alta, nunca un alta automÃ¡tica sin revisiÃ³n/autoridad.

## Documento proveedor â†’ inbound automÃ¡tico
> **El proveedor transmite datos; el comprador controla la realidad fÃ­sica. El documento del proveedor no aumenta stock por sÃ­ solo. La recepciÃ³n fÃ­sica confirmada es la que incorpora mercaderÃ­a al inventario propio.**

Circuito:
`PurchaseOrder comprador`
â†’ `SalesOrder proveedor`
â†’ preparaciÃ³n/despacho
â†’ `Invoice/Remito/ASN estructurado`
â†’ `Inbound/Purchase Receipt esperado comprador`
â†’ control fÃ­sico
â†’ confirmaciÃ³n
â†’ stock propio.

La factura, orden de venta, remito o ASN puede crear/prellenar automÃ¡ticamente una recepciÃ³n esperada con proveedor, productos, cantidades, costos informados, documentos, bultos, lotes/series, referencia externa y estado de envÃ­o.

**El comprador controla; no vuelve a transcribir.**

## Diferencias de recepciÃ³n
Ejemplo:
`Proveedor declarÃ³ 100 â†’ 97 conformes + 2 daÃ±ados + 1 faltante`.

SRCM conserva esperado, recibido, condiciÃ³n, faltante/sobrante, daÃ±o, evidencia y reclamo. SÃ³lo lo fÃ­sicamente confirmado ingresa al stock correspondiente y con condiciÃ³n real.

## 3-way match
`Orden de compra â†” documento/factura proveedor â†” recepciÃ³n fÃ­sica`.

Coincidencia exacta puede dejar preparada la obligaciÃ³n de pago segÃºn polÃ­ticas. Diferencias nunca se corrigen ni pagan silenciosamente.

## ASN â€” Advance Shipping Notice
Puede transportar artÃ­culos, cantidades, bultos, lotes/series, transportista, documentos, ETA y QR/identificador. Al llegar, escanearlo puede abrir directamente la recepciÃ³n esperada.

## ReputaciÃ³n B2B basada en hechos
Indicadores gobernados por privacidad:
- cumplimiento de cantidades;
- puntualidad;
- diferencias;
- daÃ±os;
- cancelaciones;
- calidad de respuesta;
- experiencia por rubro.

Priorizar evidencia operacional sobre estrellas subjetivas.

## Dos representaciones privadas
El mismo intercambio puede ser:
- venta/fulfillment para proveedor;
- compra/recepciÃ³n/CxP para comprador.

Se comparte documento estructurado, no acceso a la base privada de la contraparte.

## Regla anti doble carga
> **Un dato estructurado creado por una empresa SRCM Network no debe recargarse manualmente por otra cuando pueda transmitirse, mapearse y validarse de forma segura.**

---

# Knowledge Universe â€” capacidad avanzada transversal y opcional

Knowledge Universe sigue siendo una capacidad diferencial de SRCM, pero no una obligaciÃ³n visible ni una dependencia del catÃ¡logo comercial universal. Una organizaciÃ³n puede deshabilitar su superficie tÃ©cnica cuando su rubro no la necesita sin perder productos, ventas, compras, stock ni historia comercial.

Cuando Knowledge estÃ¡ habilitado, los mÃ³dulos compatibles deben preguntarse:

**Â¿quÃ© conocimiento produce esta operaciÃ³n y quÃ© conocimiento podrÃ­a ayudarla?**

Deshabilitar Knowledge no borra entidades ni evidencia histÃ³rica. La capacidad puede permanecer oculta/read-only y reactivarse segÃºn las polÃ­ticas futuras del Tablero de MÃ³dulos.

Fuentes internas:
- compras;
- ventas;
- devoluciones;
- reparaciones;
- diagnÃ³sticos;
- presupuestos;
- garantÃ­as;
- fallas;
- compatibilidades;
- identificadores;
- rotaciÃ³n;
- proveedores;
- precios;
- bÃºsquedas sin resultado;
- evidencia aportada por usuarios autorizados.

Fuentes externas:
- fabricantes;
- documentaciÃ³n oficial;
- catÃ¡logos de proveedores;
- APIs;
- cÃ³digos/GS1;
- marketplaces;
- documentaciÃ³n tÃ©cnica;
- fuentes pÃºblicas de la web cuando su uso sea permitido y verificable.

Modelo conceptual:

`fuente â†’ dato candidato â†’ normalizaciÃ³n â†’ entidad SRCM â†’ relaciÃ³n â†’ provenance â†’ confianza â†’ validaciÃ³n â†’ conocimiento utilizable`

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
- validaciÃ³n;
- versiÃ³n.

Privacidad:
- los datos privados de cada organizaciÃ³n siguen siendo privados;
- el conocimiento compartible debe separarse de datos comerciales sensibles;
- cualquier agregaciÃ³n entre organizaciones debe diseÃ±arse con reglas explÃ­citas de privacidad, anonimizaciÃ³n/consentimiento y gobernanza;
- nunca convertir automÃ¡ticamente una observaciÃ³n privada en conocimiento pÃºblico.

La IA podrÃ¡ extraer, relacionar, detectar contradicciones y proponer conocimiento, pero debe distinguir entre:
- inferencia de IA;
- afirmaciÃ³n de fabricante;
- evidencia de proveedor;
- observaciÃ³n de usuario/comercio;
- validaciÃ³n tÃ©cnica.

Objetivo: que SRCM sea simultÃ¡neamente **sistema de operaciÃ³n** y **memoria intelectual del comercio**.

---

# V3.0 â€” Inteligencia, automatizaciÃ³n y plataforma

## 24. IA operacional gobernada
La IA no serÃ¡ un chatbot decorativo.

Casos:
- sugerir reposiciÃ³n;
- detectar anomalÃ­as de caja;
- seÃ±alar ventas/compras atÃ­picas;
- identificar repuestos desde foto/cÃ³digo/medidas;
- proponer compatibilidades;
- sugerir precio/margen;
- preparar Ã³rdenes de compra;
- explicar variaciones;
- resumir expedientes;
- sugerir campaÃ±as;
- detectar garantÃ­as repetitivas;
- forecast;
- asistencia al operador.

Regla:
**la IA puede observar, explicar, sugerir y preparar; no debe ejecutar por sÃ­ sola dinero, stock, fiscalidad o actos irreversibles sin autoridad definida.**

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
- rollback lÃ³gico mediante hechos compensatorios.

## 26. API pÃºblica y ecosistema
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
- rubros/categorÃ­as publicables;
- supplier discovery;
- RFQ;
- catÃ¡logos/ofertas compartibles;
- documentos B2B estructurados;
- SalesOrder/Invoice/Remito/ASN â†’ inbound comprador;
- mapping de productos;
- 3-way match;
- reputaciÃ³n basada en hechos;
- privacidad y soberanÃ­a por organizaciÃ³n;
- eliminaciÃ³n de doble carga.

## 27. Conocimiento y comunidad
- base tÃ©cnica;
- compatibilidades;
- casos;
- protocolos;
- evidencias;
- reputaciÃ³n;
- conocimiento compartible;
- marketplace de conocimiento;
- IA alimentada sÃ³lo por fuentes y permisos vÃ¡lidos.

---

# Principios tÃ©cnicos permanentes

## 28. Arquitectura
- modular monolith primero; separar servicios sÃ³lo cuando exista necesidad real;
- API-first en contratos de dominio;
- web como cliente, no autoridad final;
- provider-neutral;
- tenant-private;
- idempotencia;
- transacciones atÃ³micas donde corresponda;
- eventos/outbox para efectos externos;
- append-only/inmutabilidad para evidencia y hechos financieros;
- bases proyectadas reconstruibles desde hechos confirmados cuando el dominio lo permita.

## 29. UX
- teclado primero en POS;
- mÃ³vil cuando la tarea lo requiera;
- accesible;
- no esconder estados crÃ­ticos;
- no usar texto libre como fuente de verdad cuando existe vocabulario estructurable;
- defaults sÃ³lo cuando son inequÃ­vocos;
- confirmar explÃ­citamente dinero/stock/acciones peligrosas;
- minimizar trabajo manual repetitivo.

## 30. Autoridad
- rol + organizaciÃ³n + contexto;
- lÃ­mites por importe/tipo;
- supervisor/step-up;
- ninguna autorizaciÃ³n inferida de un campo de texto;
- segregaciÃ³n de funciones configurable para comercios grandes.

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
1. diagnÃ³stico real;
2. diseÃ±o/ADR cuando corresponde;
3. migraciones seguras;
4. tests focales;
5. suite completa;
6. `git diff --check`;
7. GRAN PRUEBA manual si afecta UI/operaciÃ³n;
8. verificador read-only cuando sea Ãºtil;
9. commit/push sÃ³lo tras aprobaciÃ³n;
10. checkpoint registrado en Roadmap.

Los runners deben ser PowerShell 5.1 compatibles y deben tratar errores fatales impresos como fallo aunque un proceso devuelva exit code incorrecto.

---

# Baseline tecnolÃ³gico 2026 a vigilar

No son dependencias obligatorias inmediatas; son referencias para no diseÃ±ar SRCM con supuestos viejos:

- ARCA: facturaciÃ³n electrÃ³nica por Web Services oficiales, autorizaciÃ³n por punto de venta y numeraciÃ³n correlativa;
- GS1 Digital Link 1.1.4 / cÃ³digos 2D;
- OpenTelemetry para traces/mÃ©tricas/logs;
- FIDO2/WebAuthn/passkeys para autenticaciÃ³n resistente al phishing;
- POS modernos con sesiones, control de efectivo, offline temporal, hardware integrado y devoluciones;
- APIs/webhooks/event-driven integrations;
- IA operacional con permisos y human-in-the-loop.

Estas referencias deben verificarse de nuevo en fuentes oficiales antes de cada implementaciÃ³n porque normas, APIs y estÃ¡ndares cambian.

---

# Decisiones vinculantes agregadas 2026-08-11

1. **Fiscalidad desacoplada:** la venta comercial y el comprobante fiscal son verdades distintas. La integraciÃ³n con ARCA no debe borrar ni ocultar operaciones comerciales confirmadas.
2. **Mercado objetivo amplio:** SRCM Full cubre horizontalmente retail, mayorista, distribuciÃ³n, servicios, reparaciÃ³n y omnicanalidad; los verticales especiales se construyen cuando el dominio lo justifique.
3. **Knowledge Universe avanzado y opcional:** la plataforma preserva conocimiento, compatibilidades, evidencia y fuentes verificables, pero cada organizaciÃ³n decide si esa capacidad forma parte de su superficie operativa; el catÃ¡logo comercial universal no depende de ella.
4. **Privacidad por organizaciÃ³n:** el conocimiento compartible nunca autoriza mezclar datos comerciales privados sin reglas explÃ­citas.
5. **OperaciÃ³n y conocimiento se retroalimentan cuando Knowledge estÃ¡ habilitado:** comprar, vender, reparar, devolver y garantizar pueden producir conocimiento; esa capa ayuda a comprar, vender, reparar y prevenir mejor sin ser obligatoria para rubros que no la necesitan.
6. **Experiencia de tienda conectada:** price checker/kiosco, EAS y RFID consumen el mismo catÃ¡logo, precios, stock y reglas de SRCM.
7. **Business Network opt-in:** empresas pueden descubrirse, cotizar e intercambiar documentos B2B sin exponer datos privados.
8. **Cero doble carga evitable:** datos estructurados del proveedor prellenan compra/recepciÃ³n del comprador.
9. **Stock sÃ³lo por recepciÃ³n fÃ­sica confirmada:** factura, orden, remito o ASN jamÃ¡s incrementan stock por sÃ­ solos.

---

# Regla final de alcance

**â€œSRCM lo quiero TODOâ€ no significa construir indiscriminadamente todo desde cero.**

Significa que el comerciante debe poder resolver desde SRCM â€”de forma nativa o mediante integraciÃ³n sÃ³lidaâ€” todo su circuito operativo sin volver a planillas, WhatsApp suelto o procesos paralelos para cubrir agujeros esenciales.

La prioridad siempre serÃ¡:

**menos trabajo manual + mÃ¡s verdad + mÃ¡s seguridad + mÃ¡s velocidad + mÃ¡s trazabilidad.**

<!-- P5.2_MERCADO_PAGO_POINT_ADAPTER_V1 -->
### P5.2 â€” Mercado Pago Point adapter â€” PUBLICADO
- primer adaptador concreto montado sobre P5.1 provider-neutral;
- API de Orders vigente para Point;
- normalizaciÃ³n de recurso completo Point Order;
- status provider-specific â†’ provider-neutral;
- dinero decimal â†’ minor units sin float ambiguo;
- payload/PII/tokens descartados;
- notificaciÃ³n incompleta fail-closed;
- smoke real opcional sÃ³lo lectura para detectar terminales;
- sin credenciales persistidas, sin webhook pÃºblico y sin cobro real en esta slice.

Checkpoint base P5.1: `97653c38ca416906004e7fd4230756c6ce281115`.
Checkpoint P5.2: `afcc9d863c6a291026fdce5cb74ad3fff7702ec6`.


<!-- P5.3_MERCADO_PAGO_ORDERS_TEST_V1 -->
### P5.3 â€” Mercado Pago Orders / prueba controlada â€” PUBLICADO
- transporte HTTP mÃ­nimo `POST /v1/orders` + `GET /v1/orders/{id}`;
- `X-Idempotency-Key` UUID v4 obligatorio;
- minor units internos â†’ decimal string sin float;
- hardening de moneda: `AR/ARG â†’ ARS` sÃ³lo cuando la order no informa `currency`;
- errores del proveedor sanitizados, sin body ni token;
- smoke opt-in sobre `NEWLAND_N950__SBX0000001`;
- simulaciÃ³n `processed` + GET + normalizaciÃ³n por adapter;
- sin Point fÃ­sico, sin dinero real y sin escritura en el ledger P3.

Checkpoint P5.3: `67b08f0593d5657f5eb634ec2145af6ade6e457e`.


<!-- P5.4_MERCADO_PAGO_WEBHOOK_RESOLUTION_V1 -->
### P5.4 â€” Mercado Pago Webhook authenticity/resolution â€” PUBLICADO
- HMAC-SHA256 sobre manifest oficial `data.id + x-request-id + ts`;
- comparaciÃ³n constante y fail-closed ante firma incompleta o invÃ¡lida;
- body nunca selecciona tenant/cuenta;
- `application_id`, `user_id` y `live_mode` sÃ³lo se contrastan contra identidad esperada;
- recurso financiero canÃ³nico siempre se obtiene por `GET /v1/orders/{id}`;
- secretos siguen fuera de DB/repo y entran sÃ³lo de forma transitoria;
- sin ruta pÃºblica ni ingestiÃ³n al ledger hasta resolver secret store + connection routing + ACK/job.

Checkpoint P5.4: `9d5440f103cca6c940f991bc487760797eafa556`.


<!-- P5.5_MERCADO_PAGO_WEBHOOK_HTTP_QUEUE_V1 -->
### P5.5 â€” Mercado Pago Webhook HTTP/Queue + secret routing â€” PUBLICADO
- endpoint stateless `/api/webhooks/finance/mercado-pago/{connectionPublicId}`;
- route UUID selecciona conexiÃ³n interna, nunca el body;
- secret store fuera de DB/repo mediante contrato reemplazable;
- query raw preserva `data.id` antes de HMAC;
- firma + identidad se validan antes del ACK;
- job serializa sÃ³lo connection/resource/notification IDs;
- `HTTP 200` ocurre despuÃ©s de enqueue y antes del GET externo;
- job obtiene order canÃ³nica y la ingiere con source Webhook;
- sin URL pÃºblica real ni prueba externa en esta slice.

Checkpoint P5.5: `8d26d07a60dd7777fc588e6ba71b968bf6e9ccd5`.
