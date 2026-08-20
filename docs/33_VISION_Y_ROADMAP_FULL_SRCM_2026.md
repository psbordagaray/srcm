# SRCM — Visión y Roadmap Full 2026

Estado: **North Star / contrato de dirección y continuidad**
Fecha: **2026-08-19**
Documento ejecutivo asociado: `docs/06_ROADMAP.md`
Puerta de entrada de continuidad: `docs/README.md`

---

## 0. Estado maestro de continuidad

Base funcional publicada al cierre de **P11 Production Security Baseline V1**:

`b712081c550d2fba36704ec75678eba1f5b73ff9`
— `feat(security): add production security baseline`

Estado: P1–P9 publicados/cerrados según sus checkpoints; P10 **LOCAL_CLOSURE=GREEN** en `7922c51f7f52995c7137094ec7e8be9cbdd32192`; P11 iniciado con Production Security Baseline V1 publicado.

P10 conserva su verdad: runtime local WSAA/WSFE estructuralmente completo, producción fiscal bloqueada y `REAL_ARCA_HOMOLOGATION` / WSASS diferidos. Preparado para conectar ARCA no equivale a integración ARCA validada.

P11 Production Security Baseline V1 agrega headers globales conservadores, fail-closed de configuración insegura en producción, step-up por confirmación reciente de contraseña en operaciones de alto impacto sin replay automático, y guard testeado para secret/config hygiene. ADR relacionado: `docs/130_ADR_PRODUCTION_SECURITY_BASELINE_V1.md`.

Validación publicada: **9/59 focal, 72/573 Auth+Security y 1061 tests / 7970 assertions GREEN**. BD autoritativa preservada: 107 tablas de negocio, fingerprint `D682F392715CFC9EAE886BD1D865DC60415D345E8369B9071EC89FD3436DAC3D`, schema `F2653BE8FF9B9160A6E544868478E39B7C37E57123E096BC97756CE902D92F42` y 93 migraciones / `03AC754F8B637811B412AB381F881BB55F3C838D77FCE547748878CB5BA6FC14`.

Producción general sigue bloqueada hasta completar los release gates P11. MFA/passkeys, PIN supervisor dedicado, CSP/Permissions-Policy, OpenTelemetry, backup automation y outbox continúan diferidos.

Próximo paso exacto:
`P11_PRODUCTION_OBSERVABILITY_BASELINE_RECON_V1`. Debe realizar un inventario focal de observabilidad real antes de elegir instrumentation adicional.

### Disciplina irrefutable de recuperación

Los tres documentos maestros forman una cadena obligatoria:

1. `docs/README.md`;
2. `docs/06_ROADMAP.md`;
3. `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`.

Cada paso que cambie el estado real de SRCM debe actualizar, validar y publicar los tres antes de abrir la frontera siguiente. Cuando corresponda, el mismo runner ejecuta el commit funcional y un segundo commit documental; ambos se mantienen separados.

Los gates locales de los tres maestros son estructurales: paths exactos, `diff --check`, commit/push exactos y repo limpio. La verificación semántica se realiza una sola vez sobre GitHub después de publicar.

---

## 1. North Star

SRCM debe convertirse en una plataforma integral para comercios reales: rápida para el operador, segura para el dueño, auditable para la administración, integrable con terceros y preparada para crecer desde un local hasta una operación multi-sucursal.

La plataforma debe reducir:
- doble carga;
- planillas paralelas;
- mensajes dispersos;
- conciliaciones manuales;
- errores de caja;
- sobreventa;
- pérdida de evidencia;
- decisiones sin autoridad;
- tareas repetitivas.

Y aumentar:
- velocidad de venta;
- control de stock;
- visibilidad de caja;
- trazabilidad;
- calidad de compras;
- seguridad;
- automatización;
- información para decidir.

---

## 2. Qué significa “full”

SRCM full cubre o integra:

**Catálogo + conocimiento + stock + compras + proveedores + ventas + clientes + POS + pagos + cajas + tesorería + cuentas por cobrar + cuentas por pagar + fiscalidad + devoluciones + reparaciones + omnicanalidad + logística + promociones + CRM + fidelización + hardware + offline + seguridad + observabilidad + analítica + IA + API + automatizaciones.**

