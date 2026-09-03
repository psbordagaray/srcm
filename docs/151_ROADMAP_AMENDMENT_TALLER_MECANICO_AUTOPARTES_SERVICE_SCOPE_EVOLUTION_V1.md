# Straleon — Roadmap Amendment: Taller Mecánico + Autopartes + Evolución de Alcance V1

Estado: **PROPUESTA VINCULANTE PARA PRÓXIMA SINCRONIZACIÓN MAESTRA**  
Fecha: **2026-09-03**  
Producto canónico: **Straleon**  
Complementa: `docs/150_ROADMAP_AMENDMENT_STRALEON_STOREFRONT_MULTIRUBRO_PHYSICAL_COMMERCE_V1.md`  
Naturaleza: **documental / arquitectura de producto**  
Código funcional: **NO MODIFICADO**  
Base de datos: **NO MODIFICADA**  
Producción: **NO MODIFICADA**

---

## 1. Caso real incorporado

Se incorpora como escenario oficial de validación un **taller mecánico integral** que:

- realiza reparaciones mecánicas de múltiples tipos;
- recibe vehículos de clientes;
- diagnostica fallas;
- emite presupuestos;
- descubre durante el trabajo reparaciones adicionales convenientes o necesarias;
- necesita ampliar el alcance presupuestado sin borrar ni falsear lo ya autorizado;
- compra repuestos nuevos;
- compra/utiliza autopartes usadas;
- consume repuestos propios de stock;
- puede tercerizar trabajos específicos;
- vende autopartes, repuestos y accesorios al público desde el mismo negocio;
- cobra servicios, repuestos y ventas de mostrador bajo una misma organización.

El caso debe resolverse con **un único Straleon Core** y capacidades combinables, no mediante un producto separado para talleres.

---

## 2. Cobertura ya existente que debe preservarse

El Core Service actual ya posee fundamentos útiles para este caso:

- activo técnico `Vehicle`;
- identificadores VIN, patente, número de motor y número de chasis;
- orden de servicio e ingreso documentado;
- diagnósticos versionados;
- presupuestos de servicio versionados;
- decisión del cliente sobre presupuesto;
- trabajos propios y tercerizados;
- custodia externa;
- repuestos requeridos por trabajo;
- consumo desde inventario;
- compra directa de repuestos afectada a la orden;
- condición de inventario `new`, `used`, `refurbished`, `damaged`, `display`;
- control de calidad;
- entrega;
- garantía y reingreso correctivo;
- evidencias privadas/fotografías;
- venta/cobro/cierre comercial;
- catálogo, inventario y comercio reutilizables para venta de autopartes/accesorios.

Regla de continuidad:

> Lo ya implementado en Service no debe ser reemplazado por un “módulo taller” paralelo. El taller amplía y combina las mismas capacidades.

---

# 3. Brecha crítica descubierta: reparación adicional durante trabajo autorizado

El flujo real de un taller no termina con una única secuencia:

```text
ingreso
→ diagnóstico
→ presupuesto
→ aprobación
→ reparación
→ entrega
```

Durante una reparación pueden aparecer nuevos hallazgos.

Ejemplo:

```text
Presupuesto V1 aprobado
- cambio de amortiguadores
- bujes

Trabajo iniciado
        ↓
Al desmontar se descubre:
- rótula con juego severo
- extremo de dirección deteriorado
```

Straleon no debe:

- modificar silenciosamente el presupuesto V1 aprobado;
- agregar trabajos cobrables sin autorización;
- cancelar lo ya aprobado sólo porque apareció un hallazgo nuevo;
- obligar a duplicar toda la orden de servicio;
- confundir una recomendación con una reparación autorizada.

---

# 4. Nueva capacidad: Service Scope Evolution / Additional Work Authorization

Se define una capacidad transversal para servicios donde el alcance puede evolucionar durante la ejecución.

Nombre conceptual:

## **Service Scope Evolution**

con hechos específicos de:

## **Supplemental Finding / Additional Work Proposal / Additional Work Authorization**

Flujo recomendado:

```text
ALCANCE APROBADO V1
        ↓
TRABAJO EN CURSO
        ↓
NUEVO HALLAZGO
        ↓
PROPUESTA ADICIONAL V2
        ↓
CLIENTE DECIDE
     ┌──┴────┐
  APRUEBA  RECHAZA
     │        │
     ↓        ↓
SE AMPLÍA   V1 CONTINÚA
EL ALCANCE  SIN ALTERARSE
```

Regla vinculante:

> **Una ampliación de reparación nunca reescribe el alcance ya autorizado. Crea un nuevo hecho/version de propuesta y una decisión independiente del cliente.**

---

# 5. Separar hallazgo, recomendación, urgencia y autorización

Un nuevo hallazgo debe poder registrar:

- descripción;
- evidencia/foto/video;
- componente afectado;
- severidad;
- seguridad/criticidad;
- reparación recomendada;
- consecuencia de no realizarla;
- mano de obra adicional;
- repuestos adicionales;
- tiempo adicional estimado;
- importe adicional;
- impacto en fecha prometida;
- quién lo detectó;
- cuándo;
- si el vehículo puede continuar usándose de forma segura;
- decisión del cliente.

Pero estas dimensiones no son equivalentes.

```text
HALLAZGO ≠ RECOMENDACIÓN ≠ PRESUPUESTO ≠ AUTORIZACIÓN ≠ TRABAJO REALIZADO
```

---

# 6. Decisiones parciales del cliente

Un cliente puede aprobar sólo parte de lo descubierto.

Ejemplo:

```text
Adicional #2

[✓] Cambiar rótula             $...
[ ] Cambiar extremos           $...
[✓] Alinear                    $...
[ ] Cambiar amortiguadores traseros $...
```

Straleon debe conservar:

- qué se recomendó;
- qué se cotizó;
- qué se aprobó;
- qué se rechazó/postergó;
- qué efectivamente se realizó;
- qué quedó pendiente para una futura visita.

Esto debe integrarse conceptualmente con P13.C Presupuesto / Commercial Intent sin duplicar el dominio Service.

---

# 7. Vehicle Technical Passport

La fundación actual de `ServiceAsset` ya permite vehículo e identificadores automotores. Se propone enriquecer progresivamente la superficie automotriz mediante un **Vehicle Technical Passport** derivado del mismo activo técnico.

Campos/capacidades futuras orientativas:

- marca;
- modelo;
- versión;
- año;
- VIN;
- patente;
- número de motor;
- número de chasis;
- motorización;
- combustible;
- kilometraje/odómetro observado por visita;
- observaciones de ingreso;
- historial de diagnósticos;
- trabajos realizados;
- repuestos utilizados;
- trabajos rechazados/postergados;
- garantías;
- próxima atención sugerida;
- documentos/evidencias.

El kilometraje debe registrarse como **observación fechada**, no como un único campo histórico reescribible.

---

# 8. Maintenance Recommendations / Deferred Work

Un trabajo recomendado y no realizado no debe desaparecer.

Ejemplo:

```text
Vehículo: VW Gol AB123CD

Realizado hoy:
✓ frenos delanteros
✓ cambio de aceite

Recomendado / postergado:
! amortiguadores traseros
! correa auxiliar

Revisar en:
~ 5.000 km / próxima visita
```

Esto permite:

- continuidad entre visitas;
- atención preventiva;
- mejor experiencia de cliente;
- futuros recordatorios con consentimiento;
- Storefront/Customer Surface mostrando pendientes autorizados para el cliente.

Nunca debe convertir una recomendación en deuda, venta o reparación ejecutada.

---

# 9. Repuestos nuevos, usados y reacondicionados

El mismo `CatalogProduct` puede participar como:

- producto de venta de mostrador;
- repuesto propio en stock;
- repuesto comprado específicamente para una orden;
- repuesto usado;
- repuesto reacondicionado.

La condición física debe seguir separada del producto.

Ejemplo:

```text
Alternador VW X

Stock:
- Nuevo        2
- Usado        1
- Reacondicionado 3
```

El presupuesto debe indicar la condición ofertada cuando sea comercialmente relevante.

Un cliente que autorizó:

```text
Alternador usado
```

no debe recibir silenciosamente uno nuevo más caro o uno reacondicionado distinto sin regla/aceptación correspondiente.

---

# 10. Provenance para autopartes usadas

