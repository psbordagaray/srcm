# ADR 129 — ARCA WSFE Authorization Runtime Binding V1

Estado: aceptada para el corte posterior a WSFE Remote Sequence Transport Boundary V1.

## Problema

SRCM ya publica, por separado, todas las fronteras necesarias para una autorización
WSFE localmente componible:

- `WsaaAccessTicketProvider`;
- wire `FECompUltimoAutorizado`;
- wire `FECAESolicitar`;
- `FiscalRemoteSequenceAuthority`;
- `FiscalAuthorizationTransport`;
- `WsfeFecaeRequestComposerContract`;
- normalización y convergencia provider.

Sin embargo, los dos contratos runtime centrales permanecían deliberadamente sin
binding. El sistema tampoco tenía una frontera explícita que resolviera el scope
`organization + environment + service + issuer_cuit` necesario para pedir un TA,
sin inferir identidad fiscal desde la venta o desde `FiscalOrganizationProfile`.

## Decisión

Se introduce `FiscalAuthorizationRuntimeScopeStore` y su implementación
`EnvironmentFiscalAuthorizationRuntimeScopeStore`.

La fuente de scope es la misma configuración tenant-scoped ya utilizada por WSAA:

`SRCM_ARCA_WSAA_CREDENTIAL_REFERENCES_JSON`

El runtime extrae únicamente:

- organización;
- ambiente;
- `service`;
- `issuer_cuit`.

No abre certificado, clave privada ni passphrase. La dereferencia y validación del
material permanece exclusivamente en la cadena WSAA existente.

No se usa `FiscalOrganizationProfile.tax_id` para construir `issuer_cuit` y no se
infiere identidad desde venta, cliente, punto de venta u otros hechos comerciales.

## Remote sequence runtime

`WsaaBackedFiscalRemoteSequenceAuthority` implementa
`FiscalRemoteSequenceAuthority` mediante:

1. `ArcaHomologationReadiness::assertReady()`;
2. resolución explícita del scope;
3. obtención de TA mediante `WsaaAccessTicketProvider`;
4. construcción efímera de `WsfeCompUltimoAutorizadoSoap11Call`;
5. intercambio por `WsfeCompUltimoAutorizadoSoapTransport`.

No existe fallback a numeración local ni retry automático.

## Authorization runtime

`WsaaBackedFiscalAuthorizationTransport` implementa
`FiscalAuthorizationTransport` mediante:

1. readiness;
2. scope explícito;
3. TA;
4. `WsfeFecaeSoap11Call` efímera;
5. wire `FECAESolicitar`;
6. `WsfeFecaeProviderResponseNormalizerContract`;
7. `WsfeFecaeProviderResultConvergenceContract`.

El resultado vuelve al contrato neutral `FiscalAuthorizationTransportResult`.
Token y Sign no se agregan al transport request neutral ni a persistencia fiscal.

## Container

`AppServiceProvider` enlaza:

- `FiscalAuthorizationRuntimeScopeStore`;
- `FiscalAuthorizationCredentialStore`;
- `FiscalRemoteSequenceAuthority`;
- `FiscalAuthorizationTransport`;
- `WsfeFecaeRequestComposerContract`;
- normalizer y convergence contracts.

Con ello `ArcaFiscalAuthorizationAdapter` queda resoluble sin modificar su diseño.

## Gate externo

La homologación real sigue deshabilitada por configuración en el entorno actual.
`ArcaHomologationReadiness` se ejecuta antes de solicitar TA o tocar wire transport.
Producción permanece fail-closed.

Las pruebas de este corte usan únicamente:

- CUIT sintético;
- TA sintético;
- wire transports en memoria;
- respuestas provider sintéticas.

No se genera ni dereferencia certificado/clave real y no se ejecuta CMS,
`LoginCms`, `FECompUltimoAutorizado`, `FECAESolicitar`, DNS o HTTP ARCA real.

## Concurrencia e incertidumbre

Este binding no inventa reserva local entre `FECompUltimoAutorizado` y
`FECAESolicitar`. Tampoco agrega retry automático. La autoridad remota continúa
siendo ARCA y los estados inciertos deberán tratarse mediante evidencia/reconsulta
en un corte posterior si la homologación real lo exige.

## Fuera de alcance

- WSASS y enrolamiento real;
- CUIT real en este corte;
- certificado o clave real;
- homologación externa real;
- producción;
- retry automático;
- reserva distribuida de numeración;
- cambios de schema o migraciones;
- cambios en modelos comerciales/fiscales;
- FCE específica.
