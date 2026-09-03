# Straleon — Roadmap Amendment: Service Scope Evolution Cross-Vertical V1

Estado: **PROPUESTA VINCULANTE PARA PRÓXIMA SINCRONIZACIÓN MAESTRA**  
Fecha: **2026-09-03**  
Producto canónico: **Straleon**  
Complementa: `docs/150_ROADMAP_AMENDMENT_STRALEON_STOREFRONT_MULTIRUBRO_PHYSICAL_COMMERCE_V1.md` y `docs/151_ROADMAP_AMENDMENT_TALLER_MECANICO_AUTOPARTES_SERVICE_SCOPE_EVOLUTION_V1.md`  
Naturaleza: **documental / arquitectura de producto**  
Código funcional: **NO MODIFICADO**  
Base de datos: **NO MODIFICADA**  
Producción: **NO MODIFICADA**

---

## 1. Corrección de alcance

`Service Scope Evolution / Additional Work Authorization` no pertenece exclusivamente al Taller Mecánico.

Se formaliza como capacidad transversal del dominio **Straleon Service** para cualquier servicio técnico cuyo diagnóstico pueda evolucionar durante la apertura, desmontaje, prueba o ejecución del trabajo.

Casos oficiales de validación inicial:

- SULU — computación, celulares, electrónica y reparaciones;
- Taller Mecánico + Autopartes;
- futuros servicios técnicos de electrodomésticos, herramientas, maquinaria u otros equipos.

Regla:

> **Una reparación puede descubrir hechos nuevos después de que el cliente haya autorizado el alcance inicial. Straleon debe conservar lo ya autorizado y modelar todo nuevo hallazgo, propuesta, decisión y trabajo como hechos posteriores explícitos.**

---

# 2. Caso SULU — daño adicional descubierto durante desmontaje

Ejemplo:

```text
Notebook ingresada
      ↓
Diagnóstico inicial
      ↓
Presupuesto V1
- reemplazo de teclado
      ↓
Cliente aprueba V1
      ↓
Desarme
      ↓
Se descubre conector de alimentación dañado
```

Straleon no debe modificar silenciosamente V1.

Debe permitir:

```text
Hallazgo adicional #1
Conector de alimentación dañado
Evidencia: fotos / observaciones

Propuesta adicional V2
- reemplazo de conector
- mano de obra adicional
- impacto en plazo

Cliente:
[APROBAR] [RECHAZAR] [CONSULTAR]
```

Si el cliente rechaza V2, V1 continúa según lo autorizado cuando sea técnicamente viable.

---

# 3. Caso SULU — mejora opcional descubierta durante la reparación

No todo adicional nace de una falla.

Ejemplo real:

```text
PC / Notebook
Disco rígido HDD funcional
        ↓
Técnico detecta oportunidad de mejora
        ↓
Recomienda SSD
```

La recomendación puede ser comercialmente conveniente sin ser reparación obligatoria.

Debe distinguirse:

```text
HALLAZGO TÉCNICO
HDD funcional pero lento

RECOMENDACIÓN
Migrar a SSD

PROPUESTA COMERCIAL ADICIONAL
SSD + instalación + migración/clonado si corresponde

DECISIÓN DEL CLIENTE
aprobar / rechazar / consultar / postergar
```

Regla:

> **Una mejora recomendada no debe presentarse como falla ni como trabajo obligatorio.**

---

# 4. Represupuesto + compra + venta/consumo del repuesto

El caso SULU confirma que una ampliación de alcance puede activar una cadena comercial completa.

Ejemplo:

```text
Reparación aprobada V1
        ↓
Recomendación SSD
        ↓
Propuesta adicional V2
        ↓
Cliente aprueba
        ↓
¿SSD disponible?
   ┌────┴────┐
   sí        no
   ↓          ↓
reservar/   comprar SSD
asignar       ↓
stock       recibir/asignar
   └────┬────┘
        ↓
instalar / consumir en orden
        ↓
venta/cierre comercial
```

La implementación futura deberá reutilizar:

- `ServicePartRequirement`;
- inventario;
- compra directa afectada a orden;
- consumo de repuesto;
- Commerce/checkout;
- Money/Numerical Integrity;
- fiscalidad;
- garantía;
- auditoría.

No crear un flujo especial `upgrade_ssd` ni lógica específica de SULU.

---

# 5. Distinción entre compra, consumo técnico y venta comercial