No todo tiene que ser nativo. Lo commodity puede integrarse si una integración bien diseñada sirve mejor al comerciante que reescribir un producto entero.

---

## 2.1. Fiscalidad: verdad separada de la operación

En Argentina, SRCM debe estar preparado para que la realidad comercial y la realidad fiscal tengan ritmos distintos sin confundirlas.

Contrato:

`Venta confirmada → Estado fiscal independiente`

La venta no desaparece porque el comprobante fiscal todavía no exista. La fiscalidad puede estar pendiente, autorizada, rechazada o en contingencia. SRCM no debe incluir funciones destinadas a ocultar ventas o evadir obligaciones; sí debe conservar una verdad comercial completa y un estado fiscal explícito y auditable.

ARCA será un adapter fiscal argentino, no la fuente primaria de verdad de Comercio.

---

## 2.2. Universo comercial

SRCM Full apunta principalmente a:

- retail general y consumo masivo;
- kioscos/maxikioscos/minimercados/supermercados;
- ferreterías/corralones/materiales/electricidad;
- electrónica, celulares, informática y electrodomésticos;
- repuestos, autopartes, neumáticos y talleres;
- servicios técnicos y comercios híbridos;
- mayoristas, distribuidores e importadores;
- productos fraccionados;
- comercios de alto valor con series/IMEI;
- alimentos con lote/vencimiento;
- hogar/multirrubro;
- empresas multi-sucursal;
- operaciones omnicanal;
- preventa/reserva/seña;
- servicios con materiales;
- PYMEs y empresas familiares.

Los rubros con dominios muy especializados —restaurantes, hoteles, clínicas, farmacias, estaciones de servicio, fabricación MRP, etc.— se abordarán mediante verticales específicos cuando corresponda.

---

## 2.3. Knowledge Universe como capacidad diferencial opcional

SRCM conserva Knowledge Universe como una de sus capacidades diferenciales, pero la plataforma no obliga a cada empresa a exponer ni operar esa complejidad. El catálogo comercial universal debe funcionar plenamente por sí mismo.

Cuando la organización habilita Knowledge, el Core conecta:

`producto ↔ modelo ↔ código ↔ compatibilidad ↔ componente ↔ falla ↔ solución ↔ riesgo ↔ evidencia ↔ fuente`

El sistema puede aprender de operaciones internas y de fuentes externas verificables, siempre conservando provenance, confianza, contexto y validación.

Los datos privados de una organización nunca se vuelven conocimiento compartido por defecto. Deshabilitar la superficie Knowledge no borra historia ni evidencia y no afecta productos, stock, compras o ventas.

### 2.3.1. Potencia modular y superficies por organización

SRCM se concibe como una plataforma de capacidades. El Dueño/Admin general podrá decidir qué módulos utiliza la organización y delegar a administradores de segundo nivel sólo las configuraciones y empleados dentro de su alcance.

Los roles actuales son presets iniciales. La autoridad futura evoluciona a capacidades granulares y alcances organizacionales. La navegación debe derivarse de esa misma verdad: una función deshabilitada o ajena al trabajo del usuario no ensucia su superficie cotidiana.

Los presets por rubro aceleran la configuración inicial, pero nunca encierran a la empresa. Moda/belleza puede operar catálogo, variantes, compras, stock multisucursal, ventas, caja, clientes y promociones sin Knowledge técnico; repuestos/electro puede habilitar modelos, compatibilidades y conocimiento enriquecido sobre el mismo Core comercial.

Principio:

> **La potencia total pertenece a SRCM; la complejidad visible pertenece sólo a quien la necesita.**

### 2.3.2. Atención operativa orientada a autoridad

La modularidad define qué puede hacer cada persona; el Centro de Atención Operativa debe decirle qué requiere su intervención ahora.

SRCM proyecta desde los hechos de dominio una bandeja personal de acciones y resultados, filtrada por organización, capacidad y alcance. La campana superior y el Dashboard consumen la misma proyección y llevan mediante deep-link al punto exacto de resolución.

