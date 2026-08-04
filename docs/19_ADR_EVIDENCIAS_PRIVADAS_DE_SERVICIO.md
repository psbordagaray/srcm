# ADR 19 — Evidencias privadas e inmutables de servicio

**Estado:** Aceptada
**Fecha:** 2026-08-04
**Ámbito:** Reparaciones Core 11

## Contexto

El expediente de una reparación necesita conservar fotografías y documentos que
prueben el estado físico, los accesorios, el diagnóstico, el trabajo, los
repuestos, la custodia, la calidad, la entrega, las cancelaciones y las
garantías.

Una ruta pública, un nombre predecible o una relación polimórfica sin integridad
permitirían exposición entre organizaciones, sustitución de archivos o vínculos
ambiguos. La base de datos tampoco debe afirmar que existe una evidencia cuando
el archivo privado falta o no coincide con su hash.

## Decisión

Se incorpora `ServiceEvidence` como hecho privado, inmutable y perteneciente a
una única organización y orden de servicio.

Cada evidencia posee:

- UUID público no secuencial;
- contexto explícito;
- referencia relacional específica cuando el contexto la requiere;
- nombre original sólo como metadato;
- nombre interno aleatorio;
- disco y ruta privados;
- MIME detectado por contenido;
- tamaño y hash SHA-256;
- fecha de captura;
- actor, idempotencia y fingerprint.

Los archivos se almacenan exclusivamente en el disco `local`, cuyo directorio
es `storage/app/private`. No se usan `public/storage`, nombres originales como
ruta ni URLs públicas permanentes.

## Contextos admitidos

- expediente general;
- ingreso;
- diagnóstico;
- trabajo;
- repuesto;
- custodia;
- control de calidad;
- entrega;
- solicitud, resolución y devolución de cancelación;
- reclamo, resolución y devolución de garantía.

La tabla utiliza columnas de referencia explícitas. No se adopta un par débil
`type/id`. Los triggers verifican que la referencia, la organización y la orden
formen el mismo expediente.

## Registro compensable

El manager realiza:

1. autorización tenant y rol;
2. inspección del archivo fuente;
3. detección MIME, tamaño y SHA-256;
4. escritura temporal privada;
5. verificación del temporal;
6. validación transaccional de orden y referencia;
7. movimiento a ruta privada definitiva;
8. creación del hecho inmutable;
9. verificación final.

Ante una excepción se elimina todo archivo temporal o definitivo creado por el
intento. La reejecución con la misma clave devuelve el hecho existente sólo si
el fingerprint y el archivo almacenado coinciden.

## Seguridad

Se rechazan:

- viewers como cargadores;
- fuentes inexistentes, ilegibles o simbólicas;
- archivos vacíos o superiores al límite configurado;
- MIME no permitido;
- nombres con rutas o caracteres de control;
- fechas futuras;
- referencias de otra orden u organización;
- reutilización contradictoria de idempotencia;
- rutas absolutas, traversal o rutas fuera del expediente;
- actualización y borrado por Eloquent o SQL directo.

El modelo oculta disco y ruta en serializaciones para reducir filtraciones
accidentales.

## Integridad de base de datos

SQLite y MySQL/MariaDB reciben guardas equivalentes para:

- contexto y referencia única;
- pertenencia tenant y expediente;
- membresía activa del actor al insertar;
- disco local privado y ruta segura;
- MIME y extensión interna coherentes;
- hashes y fingerprints hexadecimales;
- inmutabilidad absoluta después de insertar.

## Permisos

- Administrador y Operador pueden cargar evidencias.
- Administrador, Operador y Consulta pueden ver y verificar integridad.
- Nadie elimina ni reemplaza una evidencia confirmada mediante el flujo
  ordinario.

## Fuera de alcance

Reparaciones Core 11 no incorpora todavía:

- formularios HTTP de carga;
- visualización o descarga autenticada;
- miniaturas;
- firma digital;
- antivirus externo;
- almacenamiento S3;
- retención legal o purga administrativa excepcional.

La superficie HTTP/UI y la descarga privada quedan para Reparaciones Core 12.