El mismo SSD puede participar en varios hechos relacionados pero distintos.

```text
Compra al proveedor
        ↓
Ingreso/asignación según política
        ↓
Consumo técnico en Service Order
        ↓
Componente comercial facturable al cliente
```

Cuando el repuesto se compra específicamente para la orden, Straleon ya tiene una fundación de compra directa afectada a reparación; la evolución deberá preservar esa semántica y converger con el cierre comercial sin inventar movimientos de inventario que nunca ocurrieron físicamente.

Regla:

> **Compra del repuesto ≠ consumo técnico ≠ venta al cliente ≠ cobro.**

Los hechos se relacionan, pero no se fusionan artificialmente.

---

# 6. Clasificación del adicional

`Service Scope Evolution` deberá poder distinguir al menos:

- `REQUIRED_REPAIR` — reparación adicional necesaria para resolver el problema o completar el trabajo;
- `SAFETY_CRITICAL` — hallazgo que compromete seguridad y requiere advertencia explícita;
- `RECOMMENDED_REPAIR` — reparación conveniente pero no necesariamente imprescindible en ese momento;
- `OPTIONAL_UPGRADE` — mejora voluntaria de rendimiento/capacidad/experiencia;
- `PREVENTIVE_MAINTENANCE` — mantenimiento preventivo sugerido;
- `CUSTOMER_REQUESTED_ADDITION` — agregado solicitado por el cliente durante el trabajo.

Los nombres definitivos deberán fijarse en RECON; la semántica es vinculante.

---

# 7. Presupuesto incremental, no reemplazo histórico

Ejemplo:

```text
Orden #501

V1 APROBADA
- cambio de teclado                $100

ADICIONAL A1 APROBADO
- conector alimentación             $30

ADICIONAL A2 RECHAZADO
- SSD 1 TB                         $120

ADICIONAL A3 APROBADO
- SSD 500 GB                        $70
```

El cierre debe poder explicar:

- alcance original;
- adicionales propuestos;
- decisiones de cada adicional;
- piezas compradas/usadas;
- trabajo efectivamente ejecutado;
- importe final;
- quién autorizó cada cambio y cuándo.

Nunca convertir esto en una única versión final que borre la historia.

---

# 8. Sustituciones y alternativas dentro del adicional

El cliente puede elegir entre opciones.

Ejemplo:

```text
Mejora de almacenamiento

Opción A
SSD 500 GB   $...

Opción B
SSD 1 TB     $...

Opción C
No realizar mejora
```

La decisión debe referenciar exactamente la opción aceptada.

Esto reutiliza la filosofía existente de presupuestos con opciones y debe converger con P13.C Commercial Intent.

---

# 9. Autorización remota desde Customer Surface

Para SULU y Taller Mecánico, Straleon Customer/Storefront podrá permitir que el cliente decida sin ir al local.

Ejemplo:

```text
Tu Notebook Lenovo
Estado: EN REPARACIÓN

Detectamos una mejora opcional:
Tu equipo posee HDD de 1 TB.
Podemos instalar SSD para mejorar significativamente los tiempos de respuesta.

Opción 1 — SSD 500 GB   $...
Opción 2 — SSD 1 TB     $...

[APROBAR]
[RECHAZAR]
[CONSULTAR]
```

La decisión debe entrar estructuradamente a la orden y conservar:

- identidad/evidencia suficiente;
- revisión/propuesta exacta;
- importe y condiciones visibles;
- instante de decisión;
- autoría;
- canal.

WhatsApp puede notificar o enlazar, pero no debe ser la única fuente de verdad cuando Straleon puede capturar la autorización estructurada.

---

# 10. Impacto en disponibilidad y compras

Al aprobar un adicional, el sistema deberá resolver mediante el Commercial Availability Engine futuro:

```text
¿Existe el repuesto elegible?
        ↓
Sí → asignar/reservar según política
No  → necesidad de compra / proveedor / espera
```

Una propuesta no debe descontar stock automáticamente salvo política explícita.

Una aprobación puede crear hold/asignación o requerimiento según contrato futuro.

La compra directa para orden y el stock propio deben seguir siendo fuentes distintas y trazables.

---

# 11. Customer-Supplied Parts

La capacidad también debe contemplar que el cliente aporte el componente.

Ejemplo:

```text
Cliente trae su propio SSD
```

Entonces:

