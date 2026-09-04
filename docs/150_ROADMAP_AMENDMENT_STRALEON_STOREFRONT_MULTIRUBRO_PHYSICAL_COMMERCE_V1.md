# Straleon — Roadmap Amendment: Storefront, Multirrubro y Comercio Físico V1

Estado: **PROPUESTA VINCULANTE PARA PRÓXIMA SINCRONIZACIÓN MAESTRA**  
Fecha: **2026-09-03**  
Producto canónico: **Straleon**  
Base exacta del corte: `feature/core-entity@e4c1061fb30db7e9c61a7c773ec450d3af84a13e`  
Naturaleza del corte: **documental / arquitectura de producto**  
Código funcional: **NO MODIFICADO**  
Base de datos: **NO MODIFICADA**  
Producción: **NO MODIFICADA**  
Autorización de producción: **NO CAMBIA**

---

## 1. Propósito

Este documento formaliza decisiones de producto surgidas de casos comerciales reales y las asigna a las áreas correctas del roadmap de Straleon.

No crea verticales independientes ni bifurca el Core. El objetivo es que una misma plataforma pueda representar operaciones muy distintas mediante capacidades, políticas, perfiles y superficies configurables.

Principio rector:

> **Straleon = un Core, una sola verdad operacional y múltiples superficies.**

Principio complementario:

> **La potencia total pertenece a Straleon; la complejidad visible pertenece sólo a quien la necesita.**

Este amendment no renumera ni reabre la fase técnica P13 actualmente activa. Las referencias P13/P14/P15/P16/P17/P18/P19/P20 de V1.5 son etiquetas históricas del roadmap comercial y deberán armonizarse documentalmente con la nomenclatura técnica vigente cuando se sincronice `docs/06_ROADMAP.md`.

---

# 2. Decisiones vinculantes nuevas

## 2.1 Straleon Storefront / Web Commerce es superficie de primera clase

Straleon Storefront / Web Commerce deja de considerarse solamente una futura integración de ecommerce.

Se define como una **superficie nativa de Straleon**, al mismo nivel conceptual que Backoffice, POS, operaciones internas, futuras aplicaciones móviles y superficies de cliente.

Topología conceptual:

```text
                         STRALEON CORE
                    ÚNICA VERDAD OPERACIONAL
                              │
       ┌──────────────────────┼──────────────────────┐
       │                      │                      │
   BACKOFFICE                POS                STOREFRONT
 Dueño / empleados      Caja / mostrador        Cliente web
 Compras                Venta rápida            Catálogo
 Inventario             Cobro                   Carrito
 Administración         Hardware                Checkout
 Reparaciones                                   Mi cuenta
       │                      │                      │
       └──────────────────────┼──────────────────────┘
                              │
                    CANALES EXTERNOS
       WhatsApp · Instagram · Marketplaces · Kioscos
       Price Checker · Digital Signage · futuras APIs
```

Contrato:

- Storefront no mantiene una segunda verdad de stock;
- Storefront no mantiene una segunda verdad de precios;
- Storefront no conecta el navegador directamente a la base de datos;
- Storefront consume servicios y contratos de dominio autorizados;
- POS, Backoffice y Storefront convergen sobre la misma realidad comercial;
- cachés, CDN, índices de búsqueda y proyecciones podrán existir, pero nunca serán la autoridad comercial;
- una venta, hold, reserva, devolución o modificación válida del Core debe proyectarse a la disponibilidad pública de acuerdo con política;
- publicación pública y existencia interna son dimensiones distintas.

Flujo técnico conceptual:

```text
Cliente → Storefront → Application/Domain Services → Core → DB
Empleado → Backoffice/POS → Application/Domain Services → Core → DB
```

---

## 2.2 Publicación comercial es una capacidad explícita

No todo producto existente debe publicarse automáticamente.

Straleon deberá distinguir al menos:

```text
existe
sellable
available
publishable
published
```

La política deberá poder depender de:

- organización;
- sucursal/ubicación;
- canal;
- condición;
- precio;
- imagen/media mínima;
- restricción comercial;
- reserva/hold;
- lote/vencimiento cuando aplique;
- stock mínimo protegido;
- venta mayorista/minorista;
- producto bajo pedido/preventa;
- política manual de publicación.