Para autopartes usadas, especialmente de mayor valor o seguridad, se recomienda futura capacidad opcional de procedencia:

- proveedor/desarmadero;
- documento de compra;
- vehículo donante cuando se conozca;
- VIN/patente donante cuando legalmente corresponda registrarlo;
- código de pieza/OEM;
- condición observada;
- prueba realizada;
- garantía ofrecida;
- fecha de ingreso;
- fotos/evidencia;
- serial/identificador cuando exista.

Esto no debe ser obligatorio para cada tornillo. Debe activarse según categoría, riesgo y política del negocio.

---

# 11. Repuesto del taller vs repuesto del cliente

Straleon deberá distinguir el origen del repuesto.

Ejemplos:

```text
SOURCE=OWN_STOCK
SOURCE=DIRECT_PURCHASE_FOR_ORDER
SOURCE=CUSTOMER_SUPPLIED
SOURCE=USED_STOCK
SOURCE=EXTERNAL_SPECIALIST
```

Un repuesto aportado por el cliente:

- no debe aumentar inventario propio;
- debe quedar bajo custodia asociada a la orden;
- debe poder documentar estado/identificación;
- puede tener políticas distintas de garantía/responsabilidad.

---

# 12. Venta de autopartes y accesorios + taller en la misma organización

Esto es un requisito explícito y **debe soportarse sin separar negocios artificialmente**.

Topología:

```text
                         TALLER / CASA DE REPUESTOS
                                  │
                            STRALEON CORE
                                  │
       ┌──────────────────────────┼──────────────────────────┐
       │                          │                          │
      POS                       SERVICE                   STOREFRONT
       │                          │                          │
 venta repuestos            reparación vehículo      repuestos/accesorios
 venta accesorios           diagnóstico              turnos/consultas futuras
 venta lubricantes          presupuesto              seguimiento reparación
                            trabajo
                            repuestos
                            garantía
```

El mismo producto puede ser vendido directamente o consumido en reparación.

Ejemplo:

```text
Filtro de aceite Mann W712

Disponible: 8

Venta mostrador: -1
Reparación orden #523: -1
────────────────────────
Disponible resultante: 6
```

No existe `stock_taller` y `stock_venta` como verdades independientes salvo ubicaciones físicas distintas justificadas por inventario.

---

# 13. Mixed Commercial Settlement

Una misma atención puede incluir:

- mano de obra;
- repuestos consumidos;
- insumos;
- servicios tercerizados;
- accesorios adicionales vendidos;
- descuentos autorizados;
- pago múltiple;
- cuenta corriente cuando aplique.

La venta/cierre final debe preservar el origen de cada componente y nunca transformar retrospectivamente los hechos técnicos.

---

# 14. Compatibilidad con JB Repuestos / Knowledge Universe

El caso Taller Mecánico se potencia con la misma capacidad de compatibilidades descubierta por JB Repuestos.

Un mecánico debería poder buscar:

```text
Vehículo
VW Gol Trend 2016 1.6
        ↓
Repuestos compatibles
        ↓
Stock propio / alternativas / proveedor
```

Y una pieza podrá conocerse por:

- OEM;
- fabricante;
- equivalencia;
- supersesión;
- aplicación vehicular.

La compatibilidad debe basarse en evidencia estructurada y nunca ser inventada automáticamente por IA.

---

# 15. Storefront / Customer Surface para taller

La superficie pública/cliente podrá evolucionar para permitir:

- solicitar turno;
- registrar vehículo;
- ver estado de reparación;
- recibir presupuesto;
- aprobar/rechazar presupuesto;
- recibir **presupuesto adicional durante la reparación**;
- aprobar parcialmente trabajos adicionales;
- consultar trabajos realizados;
- consultar trabajos recomendados/postergados;
- descargar documentos/facturas;
- ver garantías;
- comprar repuestos y accesorios;
- repetir compras;
- consultar compatibilidad cuando esté habilitada.

Ejemplo:

```text
Tu vehículo — VW Gol AB123CD

Estado: EN REPARACIÓN

Trabajo aprobado original          $...

Nuevo hallazgo:
Rótula delantera derecha con juego
[Ver fotos]

Recomendación adicional             $...

[APROBAR] [RECHAZAR] [CONSULTAR]
```

