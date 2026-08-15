# ADR 50 — Configurable CSV Column Mapping Foundation V1

Estado: Aceptada para P7.3

Checkpoint de partida:
`afee420bd9139589faa9dbdeaa6c53fa954ec21c`

## 1. Contexto

P7.1 definió el contrato CSV canónico y P7.2 agregó el commit explícito,
atómico e idempotente sobre `FinancialExternalMovement`.

El roadmap P7 exige además mapeo configurable para instituciones cuyos
extractos no usan las columnas canónicas de SRCM.

## 2. Decisión

P7.3 agrega mapeo por carga, sin crear un segundo importador financiero.

El mismo `FinancialStatementCsvPreviewer` normaliza:

- CSV canónico;
- CSV con nombres de columnas configurados;
- coma, punto y coma o tabulación como separador;
- punto o coma como separador decimal;
- formatos de fecha explícitos;
- zona horaria explícita para fechas sin offset;
- valores configurables para crédito y débito.

Después de normalizar, P7.2 sigue siendo el único camino de commit.

## 3. Campos canónicos

Siempre se requieren conceptualmente:

- fecha;
- dirección;
- bruto;
- neto.

Comisión y retención son opcionales y, si no están presentes en el extracto,
se normalizan a cero.

ID externo y referencia son opcionales.

La moneda continúa derivándose exclusivamente de la cuenta financiera
seleccionada.

## 4. Fail closed

El mapeo rechaza:

- nombres de columnas origen duplicados entre campos canónicos;
- crédito y débito con el mismo valor;
- columnas configuradas inexistentes;
- cabeceras CSV duplicadas;
- dirección no reconocida;
- fecha incompatible con el formato;
- importes con separador distinto al configurado;
- separadores de miles;
- matemática que no cumpla `gross = net + fee + withholding`.

P7.3 no intenta adivinar silenciosamente el formato de una institución.

## 5. Identidad e idempotencia

El `source_key` continúa siendo:

`csv:{file_sha256}:{line_number}`

No incorpora el mapeo deliberadamente.

Consecuencia: remapear el mismo archivo exacto hacia una verdad financiera
distinta entra en conflicto con el hecho ya registrado y falla cerrado en
lugar de crear un duplicado.

El mapeo posee además un SHA-256 determinista. Los nuevos borradores P7.3
guardan sólo ese fingerprint junto con las filas normalizadas.

## 6. Compatibilidad con borradores P7.2

P7.3 escribe borradores temporales `version=2`.

El commit continúa aceptando `version=1` de P7.2 durante su TTL y les asigna
el fingerprint del mapeo canónico.

Así una actualización no invalida una vista previa canónica todavía vigente.

## 7. Seguridad

Se conservan todas las reglas P7.2:

- tenant y usuario privados;
- permiso de revisión financiera;
- borrador cifrado;
- archivo crudo no persistido;
- cuenta activa no-cash;
- commit explícito;
- atomicidad;
- sin auto-conciliación;
- sin HTTP de proveedor;
- sin credenciales.

## 8. Alcance futuro

P7.3 no persiste perfiles de mapeo reutilizables y no incorpora XLSX.

Un corte posterior puede agregar perfiles versionados por organización y un
adaptador XLSX que produzca el mismo contrato normalizado antes de P7.2.
