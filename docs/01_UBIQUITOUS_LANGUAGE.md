# Lenguaje ubicuo de SRCM OS

Este documento define el significado oficial de los términos utilizados en el negocio, la documentación, la interfaz y el código de SRCM.

Cuando dos palabras parezcan similares, deberá utilizarse el término definido aquí.

---

## Catálogo

Conjunto organizado de artículos maestros, categorías, marcas, fabricantes, modelos, identificadores, componentes y compatibilidades disponibles en SRCM.

El catálogo describe conocimiento técnico y comercial. No representa existencias físicas.

---

## Rubro

Área general de actividad comercial o técnica.

Ejemplos:

- Controles remotos y televisión
- Telefonía celular
- Computación e informática
- Accesorios
- Repuestos

Un rubro puede contener múltiples categorías.

---

## Categoría de producto

Clasificación utilizada para organizar artículos maestros que comparten una función o naturaleza comercial.

Ejemplos:

- Controles remotos
- Módulos de pantalla
- Baterías
- Cargadores
- Memorias RAM
- Discos SSD

Nombre técnico actual en el código:

`ProductCategory`

---

## Artículo maestro

Identidad única y normalizada de algo que puede comprarse, venderse, utilizarse, repararse o relacionarse técnicamente.

Un artículo maestro existe una sola vez en SRCM, aunque diferentes proveedores lo describan con nombres distintos.

Ejemplo canónico:

`Módulo de pantalla Samsung Galaxy A32 4G SM-A325F`

Posibles nombres externos vinculados:

- Módulo Sam A32
- Display A325
- Pantalla Samsung A32
- Módulo A325F

Esos nombres externos no crean artículos nuevos. Se registran como alias u ofertas vinculadas al artículo maestro.

---

## Alias

Nombre alternativo, abreviatura, error frecuente o descripción externa utilizada para encontrar un artículo maestro.

Un alias nunca reemplaza el nombre canónico.

---

## Nombre canónico

Nombre oficial, normalizado y aprobado de un artículo maestro dentro de SRCM.

Debe ser claro, consistente y técnicamente preciso.

---

## Producto

Término comercial general.

En conversaciones con usuarios puede utilizarse como sinónimo informal de artículo. En el modelo de dominio deberá preferirse `Artículo maestro` cuando se hable de su identidad global.

---

## Unidad

Ejemplar físico concreto de un artículo maestro.

Varias unidades pueden pertenecer al mismo artículo, pero cada una puede tener:

- número de serie;
- IMEI;
- QR;
- ubicación;
- estado;
- propietario;
- historial técnico.

Ejemplo:

`Samsung Galaxy A32` es el artículo maestro.

El teléfono con IMEI `35XXXXXXXXXXXXX` es una unidad específica.

---

## Activo

Objeto físico o digital sobre el cual SRCM debe conservar identidad, estado, relaciones o historial durante su ciclo de vida.

Una unidad serializada normalmente será un activo.

Ejemplos:

- celular de un cliente;
- notebook;
- televisor;
- herramienta;
- equipo en reparación.

---

## Componente

Artículo o pieza que forma parte de otro artículo o activo.

Un componente puede estar compuesto, a su vez, por otros componentes.

Ejemplos:

- un automóvil contiene un motor;
- un motor contiene pistones y válvulas;
- un celular contiene una placa;
- una placa contiene chips y conectores.

---

## Relación de composición

Vínculo que indica que un artículo o componente forma parte de otro.

Debe poder navegarse en ambas direcciones:

- de un equipo hacia sus partes;
- de una pieza hacia todos los equipos que la utilizan.

---

## Marca

Identidad comercial bajo la cual se presenta un artículo.

Ejemplos:

- Samsung
- LG
- Only
- Xaea
- Bosch

La marca no necesariamente fabrica el artículo.

---

## Fabricante

Organización responsable de producir física o técnicamente un artículo o componente.

Puede ser diferente de la marca, el importador o el vendedor.

---

## Importador

Organización que introduce artículos al mercado nacional.

Ejemplo conocido:

- Xaea como importador de determinadas líneas de telefonía.

---

## Parte comercial

Identidad única de una persona o empresa que mantiene relaciones comerciales con una organización usuaria de SRCM.

Una parte comercial puede asumir distintos roles sin duplicarse:

- cliente;
- proveedor;
- contacto;
- profesional;
- representante.

Los roles cambian. La identidad permanece.

---

## Proveedor

Persona u organización que ofrece artículos o servicios a SULU.

Un proveedor no define la identidad maestra del artículo. Solo publica una oferta sobre él.

---

## Oferta de proveedor

Relación comercial entre un proveedor y un artículo maestro.

Puede contener:

- código propio del proveedor;
- descripción publicada;
- costo;
- moneda;
- disponibilidad informada;
- URL de origen;
- fecha de verificación;
- condiciones comerciales.

Un mismo artículo maestro puede tener muchas ofertas.

Una oferta no constituye stock, compra, deuda, venta ni precio final al cliente. La disponibilidad describe lo informado por el proveedor y debe conservar su fecha de verificación.

---

## Identificador

Dato utilizado para localizar o distinguir un artículo o una unidad.

Tipos previstos:

- código interno SRCM;
- código original;
- código alternativo;
- Part Number;
- número de serie;
- IMEI;
- código de barras;
- QR;
- código del proveedor.

---

## Compatibilidad

Relación técnica que indica que un artículo puede utilizarse, reemplazarse o funcionar con otro artículo, equipo o modelo.