Canales orientativos:

- POS;
- Storefront;
- WhatsApp Business;
- Instagram;
- marketplace;
- B2B mayorista;
- cartelería digital;
- kiosco/price checker.

---

## 2.3 Hardware comercial es superficie física de primera clase

Scanner, balanza, impresoras, terminales de cobro y dispositivos móviles dejan de ser accesorios periféricos del producto.

Se consideran **extensiones físicas de Straleon**, integradas mediante capacidades y adapters, sin acoplar el Core a fabricantes concretos.

Arquitectura:

```text
Device → Capabilities → Protocol/Driver → Adapter → Straleon
```

Capacidades iniciales de referencia:

- `barcode.read.1d`;
- `barcode.read.2d`;
- `gs1.decode`;
- `weight.read`;
- `receipt.print`;
- `label.print`;
- `customer.total.show`;
- `payment.initiate` / evidencia de terminal según adapter;
- `cash_drawer.open`;
- futuras `rfid.*`, `temperature.read`, `volume.dispensed`.

Hardware comercial Tier 1 futuro:

1. scanner 1D/2D cableado;
2. impresora térmica 80 mm;
3. cajón portamonedas;
4. balanza;
5. impresora de etiquetas;
6. terminal/postnet mediante adapter/provider;
7. dispositivos Android/PDA;
8. display de cliente;
9. price checker/kiosco.

Regla:

> El dispositivo aporta observaciones o ejecuta acciones autorizadas; nunca se convierte en la verdad del negocio.

---

# 3. Validación multirrubro real obligatoria

Las decisiones futuras de Catálogo, Inventario, Comercio, Precios, Fulfillment, Storefront y Hardware no se considerarán suficientemente generales por abstracción teórica.

Deberán contrastarse, cuando corresponda, contra este banco inicial de negocios reales.

## 3.1 SULU

Perfil:

- computación;
- celulares;
- electrónica;
- venta;
- reparación.

Capacidades críticas:

- serial/IMEI/SN;
- condición física;
- garantía;
- reparación;
- presupuesto;
- consumo de repuestos;
- trazabilidad de custodia;
- Storefront de productos;
- portal/superficie cliente para reparación, presupuesto y seguimiento.

---

## 3.2 JB Repuestos

Perfil:

- repuestos Volkswagen;
- multimarca;
- venta mostrador;
- posible mayorista.

Capacidades críticas:

- SKU/EAN;
- código OEM;
- código fabricante;
- equivalencias;
- supersesiones/reemplazos;
- compatibilidades;
- vehículo/modelo/año/motor/versión;
- Knowledge Universe opcional pero de alto valor;
- búsqueda Storefront por código y por vehículo.

---

## 3.3 El Arca by Virginia

Perfil:

- indumentaria;
- accesorios femeninos;
- showroom;
- sucursal;
- venta presencial y online.

Capacidades críticas:

- producto → opciones → variantes → SKU;
- talle;
- color;
- stock por variante;
- imágenes/colecciones;
- multi-sucursal;
- retiro en tienda;
- cambios;
- Storefront visual.

---

## 3.4 Huevos Mauri

Perfil:

- alimentos;
- fiambres;
- huevos;
- quesos;
- minorista;
- mayorista;
- distribución.

Capacidades críticas:

- unidad base;
- presentaciones;
- conversiones;
- peso;
- fraccionamiento;
- lotes;
- vencimientos;
- precio minorista/mayorista;
- escalas por cantidad;
- cliente/lista de precios;
- picking;
- reparto/distribución;
- Storefront B2C/B2B progresivo.

---

## 3.5 Punto Nebel

Perfil:

- supermercado;
- carnicería;
- rotisería;
- panadería.

Capacidades críticas:

- retail de alta rotación;
- códigos de barras;
- balanzas;
- productos pesables;
- lotes/vencimientos;
- venta estimada por peso;
- preparación real;
- elaboración/transformación;
- recetas/fórmulas;
- producción comercial liviana;
- merma;
- Storefront omnicanal.

---

## 3.6 Flash Multirubro

Perfil:

- artículos provenientes de devoluciones de Mercado Libre;
- multirrubro;
- artículos nuevos para el hogar;
- reventa mayorista/minorista.