La proyección nunca sustituye Gates, Policies, reglas de dominio ni integridad DB. Tampoco crea una segunda verdad de negocio: una autorización sigue viviendo en su workflow original; la atención sólo hace visible que requiere acción o que produjo un resultado relevante.

Principio:

> **El usuario no busca pendientes; SRCM le presenta los pendientes que realmente le corresponden.**

---

## 2.4. Experiencia de tienda y seguridad

SRCM debe extenderse al piso de venta mediante Customer Kiosk / Price Checker, scanner 1D/2D, QR/DataMatrix, customer display, EAS, RFID/EPC y Smart Exit/Loss Prevention.

La consulta de precios consume la misma verdad comercial de SRCM. Un código de barras no sustituye un tag EAS/RFID.

---

## 2.5. SRCM Business Network

Red comercial opt-in entre organizaciones.

Cada empresa conserva privados sus clientes, costos, márgenes, stock no publicado, finanzas, autoridad y auditoría; puede decidir compartir perfil, rubros, marcas, catálogo/ofertas seleccionadas, capacidad de entrega, documentos B2B necesarios y conocimiento compartible.

Objetivos:
- descubrir proveedores;
- RFQ/cotizaciones;
- convertir ofertas en órdenes;
- transmitir SalesOrder/Invoice/Remito/ASN;
- precargar inbound/recepciones;
- eliminar doble carga;
- 3-way match;
- reputación operacional;
- Knowledge Network.

Regla:
`Documento proveedor → recepción esperada`, nunca `Documento proveedor → stock`.
Sólo `control físico + confirmación → stock propio`.

---

## 3. Capas del producto

### A. Sistema de registro
Verdad durable:
- identidades;
- productos;
- movimientos;
- ventas;
- compras;
- pagos;
- cuentas;
- sesiones;
- documentos;
- evidencias.

### B. Sistema de operación
Lo que usa el comercio:
- POS;
- compras;
- recepción;
- caja;
- reparaciones;
- devoluciones;
- stock;
- fulfillment;
- campañas.

### C. Sistema de control
- auditoría;
- conciliación;
- cierres;
- diferencias;
- autorizaciones;
- alertas;
- compliance;
- fiscalidad.

### D. Sistema de decisión
- dashboards;
- márgenes;
- rotación;
- forecasting;
- anomalías;
- IA;
- recomendaciones.

### E. Plataforma
- APIs;
- webhooks;
- adapters;
- importadores;
- hardware;
- apps;
- automatizaciones.


### F. Knowledge Universe
- entidades y relaciones;
- compatibilidades;
- dependencias;
- casos;
- protocolos;
- evidencia;
- provenance;
- reputación por dominio;
- validación;
- fuentes externas;
- aprendizaje de operaciones;
- conocimiento compartible gobernado.

---

## 4. Contratos de verdad

### Stock
Los movimientos confirmados son la verdad. Los saldos visibles son proyecciones.

### Precio
El precio cobrado debe tener origen y autoridad.

### Dinero

SRCM conserva hechos monetarios separados en ambas direcciones.

**Entrada:**
1. venta;
2. importe aplicado;
3. efectivo entregado y vuelto cuando corresponda;
4. cobro declarado;
5. operación externa;
6. acreditación real;
7. conciliación.

**Salida:**
1. causa comercial/recepción;
2. obligación económica;
3. autorización;
4. ejecución del pago;
5. movimiento de cuenta/caja;
6. débito externo verificado;
7. conciliación.

`entregado ≠ aplicado`, `vuelto ≠ reembolso`, `recepción ≠ pago` y `autorizar ≠ ejecutar`.

### Caja
Separar:
- cuenta financiera;
- caja física/lógica;
- turno;
- movimiento;
- arqueo;
- cierre.

### Compras
Separar:
- pedido;
- recepción;
- documento;
- obligación;
- autorización;
- pago.

### Fiscalidad
Separar:
- hecho comercial;
- documento fiscal;
- autorización fiscal;
- contingencia.