- no se registra como compra del comercio;
- no aumenta inventario comercial propio;
- queda asociado bajo custodia a la orden;
- puede existir mano de obra de instalación;
- garantía/responsabilidad puede diferir según política y normativa;
- debe quedar explícito en presupuesto y cierre.

---

# 12. Garantía del trabajo adicional

Cada adicional realizado puede tener condiciones de garantía propias o compartir las de la orden según política.

La garantía debe poder responder:

```text
¿Qué trabajo falló?
¿Qué repuesto se utilizó?
¿De qué adicional/autorización provenía?
¿Quién lo proveyó?
¿Qué garantía correspondía?
```

No se debe perder esta relación al consolidar el cierre comercial.

---

# 13. Aplicación transversal

El patrón debe poder utilizarse en:

## SULU

- celular: al abrir aparece flex/conector/batería dañada;
- notebook: teclado aprobado + daño de placa descubierto;
- PC: reparación inicial + recomendación SSD/RAM;
- TV: fuente dañada + tiras LED deterioradas;
- impresora: reparación inicial + rodillos/kit de mantenimiento recomendado.

## Taller mecánico

- al desmontar aparecen rótulas, bujes, pérdidas o piezas deterioradas;
- mantenimiento preventivo adicional;
- mejora/opción de repuesto;
- pieza nueva vs usada/reacondicionada.

## Otros futuros servicios

- electrodomésticos;
- herramientas;
- maquinaria;
- refrigeración;
- electrónica industrial;
- bicicletas/motos;
- otros servicios técnicos.

---

# 14. Regla arquitectónica actualizada

La cláusula de `docs/151...` debe interpretarse de forma transversal:

> **Service Scope Evolution. Cuando durante un servicio ya autorizado aparece un nuevo hallazgo, reparación conveniente, mantenimiento preventivo, mejora opcional o agregado solicitado, Straleon conserva el alcance previamente autorizado y modela el adicional como una propuesta posterior explícita, con alternativas, impacto comercial, decisión del cliente, repuestos y trabajo ejecutado trazables.**

---

# 15. Criterios de aceptación

Straleon no deberá considerar completa esta capacidad hasta poder demostrar, al menos:

1. una notebook ingresa por reparación de teclado;
2. V1 se aprueba y queda inmutable;
3. durante desarme aparece daño adicional;
4. se crea adicional A1 sin reescribir V1;
5. el cliente aprueba A1;
6. aparece una recomendación opcional de SSD;
7. se ofrecen 500 GB / 1 TB / no realizar;
8. el cliente elige una opción concreta;
9. si no hay SSD, se registra compra directa para esa orden;
10. el SSD comprado se vincula al requerimiento correspondiente;
11. se instala/consume correctamente;
12. el componente facturable converge al cierre comercial;
13. el cierre conserva la relación compra → repuesto → trabajo → adicional → autorización → venta;
14. un adicional rechazado permanece en historia sin convertirse en deuda, venta ni trabajo ejecutado;
15. la garantía puede atribuirse al trabajo/repuesto correspondiente.

---

# 16. Anti-patrones prohibidos

- reemplazar V1 por V2 y perder el alcance originalmente aprobado;
- presentar una mejora opcional como avería obligatoria;
- instalar/cobrar un adicional no autorizado;
- convertir recomendación en venta automáticamente;
- descontar stock sólo por emitir una propuesta;
- registrar compra del proveedor como si fuera directamente una venta;
- duplicar el dominio Service para SULU y Taller Mecánico;
- crear lógica por rubro cuando la semántica es transversal;
- usar WhatsApp como única evidencia estructural de autorización;
- borrar adicionales rechazados/postergados;
- permitir a IA generar autorización o convertir una sugerencia en trabajo aprobado.

---

# 17. Relación con el roadmap

Al sincronizar `docs/README.md`, `docs/06_ROADMAP.md` y `docs/33_VISION_Y_ROADMAP_FULL_SRCM_2026.md`, incorporar `Service Scope Evolution` dentro de la evolución del dominio Service como capacidad transversal, y usar tanto **SULU** como **Taller Mecánico + Autopartes** como escenarios obligatorios de validación.

Debe conectarse con:

- P13.C Presupuesto / Commercial Intent;
- Inventario;
- Compras;
- Commerce;
- Commercial Availability Engine;
- Customer Surface / Storefront;
- garantía;
- evidencia privada;
- notificaciones;
- Numerical Integrity;
- fiscalidad.

No abrir implementación sin RECON del código real vigente.