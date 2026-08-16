# ADR 61 — Mercado Pago Point Refund Controlled Activation V1

Estado: Aceptada para P8.4.3.4

Checkpoint de partida:
`a24e1532be7018d59e5ffeb87cf9febb09ca214c`

## 1. Contexto

P8.4.3.3 validó el contrato de Refund de Mercado Pago Point mediante harness
sin HTTP real y creó un snapshot append-only compatible.

Ese corte dejó intencionalmente:

- adapter no registrado en el container productivo;
- conexiones existentes todavía ligadas al snapshot histórico;
- Refund health sin activar;
- automatización Refund bloqueada.

P8.4.3.4 prepara el cutover sin ejecutar dinero.

## 2. Wiring del adapter

El container puede resolver `FinancialProviderRefundAdapterRegistry` con
`MercadoPagoPointRefundAdapter`.

Esto NO habilita un reembolso por sí solo.

La ejecución continúa protegida por `FinancialProviderAutomationGate`.

Una conexión vinculada al snapshot histórico sigue viendo:

`Refund = Unknown`

y por lo tanto permanece bloqueada.

## 3. Preflight antes de migrar el binding

Se agrega `MercadoPagoRefundReadinessHealthProbe`.

Es estrictamente read-only: reutiliza el probe autenticado de identidad
`GET /users/me`.

Si autenticación e identidad son correctas, el resultado Refund NO es Healthy.
Se registra conceptualmente como:

- status: `Degraded`
- diagnostic: `refund_smoke_required`

La razón es deliberada: un GET de identidad no demuestra que un endpoint de
refund pueda ejecutar dinero correctamente.

Si las credenciales, identidad o transporte fallan, el preflight falla cerrado.

El binding NO se migra cuando ese preflight no alcanza el estado
`refund_smoke_required`.

## 4. Activación explícita

`MercadoPagoPointRefundActivationManager::prepare()` es Admin-only y
tenant-scoped.

Cuando el preflight seguro es satisfactorio:

1. registra idempotentemente el snapshot P8.4.3.3;
2. migra el binding mediante el manager append-only existente;
3. registra el resultado del readiness probe contra el NUEVO binding;
4. evalúa el gate Refund.

El resultado esperado del corte es:

`health_degraded`

Por tanto, el adapter está disponible pero el dinero sigue bloqueado.

## 5. Idempotencia

Si la conexión ya está en el snapshot Refund y ya posee health Refund para ese
binding, `prepare()` reutiliza binding y health.

No repite el probe ni crea otra migración.

Esto evita que una ejecución repetida degrade o sobrescriba evidencia posterior.

## 6. Conservación de health por binding

Los health checks continúan ligados a un binding específico.

Migrar a un snapshot nuevo no reutiliza silenciosamente health histórico del
binding anterior.

Esto puede bloquear otras automatizaciones hasta que sus capacidades se
revaliden para el nuevo binding; es una propiedad fail-closed, no un error.

P8.4.3.4 no expone un botón ni ruta de activación productiva.

## 7. Seguridad

P8.4.3.4 no:

- ejecuta `POST /refund`;
- crea dispatch/evidence de posventa;
- crea `FinancialExternalMovement`;
- usa dinero real;
- persiste Access Tokens o payloads;
- marca Refund como Healthy;
- modifica `CommercePayment`;
- hace migraciones destructivas.

## 8. Próximo corte

P8.4.3.5 deberá decidir y ejecutar un smoke externo controlado.

Sólo una evidencia que realmente pruebe el contrato de refund podrá producir
un health `Refund = Healthy` para el nuevo binding.

Hasta entonces el gate sigue denegando ejecución monetaria.