Toda compatibilidad deberá indicar:

- fuente;
- fecha;
- nivel de confianza;
- estado de validación;
- persona o sistema que la confirmó.

---

## Evidencia

Prueba que respalda una afirmación, operación o relación.

Ejemplos:

- sitio oficial;
- manual técnico;
- fotografía;
- documento;
- factura;
- prueba realizada por SULU;
- historial de reparaciones;
- confirmación del fabricante.

---

## Fuente

Origen del dato o conocimiento.

Ejemplos:

- fabricante oficial;
- importador;
- proveedor;
- manual;
- catálogo;
- experiencia SULU;
- cliente;
- inteligencia artificial.

La fuente no determina por sí sola que el dato sea correcto. Debe evaluarse su confiabilidad.

---

## Conocimiento

Información validada, relacionada y reutilizable que ayuda a tomar mejores decisiones.

No es un comentario aislado.

Puede surgir de:

- casos reales;
- documentación oficial;
- reparaciones;
- ventas;
- pruebas;
- comparaciones;
- experiencia acumulada.

---

## Caso

Situación real que requiere una consulta, decisión, acción o seguimiento por parte de SULU.

Ejemplos:

- identificar un control remoto;
- recibir un celular para reparación;
- consultar una compatibilidad;
- realizar una venta;
- atender un reclamo.

---

## Expediente técnico

Historia completa de una unidad o activo específico.

Puede contener:

- propietarios;
- ingresos;
- diagnósticos;
- reparaciones;
- piezas reemplazadas;
- evidencias;
- técnicos;
- garantías;
- entregas.

---

## Evento

Hecho ocurrido dentro del ciclo de vida de una persona, caso, artículo, unidad, activo u operación.

Ejemplos:

- categoría creada;
- artículo recibido;
- precio modificado;
- equipo diagnosticado;
- unidad trasladada;
- reparación entregada.

Los eventos importantes no deben eliminarse.

---

## Organización

Empresa o negocio que utiliza SRCM y constituye la frontera propietaria de su información privada.

No debe confundirse con una `BusinessParty` de tipo `organization`: esa parte comercial representa una empresa externa que puede actuar como proveedor, cliente u otro contacto. `Organization` representa al negocio usuario de SRCM.

Cada organización conserva sus propios:

- miembros y roles;
- proveedores;
- ofertas comerciales;
- stock;
- costos;
- precios;
- clientes;
- compras;
- ventas;
- ubicaciones;
- auditoría;
- datos internos.

Un usuario puede pertenecer a más de una organización. La organización activa determina qué información privada puede consultar o modificar durante la sesión.

El catálogo maestro y el conocimiento compartido no llevan `organization_id`: permanecen separados de la información privada y podrán estar disponibles según el plan, los permisos y las reglas de SRCM.

---

## Regla de identidad única

Un artículo real debe existir una sola vez dentro del catálogo maestro.

Antes de crear un artículo, SRCM deberá buscar posibles coincidencias mediante:

- nombre normalizado;
- alias;
- marca;
- modelo;
- Part Number;
- códigos;
- compatibilidades;
- ofertas de proveedores.

Si existe una coincidencia probable, el sistema recomendará vincularla en lugar de crear un duplicado.

---

## Cobro

Hecho por el cual la organización recibe o declara recibir valor de un cliente
para aplicar a una venta, deuda u obligación del cliente.

Un cobro declarado no equivale necesariamente a acreditación bancaria
verificada.

---

## Importe aplicado

Porción del total comercial que un medio de pago cancela.

En `CommercePayment`, la verdad actual es `amount_minor`.

No debe confundirse con el dinero físico entregado por el cliente.

---

## Dinero entregado

En efectivo, importe físico que el cliente pone en manos del cajero antes de
recibir vuelto.

Puede ser mayor que el importe aplicado.

No es ingreso adicional, no aumenta el total vendido y no debe interpretarse
como efectivo retenido final.

---

## Vuelto

Importe que SRCM devuelve al cliente dentro del acto de cobro porque el dinero
entregado supera lo aplicado.

En P4E Foundation:

`vuelto = entregado - aplicado`

El vuelto no es descuento, devolución posventa, reembolso ni gasto.

---

## Obligación económica

Hecho que expresa que la organización debe un importe determinado a un
beneficiario por una causa identificable.

Una recepción física puede fundamentar o preparar una obligación, pero no es la
misma cosa y nunca constituye por sí sola un pago.

---

## Autorización de pago

Decisión atribuible que habilita pagar hechos exactos dentro de una política de
autoridad.

Autorizar no mueve dinero.

---

## Ejecución del pago

Acto por el cual un pago autorizado se hace efectivo desde una cuenta, caja u
otro origen.

Ejecutar es distinto de autorizar y de conciliar.

---

## Beneficiario del pago

Parte comercial o destinatario estructurado que recibe el valor desembolsado.

Puede coincidir con el proveedor de la orden o ser un transportista,
representante u otro autorizado.

---

## Evidencia de pago

Prueba estructurada o documental que respalda la ejecución de un pago.

No reemplaza la autorización, el movimiento de cuenta ni la conciliación.

---
## Términos que deben evitarse

Evitar campos o conceptos ambiguos como:

- Otros
- Varios
- Datos
- Observaciones generales
- Campo libre
- Producto duplicado por proveedor

Cuando sea necesario registrar información, deberá clasificarse como:

- nota interna;
- advertencia;
- evidencia;
- alias;
- procedimiento;
- aprendizaje;
- decisión;
- evento.