### IA
Separar:
- observación;
- recomendación;
- preparación;
- aprobación;
- ejecución.

### Business Network
Separar:
- documento declarado por proveedor;
- compra/orden propia;
- recepción esperada;
- realidad física;
- ingreso de stock;
- obligación de pago.

Nunca incrementar stock por factura/remito/ASN sin recepción física confirmada.

---

## 5. Prioridad de construcción

### Prioridad A — antes de depender de SRCM como sistema único
1. P4 Caja/Tesorería/Pagos a proveedores.
2. P5 adaptadores.
3. P6 conciliación visual.
4. P7 importadores sin API.
5. P8 devoluciones/cambios/reembolsos.
6. P9 CxC/CxP.
7. P10 ARCA/fiscal.
8. P11 producción/seguridad/observabilidad/backups.
9. P12 continuidad offline/hardware esencial.

### Prioridad B — crecimiento retail
10. P13 reservas/holds/concurrencia.
11. P14 multi-sucursal/fulfillment.
12. P15 omnicanal/SULU Media.
13. P16 promociones/CRM/loyalty.
14. P17 GS1/2D/labels/series/lotes.
15. P18 reposición/forecasting.
16. P19 BI/analítica.

### Prioridad C — plataforma full
17. logística avanzada;
18. mobile/PWA;
19. experiencias de tienda;
20. integraciones administrativas;
21. gobierno de datos;
22. IA operacional;
23. agentes;
24. API pública/ecosistema;
25. conocimiento/comunidad.

---

## 6. Criterios de “terminado” por función

Una función no está terminada sólo porque existe una pantalla.

Debe tener, según corresponda:
- modelo de dominio;
- tenant isolation;
- permisos;
- validación server-side;
- transacción;
- idempotencia;
- inmutabilidad;
- auditoría;
- UX;
- accesibilidad;
- tests;
- migración;
- rollback/compensación;
- observabilidad;
- documentación;
- caso manual real;
- exportabilidad;
- manejo de error.

---

## 7. Anti-patrones prohibidos

- stock derivado de texto libre;
- dinero derivado de notas;
- borrar hechos confirmados para “arreglar”;
- asumir Efectivo;
- inventar conciliaciones;
- mezclar cobro con acreditación;
- registrar dinero entregado como si fuera importe vendido/aplicado;
- tratar vuelto como descuento, gasto o devolución posventa;
- inventar evidencia histórica de entregado/vuelto;
- mezclar recepción con obligación;
- mezclar recepción con pago;
- mezclar autorización con ejecución;
- mezclar retiro de seguridad con gasto;
- mezclar caja física con cuenta bancaria;
- permitir cross-tenant;
- guardar PAN/CVV;
- automatizar una decisión ambigua;
- hacer depender el Core de un proveedor;
- crear una integración sin idempotencia/retry;
- usar IA para ejecutar actos irreversibles sin autoridad;
- reabrir bugs/pendientes ya resueltos sin evidencia de regresión.

---

## 8. Producción y SRE mínimo

Antes del go-live:
- backups automáticos;
- restore probado;
- monitoreo;
- alertas;
- logs estructurados;
- traces/métricas;
- health checks;
- jobs observables;
- secrets;
- TLS;
- hardening;
- actualizaciones;
- procedimiento de incidente;
- RPO/RTO;
- export de emergencia;
- plan de continuidad.

---

## 9. Estrategia de integraciones

### Contrato común
Cada adapter debe declarar:
- proveedor;
- organización;
- cuenta;
- credenciales;
- capacidades;
- source;
- external ID;
- timestamps;
- idempotency;
- firma/verificación;
- estado;
- payload seguro;
- errores/retry.

### Orden de preferencia
1. API/webhook oficial;
2. polling oficial;
3. importación estructurada;
4. manual controlado.