Capacidades críticas:

- preingreso;
- grandes lotes/remitos;
- identificación desconocida/probable/confirmada;
- condición real por unidad;
- nuevo/usado/reacondicionado/dañado/incompleto/exhibición;
- accesorios faltantes;
- pruebas y reacondicionamiento;
- unidad física individual cuando corresponda;
- fotos reales;
- ubicación precisa;
- valoración/precio por condición;
- publicación selectiva;
- PDA/scanner para recepción e identificación.

Regla estructural revelada:

> **Producto ≠ unidad física ≠ condición ≠ disponibilidad ≠ publicación.**

---

# 4. Escenarios arquitectónicos permanentes adicionales

## 4.1 Lubricentro

Representa productos fraccionados por volumen desde contenedores físicos.

Ejemplo:

```text
Aceite 15W40
Tambor A: 200 L original / 18 L saldo / ABIERTO
Tambor B: 200 L / SELLADO
Tambor C: 200 L / SELLADO
```

Política requerida:

> Si el contenedor abierto elegible alcanza para cumplir la cantidad solicitada, no se abre otro contenedor cuando la política configurada sea `agotar_contenedor_abierto`.

Si el abierto no alcanza, la asignación debe poder consumir su saldo y continuar desde el siguiente contenedor elegible, conservando trazabilidad exacta.

---

## 4.2 La Casa del Panadero

Representa productos fraccionados por peso desde envases físicos.

Ejemplo:

```text
Harina 000
Bolsa A: 25 kg original / 8 kg saldo / ABIERTA
Bolsa B: 25 kg / SELLADA
```

La misma capacidad debe resolver bolsas, sacos, bidones, bobinas, hormas, rollos, tambores u otros contenedores fraccionables sin crear lógica específica por rubro.

---

## 4.3 Carnicería integral

Caso de referencia:

- compra de medias reses en matadero;
- camiones propios para retiro;
- cámaras frigoríficas;
- desposte;
- fábrica de chacinados;
- venta mostrador;
- alimentos complementarios y vinos;
- venta mayorista/reventa;
- redes sociales y WhatsApp Business;
- Storefront para clientes habituales y nuevos clientes fuera de la zona inmediata.

Este caso valida simultáneamente:

- peso variable;
- lotes;
- transformación;
- rendimiento;
- merma;
- subproductos;
- cadena de frío;
- producción propia;
- mayorista;
- reparto;
- Storefront;
- hardware de balanza/etiquetado.

---

# 5. Motores transversales nuevos

## 5.1 Commercial Availability Engine

Straleon debe evolucionar de “stock visible” a **disponibilidad comercial prometible**.

Pregunta universal:

> **¿Cuánto de este producto puede prometer esta organización a este cliente, por este canal, en este momento?**

La respuesta puede considerar:

```text
stock físico
- reservas
- holds
- pedidos web en preparación
- stock bloqueado
- lotes no elegibles
- stock mínimo protegido
- compromisos mayoristas
- restricciones de sucursal/canal
± políticas de preventa/backorder
= disponibilidad comercial
```

Contrato:

- el stock físico sigue derivándose del ledger de inventario;
- disponibilidad es una proyección/regla, no un saldo paralelo manual;
- POS, Storefront, WhatsApp, marketplace y vendedor deben consultar el mismo motor;
- debe ser concurrency-safe;
- debe prevenir sobreventa;
- puede ocultar la cantidad exacta al público según política.

Modos de publicación posibles:

- cantidad exacta;
- Disponible;
- Últimas unidades;
- Consultar disponibilidad;
- Preventa;
- Bajo pedido;
- no publicar cantidad.

---

## 5.2 Units / Presentations / Fractional Containers

Straleon deberá separar:

```text
unidad base
presentación comercial/logística
contenedor físico
saldo fraccionable
```

Ejemplos:

```text
Huevo
unidad base: unidad
presentaciones: unidad / docena / maple / caja
```

```text
Aceite
unidad base: litro
presentaciones: 1 L / 4 L / 20 L / tambor 200 L / fraccionado libre
```

```text
Harina
unidad base: kg
presentaciones: 1 kg / 5 kg / bolsa 25 kg / fraccionado
```

Reglas:

