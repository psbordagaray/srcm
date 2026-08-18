# ADR 95 — Fiscal Configuration Foundation V1

Estado: **PROPOSED para publicación por P10.1**
Fecha: **2026-08-18**
Bloque: **P10 — Fiscalidad argentina / ARCA**

## Contexto

El RECON P10 confirmó que `CommerceSale` ya es la verdad comercial confirmada e
inmutable, que su `sale_number` es una numeración interna por organización y que
no existe todavía ninguna entidad fiscal, punto de venta, secuencia ni adapter
ARCA.

El Roadmap fija una separación vinculante:

**Venta comercial ≠ comprobante fiscal ≠ autorización fiscal.**

Antes de preparar comprobantes o consumir Web Services oficiales es necesario
establecer quién factura y mediante qué puntos de venta, sin convertir esa
configuración en autorización ni evidencia fiscal.

## Decisión

P10.1 incorpora una capa `Fiscal` explícita con dos verdades:

1. `FiscalOrganizationProfile`: identidad fiscal argentina de la organización
   activa, separada de los datos comerciales básicos de `Organization`;
2. `FiscalPointOfSale`: identidad inmutable de un punto de venta por
   organización, ambiente y número, vinculado al perfil fiscal.

El perfil conserva razón social, CUIT validado, código vigente de condición
IVA, Ingresos Brutos, inicio de actividades y domicilio fiscal. Los códigos
externos se almacenan como referencias, no como enums permanentes, porque los
catálogos oficiales pueden evolucionar.

Los ambientes `homologation` y `production` son independientes. Los modos
inicialmente representables son `wsfe_v1` y `wsmtxca`; representar WSMTXCA no
equivale a activarlo ni a declararlo integración primaria.

La creación de puntos es idempotente sólo cuando ambiente, número, perfil y
modo coinciden. Su identidad no puede modificarse ni eliminarse físicamente;
sólo puede activarse o desactivarse. El CUIT del perfil queda congelado cuando
existe el primer punto, mientras los demás datos configurables pueden reflejar
cambios reales y serán snapshot en futuros documentos. Perfil y puntos respetan tenancy, permiso
Administrador, transacción, locks, auditoría y guards de BD.

## Fuera de alcance de P10.1

- documento o línea fiscal;
- correlatividad o asignación de número de comprobante;
- WSAA, certificados, claves o tickets;
- solicitudes WSFEv1/WSMTXCA;
- intentos, autorización, rechazo o recuperación ante timeout;
- CAE, CAEA, contingencia y QR;
- notas de crédito/débito;
- migración de la BD real por el runner;
- HTTP externo.

La existencia de un perfil o punto de venta **no** significa que una venta esté
facturada, presentada, autorizada ni exceptuada.

## Consecuencias

- `Organization.tax_id` continúa siendo un dato general y no reemplaza al
  perfil fiscal completo.
- `CommerceSale.sale_number` continúa siendo interno y nunca se usa como número
  fiscal autorizado.
- homologación puede configurarse sin habilitar producción;
- las credenciales y certificados se incorporarán mediante stores externos al
  repositorio en cortes posteriores;
- el siguiente corte deberá relevar y diseñar el documento fiscal canónico, sus
  snapshots y su estado derivado antes de integrar ARCA.

## Referencias oficiales verificadas

- Web Services de facturación electrónica:
  https://www.arca.gob.ar/fe/ayuda/webservice.asp
- Manual para desarrolladores WSFEv1:
  https://www.arca.gob.ar/ws/documentacion/manuales/manual-desarrollador-ARCA-COMPG.pdf
- WSAA:
  https://www.arca.gob.ar/ws/documentacion/wsaa.asp
- Certificados:
  https://www.arca.gob.ar/ws/documentacion/certificados.asp

## Próximo corte

**P10.2 — Fiscal Document Core RECON**: definir documento, snapshots de emisor,
receptor e importes, relación con venta/posventa, tipos de comprobante y estado
fiscal derivado, todavía sin inventar numeración ni autorización.