### P5.1 — límite provider-neutral
- la conexión de proveedor referencia una cuenta financiera privada pero no conserva credenciales ni secretos;
- adapters concretos producen observaciones seguras y el Core las ingiere sobre `FinancialExternalMovement` P3;
- API/webhook/polling son transportes, no nuevas verdades financieras;
- reintentos y entregas multicanal de la misma operación/estado no deben duplicar efectos;
- cambios de estado externos son nuevos hechos inmutables, no updates retrospectivos;
- una inconsistencia monetaria para la misma operación/estado falla cerrado;
- conciliación sigue siendo un acto separado y verificable.

---

## 10. Estrategia de IA

La IA se añade después de tener buenos hechos.

### Puede
- buscar;
- resumir;
- explicar;
- clasificar;
- detectar anomalías;
- estimar;
- recomendar;
- preparar borradores;
- preparar acciones para aprobación.

### No debe, por defecto
- confirmar una venta;
- retirar efectivo;
- pagar proveedor;
- emitir comprobante irreversible;
- cambiar stock confirmado;
- aprobar diferencias;
- conciliar forzadamente.

El permiso de un agente nunca puede superar el permiso del usuario/servicio que lo invoca.

---

## 11. Referencias tecnológicas 2026

### Fiscalidad argentina
ARCA mantiene Web Services oficiales de facturación electrónica, incluyendo WSFEv1; el diseño SRCM debe tratar punto de venta, correlatividad, autorización y contingencia como dominio fiscal separado del hecho comercial.

### GS1
GS1 Digital Link 1.1.4 fue publicado en enero de 2026. SRCM debe evitar un modelo de identificadores que impida adoptar 2D, Application Identifiers, lotes/series y Digital Link.

### Observabilidad
OpenTelemetry es la referencia vendor-neutral para instrumentación de traces, métricas y logs.

### Autenticación
FIDO2/WebAuthn/passkeys son una dirección madura para autenticación resistente al phishing. En POS también se necesita step-up operacional simple y rápido.

### POS moderno
La referencia funcional ya incluye:
- sesiones;
- apertura/cierre;
- cash in/out;
- efectivo esperado vs contado;
- devoluciones;
- operación temporal offline;
- periféricos;
- loyalty;
- QR;
- customer display;
- múltiples tiendas.

No copiar productos ajenos: usar estas capacidades como benchmark de cobertura.

---

## 12. Arquitectura objetivo

Mantener Laravel como modular monolith mientras siga siendo la opción más simple y segura.

Separar servicios sólo cuando:
- exista escalamiento independiente real;
- aislamiento de seguridad lo justifique;
- un adapter requiera proceso independiente;
- disponibilidad o carga lo exija.

Preferir:
- módulos de dominio;
- servicios explícitos;
- contratos;
- colas;
- outbox;
- eventos;
- adapters;
- APIs versionadas;
- jobs idempotentes.

Evitar microservicios por moda.

---

## 13. Roadmap vivo

Este documento es deliberadamente amplio.

Cada fase debe:
1. relevar el repo real;
2. convertir alcance en ADR/plan cuando haga falta;
3. implementar bloques pequeños;
4. validar;
5. actualizar `docs/06_ROADMAP.md`;
6. registrar checkpoint;
7. actualizar este documento si cambia la North Star.

No es válido “terminar” un bloque y dejar el roadmap afirmando que sigue pendiente.

---

## 14. Protocolo de recuperación de conversación

Al abrir un chat nuevo o recuperar uno trabado:

1. identificar proyecto SRCM;
2. obtener branch + HEAD + origin + status + staging y exigir coincidencia/limpieza;
3. leer `docs/README.md`;
4. leer `docs/06_ROADMAP.md`;
5. leer este archivo;
6. si se toca dinero/ventas, leer `docs/32_PLAN_TERMINAL_COBRO_CUENTAS_CONCILIACION_V1.md`;
7. leer todos los ADR del dominio afectado;
8. leer RESULT del último bloque;
9. reconstruir la frontera de código, migraciones, permisos, rutas, UI y tests;
10. continuar desde el checkpoint y próximo paso documentados, no desde memoria informal.

---

## 15. Mandato permanente

La meta no es “tener un ERP grande”.