- no crear stocks paralelos ficticios por presentación;
- conversiones determinísticas y versionadas cuando corresponda;
- vender un contenedor cerrado no implica abrirlo;
- una fracción debe poder conservar su origen físico;
- la precisión decimal depende de producto/unidad/política;
- el sistema debe distinguir medición comercial de empaque/logística.

---

## 5.3 Fractional Container Consumption Policy

Políticas posibles:

- `agotar_contenedor_abierto`;
- FEFO;
- FIFO;
- selección manual;
- confirmación antes de abrir siguiente;
- autorización de supervisor;
- apertura sólo desde depósito/preparación.

Máquina de estados orientativa:

```text
SELLADO → ABIERTO → EN_CONSUMO → AGOTADO
```

Estados auxiliares posibles:

- cuarentena;
- bloqueado;
- dañado;
- vencido;
- en conteo.

Una venta de 25 L podrá asignarse, por ejemplo:

```text
18 L ← Tambor A
 7 L ← Tambor B
```

sin perder la trazabilidad física.

---

## 5.4 Variable Quantity Fulfillment

Para productos cuyo valor final depende de la cantidad real preparada.

Flujo:

```text
intención solicitada
      ↓
hold/reserva estimada
      ↓
picking / preparación
      ↓
medición real
      ↓
cantidad aceptada
      ↓
importe definitivo
      ↓
venta/fulfillment confirmado
```

Ejemplo:

```text
Cliente solicita: 1,000 kg de vacío
Preparador pesa: 1,086 kg
Cantidad real:   1,086 kg
Importe final:   precio/kg × 1,086
```

Regla:

> Nunca falsear la cantidad real para hacerla coincidir con la intención original del cliente.

Aplicable a:

- carnicería;
- fiambres;
- quesos;
- pescadería;
- frutas/verduras;
- panificados por peso;
- granel;
- lubricantes;
- otros fraccionados.

---

## 5.5 Quantity Tolerance Policy

El cliente o la política comercial podrán establecer tolerancias.

Ejemplos:

- ±5 %;
- ±10 %;
- máximo absoluto;
- nunca superar objetivo;
- permitir menor cantidad;
- consultar antes de exceder.

El preparador debe ver el rango permitido y Straleon debe bloquear, advertir o requerir autorización según política.

---

## 5.6 Fulfillment Preferences / Sustituciones

El pedido debe poder conservar preferencias como:

- aceptar otro peso dentro de tolerancia;
- aceptar/no aceptar otra marca;
- aceptar/no aceptar producto equivalente;
- no sustituir determinado corte/producto;
- consultar por WhatsApp;
- cancelar línea si no existe alternativa.

Las preferencias son parte de la intención comercial y no deben perderse durante picking/preparación.

---

## 5.7 Transformation & Yield Ledger

Straleon deberá representar transformaciones físicas sin reescribir inventario silenciosamente.

Modelo conceptual:

```text
INPUTS
   ↓
TRANSFORMATION
   ↓
OUTPUTS
+ SUBPRODUCTOS
+ MERMAS
```

Ejemplo carnicería:

```text
Media res MR-93882 — 143,600 kg
            ↓
         DESPOSTE
            ↓
Asado
Vacío
Matambre
Nalga
Cuadrada
Paleta
Picada
Huesos
Grasa
Merma
```

El hecho debe conservar:

- organización;
- inputs reales;
- lotes/unidades de origen;
- cantidades de entrada;
- proceso/tipo de transformación;
- operador/responsable;
- fecha/hora;
- outputs;
- subproductos;
- merma;
- ubicación origen/destino;
- lote producido cuando corresponda;
- receta/fórmula/version cuando aplique;
- evidencia/observaciones;
- idempotencia.

Una media res deja de existir como tal porque fue transformada, no porque se editó su stock.

---

## 5.8 Yield Analytics

Sobre hechos reales de transformación, Straleon podrá derivar:

- rendimiento total;
- rendimiento vendible;
- merma;
- subproductos;
- variación contra rendimiento esperado;
- rendimiento por proveedor;
- rendimiento por lote;
- rendimiento por operario/proceso cuando sea apropiado y autorizado;
- costo por output.

No se debe inventar causalidad desde correlaciones simples.

---