La decisión debe ingresar instantáneamente a la misma orden interna, sin WhatsApp como única fuente de verdad.

---

# 16. Recursos de taller — evolución futura

Sin convertir Straleon inmediatamente en software industrial de taller, se recomienda dejar preparada evolución para:

- agenda/turnos;
- mecánico/técnico asignado;
- bahía/box/elevador;
- duración estimada;
- disponibilidad de recurso;
- checklist por tipo de trabajo;
- prueba de ruta;
- inspección final;
- torque/mediciones cuando sea relevante;
- herramientas/equipos de diagnóstico como evidencia futura;
- mantenimiento preventivo.

Estas capacidades requieren RECON y no se consideran implementadas por este documento.

---

# 17. Criterio de aceptación específico

Straleon deberá poder representar sin forks este escenario:

1. ingresa un vehículo identificado por patente/VIN;
2. se documenta su estado y motivo de ingreso;
3. se diagnostican fallas;
4. se emite presupuesto V1;
5. el cliente aprueba V1;
6. se inicia el trabajo;
7. se consumen repuestos propios y/o comprados específicamente;
8. durante desmontaje aparece un hallazgo adicional;
9. se emite propuesta adicional V2 sin reescribir V1;
10. el cliente aprueba sólo parte del adicional;
11. se ejecuta únicamente el alcance autorizado;
12. queda una recomendación pendiente para próxima visita;
13. se realiza control final y entrega;
14. los repuestos utilizados descuentan el mismo inventario que abastece POS/Storefront;
15. en paralelo el negocio puede vender autopartes/accesorios a clientes que no ingresaron un vehículo al taller.

---

# 18. Incorporación al roadmap

Al sincronizar los documentos maestros, este caso deberá agregarse a **Validación Multirrubro Real** como:

## Taller Mecánico + Autopartes

Capacidades críticas:

- Vehicle Service Asset;
- VIN/patente/motor/chasis;
- diagnóstico;
- presupuesto versionado;
- **Service Scope Evolution / Additional Work Authorization**;
- trabajo propio/tercerizado;
- repuestos nuevos/usados/reacondicionados;
- compra directa para orden;
- consumo desde stock;
- provenance opcional de autopartes usadas;
- mantenimiento/recomendaciones diferidas;
- compatibilidad de repuestos;
- venta mostrador;
- Storefront;
- Customer Surface;
- garantía;
- historial técnico del vehículo.

Debe añadirse además un criterio transversal:

> **Los servicios cuyo alcance puede crecer durante la ejecución deben preservar el alcance previamente autorizado y modelar cada hallazgo, propuesta adicional y decisión como hechos posteriores explícitos.**

---

# 19. Anti-patrones prohibidos

- editar el presupuesto aprobado para agregar una reparación nueva;
- ejecutar/cobrar trabajo adicional sin autorización suficiente;
- borrar un trabajo rechazado/postergado;
- crear un segundo stock exclusivo para taller cuando es la misma mercadería;
- considerar repuesto usado equivalente a nuevo sin condición explícita;
- incorporar al stock propio un repuesto aportado por el cliente;
- perder la procedencia de una autoparte usada cuando la política exige trazabilidad;
- tratar un vehículo solamente como texto libre;
- perder odómetro/historial mediante sobrescritura;
- usar WhatsApp como única evidencia de aprobación cuando la decisión puede estructurarse en Straleon;
- permitir que IA invente compatibilidades, diagnósticos o autorizaciones.

---

# 20. Conclusión

El taller mecánico integral **no requiere un Core nuevo**.

Gran parte de su operación ya está cubierta por las fundaciones de Service, Inventario, Catálogo y Comercio de Straleon.

La brecha principal que este caso descubre es **la evolución autorizada del alcance durante una reparación ya iniciada**, acompañada por una superficie automotriz más rica del activo técnico y por trazabilidad opcional de autopartes usadas.

La dirección recomendada es:

```text
Core existente
+ Vehicle Technical Passport
+ Service Scope Evolution
+ Used Part Provenance
+ Compatibilities
+ Storefront / Customer Surface
```

sin bifurcar Straleon por rubro.