La meta es que el comerciante sienta que SRCM:
- entiende su operación;
- le ahorra trabajo;
- le muestra lo importante;
- le impide errores peligrosos;
- conserva la evidencia;
- automatiza lo obvio;
- se integra con el mundo real;
- crece sin perder la verdad.

**SRCM full = menos trabajo manual, más control y una sola verdad operacional.**

<!-- P5.2_MERCADO_PAGO_POINT_ADAPTER_V1 -->
## Continuidad P5.2 — Mercado Pago Point
El primer proveedor concreto de P5 será Mercado Pago Point sobre la API de Orders. Su adapter traduce recursos completos a la verdad financiera P3/P5.1; no crea un ledger paralelo. La primera verificación productiva será sólo lectura de terminales. El cobro real queda detrás de un checkpoint explícito posterior.


<!-- P5.3_MERCADO_PAGO_ORDERS_TEST_V1 -->
## Continuidad P5.3 — Point Orders antes de producción
La integración Mercado Pago avanza por un test harness controlado: crear order,
simular resultado, consultar recurso completo y normalizarlo. El dispositivo
virtual y las credenciales de prueba son una frontera obligatoria antes de
habilitar el Point físico y dinero real. El transporte no obtiene autoridad
para crear hechos financieros por sí mismo.


<!-- P5.4_MERCADO_PAGO_WEBHOOK_RESOLUTION_V1 -->
## Continuidad P5.4 — autenticidad antes de automatización
Los Webhooks de proveedores externos sólo pueden producir observaciones después
de validar su firma y resolver identidad interna sin confiar en datos de tenancy
recibidos. El transporte y el body externo jamás reciben autoridad directa para
elegir una organización, una cuenta financiera o escribir el ledger.


<!-- P5.5_MERCADO_PAGO_WEBHOOK_HTTP_QUEUE_V1 -->
## Continuidad P5.5 — Webhook operativo sin entregar autoridad externa
La recepción HTTP pública se separa del trabajo financiero: autenticar y
encolar es una fase; consultar la evidencia canónica e ingerirla es otra. Los
secretos permanecen fuera del ledger, la base y los jobs. La conexión interna
se fija por una URL configurada por SRCM y queda protegida por su propio HMAC.


## Continuidad P9 — CxC/CxP y desembolso de proveedores

P9.1–P9.6b publicó deuda de clientes, cobranzas e imputaciones, aging, política
de crédito, cuotas, anticipos y convergencia de excedentes. P9.7a–P9.7l publicó
documento de proveedor, 3-way match derivado, notas de crédito, aplicaciones,
anticipos, autorización agrupada, el parent canónico de desembolso y su
superficie operacional con evidencia externa saliente.

P9.7j expone esa verdad mediante HTTP/UI individual y agrupada, cash y
non-cash, sin duplicar reglas de dominio. `PurchasePaymentExecution` se conserva
como historia append-only; `PurchasePaymentDisbursement` y sus allocations son
la verdad canónica nueva. Un desembolso cash produce un solo `CashMovement` por
el total y un desembolso non-cash no fabrica evidencia bancaria externa.

P9.7k vincula explícitamente un desembolso non-cash con un
`FinancialExternalMovement` `Debit + Posted` mediante evidencia append-only,
sin ampliar `PaymentReconciliation` ni automatizar la decisión. P9.7l incorpora
resolución/derivación append-only por observación externa, preserva snapshots y
mantiene CxP intacta.

P9.8 deriva exposición y aging desde obligaciones y sus cuatro streams de
liquidación. Separa proveedor, beneficiario y moneda; fija vencimiento efectivo
y publica estado de cuenta sin tabla, snapshot ni saldo paralelo. Con este corte
P9 CxC/CxP queda cerrado en V1.

P10 RECON confirma que `CommerceSale` no depende de autorización fiscal y que
su `sale_number` es interno. P10.1 agrega `FiscalOrganizationProfile` y
`FiscalPointOfSale` por ambiente, con identidad protegida, sin documento,
secuencia, WSAA, WSFE, CAE, CAEA ni QR. El siguiente corte es P10.2 — Fiscal
Document Core RECON.