## 5.9 Transformation Cost Allocation

El costo de los inputs podrá distribuirse entre outputs mediante políticas explícitas y auditables.

Posibles políticas futuras:

- proporcional a masa/volumen;
- valor relativo de venta;
- coeficientes configurados;
- costo estándar + variación;
- asignación manual autorizada.

Nunca se reescribe retrospectivamente el costo histórico sin evidencia/corrección explícita.

---

## 5.10 Producción Comercial Liviana

El Transformation Ledger servirá también para producción comercial liviana, sin declarar a Straleon como MRP industrial completo.

Casos:

- chacinados;
- panadería;
- rotisería;
- pastelería;
- fraccionamiento;
- armado de kits;
- mezclas;
- pequeñas elaboraciones.

Ejemplo:

```text
Carne + grasa + sal + especias + tripa
                 ↓
             Producción
                 ↓
Chorizo fresco + merma
```

Debe poder conservar receta/version, consumos reales, lote resultante, rendimiento y vencimiento.

---

## 5.11 Traceability Chain

Lotes, series, contenedores y transformaciones deberán permitir trazabilidad hacia atrás y hacia adelante.

Hacia atrás:

```text
Lote chorizo
→ producción
→ carne/grasa
→ media res/lote
→ recepción
→ proveedor/matadero
```

Hacia adelante:

```text
Lote chorizo
→ venta POS
→ pedido Storefront
→ mayorista
→ cliente/entrega cuando legal y comercialmente corresponda
```

Debe habilitar recall selectivo sin afectar stock o ventas no relacionadas.

---

## 5.12 Storage Conditions / Cold Chain Readiness

Una ubicación de inventario podrá tener políticas/condiciones de almacenamiento sin dejar de ser una ubicación del Core.

Ejemplos:

- ambiente;
- refrigerado;
- congelado;
- rango objetivo de temperatura;
- productos admitidos;
- estado operativo.

Futuro hardware:

- `temperature.read`;
- sensores de cámara;
- alertas;
- evidencia de ruptura de cadena de frío.

No implementar sensores por presunción. La arquitectura solamente debe evitar bloquear esa evolución.

---

## 5.13 Logistics Custody / Vehicle as Operational Location

Para retiro, reparto y distribución, un vehículo podrá representar temporalmente una ubicación/custodia logística.

Flujo orientativo:

```text
Proveedor/depósito
      ↓
Carga
      ↓
Vehículo / en tránsito
      ↓
Recepción / cliente / sucursal
```

Esto no convierte Straleon en un TMS completo. Permite preservar custodia, ubicación y trazabilidad del inventario durante tránsito.

---

# 6. Storefront especializado por capacidades, no por forks

El Storefront deberá adaptarse a cada negocio usando las mismas capacidades del Core.

## 6.1 SULU

- productos;
- usados/reacondicionados cuando se publiquen;
- reparación;
- consulta de estado;
- aprobación de presupuesto;
- garantías.

## 6.2 JB Repuestos

- búsqueda por nombre/código;
- OEM;
- equivalencias;
- selector de vehículo;
- compatibilidad.

## 6.3 El Arca

- colecciones;
- talle/color;
- imágenes;
- stock por variante;
- retiro en showroom/sucursal;
- cambios.

## 6.4 Huevos Mauri

- minorista;
- mayorista;
- presentaciones;
- peso;
- escalas;
- entrega/distribución.

## 6.5 Punto Nebel

- supermercado;
- pesables;
- rotisería/panadería;
- promociones;
- entrega/retiro.

## 6.6 Flash Multirubro

- foto real;
- condición real;
- unidad individual cuando corresponda;
- precio específico por estado;
- disponibilidad/publicación selectiva.

## 6.7 Carnicería

El Storefront no debe ser un catálogo genérico.

Ejemplo de experiencia futura:

```text
Vacío — $/kg
Cantidad deseada: 1,000 kg
Tolerancia: ±10 %
Preferencia de corte: media
Entrega: hoy 17–20 / mañana / retiro
```

La preparación real determina cantidad e importe final dentro de las reglas aceptadas.

Funciones de alto valor:

- comprar por peso aproximado;
- preferencia de corte;
- tolerancia;
- sustitución;
- combos;
- “para N personas” mediante reglas definidas por el negocio;
- repetir compra histórica;
- promociones desde Instagram/WhatsApp con deep-link al producto/campaña;
- compra fácil para clientes fuera del radio habitual del local.

Regla:

> Storefront debe ampliar el alcance comercial del negocio sin crear otra plataforma de stock, pedidos o clientes.

---

# 7. Customer Surface ampliada

Storefront/Web Commerce deberá evolucionar hacia una superficie de cliente más amplia.

Posibles capacidades:

- pedidos;
- compras;
- entregas;
- facturas/documentos;
- presupuestos;
- cuenta corriente/saldo cuando corresponda;
- garantías;
- devoluciones;
- reservas;
- reparaciones;
- aprobación/rechazo de presupuestos;
- seguimiento;
- recompra;
- preferencias;
- fidelización.

La superficie mostrará únicamente información autorizada para ese cliente y nunca sustituirá controles de backend/DB.

---

# 8. Business Profiles / Gestor de Negocios

P20 deberá evolucionar los presets orientativos hacia perfiles de capacidades combinables.

Perfiles sugeridos:

- Retail General;
- Tecnología + Serializados;
- Servicio Técnico / Reparaciones;
- Repuestos + Fitment/Compatibilidades;
- Moda / Variantes;
- Mayorista;
- Distribución;
- Alimentos / Perecederos;
- Supermercado;
- Producción Comercial Liviana;
- Productos Fraccionados;
- Multirrubro / Devoluciones;
- Personalizado.

Los perfiles son recomendaciones editables, nunca jaulas.

Ejemplos:

```text
SULU
Tecnología + Serializados + Reparaciones + Storefront
```

```text
Huevos Mauri
Alimentos + Fraccionados + Mayorista + Distribución + Storefront
```

```text
Punto Nebel
Supermercado + Perecederos + Peso Variable + Producción Liviana + Storefront
```

El Gestor de Negocios podrá preguntar necesidades reales, por ejemplo:

- ¿Vendés por peso/metro/litro?
- ¿Usás talle/color/variantes?
- ¿Necesitás IMEI/SN?
- ¿Trabajás con lotes o vencimientos?
- ¿Elaborás productos a partir de otros?
- ¿Vendés mayorista?
- ¿Hacés reparto?
- ¿Tenés más de una sucursal?
- ¿Reparás productos?
- ¿Necesitás compatibilidades?
- ¿Usás balanzas/scanners/impresoras de etiquetas?
- ¿Querés vender por Storefront?

La respuesta configura capacidades, superficies y recomendaciones; no crea un fork del producto.

---

# 9. Relación con capacidades ya programadas

Estas mejoras **potencian** fundamentos ya existentes y no deben reemplazarlos.

## Inventario

Se conserva como verdad reconstruible mediante movimientos confirmados.

Las nuevas capas agregan:

- disponibilidad derivada;
- asignación a contenedores;
- transformaciones;
- lotes;
- holds;
- fulfillment;
- trazabilidad.

Nunca se agrega un campo editable genérico `stock` como autoridad.

## Comercio

Venta, presupuesto, reserva, pedido y fulfillment deberán converger sin reescribir hechos confirmados.

## Numerics / Money

Cantidad real, precio, impuestos, pagos y settlement deberán respetar las fronteras numéricas ya construidas.

Variable Quantity Fulfillment no autoriza a aceptar observaciones numéricas ni reescribir importes fuera de los contratos de integridad numérica vigentes.

## Fiscalidad

Venta comercial y documento/autorización fiscal continúan separados.

Storefront no debe convertir la autorización fiscal remota en condición para que exista la intención/pedido/venta comercial cuando legalmente corresponda otra secuencia.

## Reparaciones

Storefront/Customer Surface podrá proyectar estados y autorizaciones de cliente sin duplicar el dominio Service.

## Hardware / Offline

P12 conserva su Device Registry/capability model y sus restricciones actuales. Esta enmienda define la dirección futura de hardware comercial, no abre drivers, Service Worker, replay offline, terminales reales ni sensores por sí misma.

---

# 10. Concurrencia y atomicidad

Las nuevas capacidades deben diseñarse para operaciones concurrentes.

Ejemplo fraccionado:

```text
Tambor abierto: 18 L
Caja 1 solicita 15 L
Caja 2 solicita 10 L
```

No pueden ambas observar 18 L y asumir que alcanza.

El motor deberá reservar/asignar de forma transaccional o mediante el mecanismo de concurrencia apropiado:

```text
18 L
↓ Caja 1 asigna 15
3 L restantes disponibles
↓ Caja 2 solicita 10
3 L del actual + 7 L del siguiente elegible
```

La misma regla aplica a Storefront + POS + WhatsApp + otros canales.

---

# 11. Mapa de incorporación al roadmap maestro

Al sincronizar los documentos maestros, incorporar este amendment de la siguiente manera.

## Área P12 / Hardware

Agregar:

- Hardware First-Class;
- capability-based adapters;
- scanner/balanza/impresión/etiquetado/postnet/PDA;
- readiness futura para temperatura y volumen dispensado;
- dispositivos como observadores/actuadores, no autoridad de dominio.

## Área comercial de reservas / concurrencia

Agregar:

- Commercial Availability Engine;
- disponibilidad prometible por canal;
- holds de Storefront;
- concurrencia de fraccionados;
- protección de stock mínimo;
- compromisos mayoristas.

## Área multi-sucursal / fulfillment

Agregar:

- Variable Quantity Fulfillment;
- picking con medición real;
- tolerancias;
- sustituciones;
- vehículo como custodia/ubicación operativa;
- distribución/reparto progresivo.

## Área omnicanal

Reformular ecommerce como:

> **Straleon Storefront / Web Commerce — superficie nativa de primera clase**

Mantener:

- WhatsApp Business;
- Instagram;
- marketplaces;
- catálogo/stock/precio compartidos;
- publicación automática;
- pausa por agotado;
- trazabilidad publicación → pedido/venta.

## Área motor comercial

Agregar:

- presentación;
- conversión;
- venta por unidad/peso/volumen/longitud;
- reglas mayorista/minorista;
- price policy compatible con cantidad real preparada.

## Área lotes/GS1/trazabilidad

Agregar:

- contenedores físicos;
- chain of custody;
- transformación;
- trazabilidad input → output → venta;
- recall por lote/transformación.

## Área reposición/planificación

Agregar progresivamente:

- consumo/rendimiento histórico;
- reposición considerando presentaciones y contenedores;
- forecast sin convertirlo en autoridad automática.

## Área CRM/analítica

Agregar:

- recompra;
- preferencias de fulfillment;
- comportamiento B2C/B2B;
- rendimiento y margen real;
- análisis por proveedor/proceso cuando corresponda.

## Área P20 / módulos-capacidades-superficies

Agregar:

- Business Profiles ampliados;
- banco de validación multirrubro;
- perfiles combinables;
- Storefront como superficie configurable;
- hardware como superficie física/capabilities;
- regla de no fork por rubro salvo dominio realmente exclusivo.

## Nuevo bloque futuro: Physical Commerce & Transformation

Crear una agrupación futura —sin forzar numeración mientras P13 técnico está activo— que abarque:

1. Units & Measures Foundation;
2. Presentations & Conversions;
3. Fractional Containers;
4. Commercial Availability Engine;
5. Variable Quantity Fulfillment;
6. Fulfillment Preferences;
7. Transformation Ledger;
8. Yield & Cost Allocation;
9. Production Commercial Lite;
10. Traceability Chain;
11. Storage Conditions / Cold Chain Readiness;
12. Logistics Custody.

El orden exacto deberá decidirse mediante RECON sobre el código real antes de implementación.

---

# 12. Criterios de aceptación arquitectónica futuros

Una nueva decisión de catálogo/inventario/comercio no deberá considerarse suficientemente general si falla de manera estructural en uno de estos escenarios sin razón de dominio válida.

Pruebas mentales mínimas:

1. vender un teléfono serializado y luego recibirlo en reparación;
2. encontrar un repuesto por OEM y compatibilidad de vehículo;
3. vender un vestido por talle/color en una sucursal;
4. vender huevos por unidad/docena/maple/caja sin cuatro stocks paralelos;
5. vender queso por peso/lote/vencimiento;
6. recibir y clasificar una devolución multirrubro con condición individual;
7. vender 12 L desde un tambor abierto sin abrir otro;
8. vender 25 L agotando el abierto y continuando desde el siguiente;
9. tomar pedido web por 1 kg y cobrar 1,086 kg realmente preparados dentro de tolerancia;
10. transformar una media res en cortes, subproductos y merma;
11. fabricar chacinados conservando origen de lotes y lote resultante;
12. vender el mismo stock desde POS y Storefront sin sobreventa;
13. mover mercadería a un vehículo y conservar custodia durante tránsito;
14. publicar sólo productos/condiciones autorizados para el canal.

---

# 13. Anti-patrones prohibidos

No implementar:

- una base de datos distinta para Storefront como verdad comercial;
- `stock_web` y `stock_local` independientes;
- una tabla de producto gigante con campos específicos de todos los rubros;
- forks de Straleon por cliente;
- lógica `if rubro == carniceria` cuando existe una capacidad generalizable;
- stock ficticio duplicado por presentación;
- abrir otro contenedor ignorando saldo abierto cuando la política lo prohíbe;
- editar stock para simular transformación;
- ocultar merma;
- falsear peso real para coincidir con cantidad solicitada;
- permitir al browser público acceso directo a DB;
- hacer del hardware la autoridad de inventario/venta;
- asumir que una lectura de balanza/caudalímetro por sí sola confirma una venta;
- publicar toda existencia interna automáticamente;
- usar IA para inventar compatibilidades, rendimientos, cantidades o sustituciones como verdad.

---

# 14. Principios vinculantes para copiar a los maestros

## Storefront First-Class

> **Straleon Storefront / Web Commerce es una superficie nativa de la plataforma. Comparte la verdad de catálogo, precios, disponibilidad, clientes, pedidos y comercio del Core mediante contratos seguros; no constituye una base de datos comercial paralela ni una integración secundaria.**

## Validación Multirrubro Real

> **El Core y sus superficies no se considerarán suficientemente generales por abstracción teórica. Las decisiones de catálogo, inventario, comercio, precios, Storefront y operación deberán contrastarse contra negocios reales con modelos distintos. Las diferencias se resolverán preferentemente mediante capacidades, políticas, perfiles y superficies configurables; no mediante bifurcaciones del Core.**

## Hardware First-Class

> **Straleon trata los dispositivos comerciales como extensiones físicas de la misma plataforma. Scanner, balanza, impresión, etiquetado, cobro y dispositivos móviles se integran mediante capacidades y adapters explícitos, sin convertir un fabricante, protocolo o dispositivo en la verdad del negocio.**

## Physical Reality Before Convenience

> **Cuando una operación comercial depende de una realidad física —peso real, contenedor de origen, lote, transformación, merma, custodia o condición— Straleon conserva esa realidad explícitamente y no la simplifica mediante ajustes silenciosos.**

## One Core, Different Experiences

> **Los rubros pueden requerir experiencias, perfiles y superficies radicalmente diferentes sin exigir un Core diferente. Sólo una regla verdaderamente exclusiva de dominio justifica un vertical especializado.**

---

# 15. Próxima acción documental

Este amendment deberá integrarse, en un único corte documental coherente, a:

1. `docs/README.md` — índice/puntero de recuperación;
2. `docs/06_ROADMAP.md` — roadmap maestro y asignación a áreas;
3. `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md` — North Star y criterios vinculantes.

La sincronización deberá hacerse **después de reconciliar el HEAD técnico activo**, para no introducir drift durante P13.B.

No se debe abrir implementación de estos motores desde este documento por presunción. Cada bloque deberá comenzar con RECON del código real y reutilización de las fundaciones ya publicadas.

---

# 16. Resultado de esta decisión

Straleon queda orientado a representar, desde un único Core, tanto operaciones discretas como físicas/variables:

```text
producto unitario
producto serializado
variante
lote
presentación
contenedor fraccionable
peso/volumen real
transformación
subproducto
merma
producción liviana
servicio/reparación
mayorista/distribución
Storefront
hardware
```

La meta no es agregar funciones por cantidad.

La meta es que cada función nueva **potencie la verdad operacional ya construida** y permita que esa misma verdad se utilice correctamente en mostrador, depósito, administración, reparto, hardware y web pública.