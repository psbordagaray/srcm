# ADR 145 — Recovery Anchor Protocol V1

Estado: **ACEPTADA**
Fecha: **2026-08-27**
Alcance: P11 y cortes posteriores — recuperabilidad obligatoria antes de mutaciones sensibles

## Objetivo

Cada operacion sensible debe partir de un punto de recuperacion demostrable, no solamente de la expectativa de que existe un backup. El Recovery Anchor registra identidad Git exacta, integridad local y, cuando corresponde, estado remoto/productivo y snapshot de datos verificado.

## Contrato minimo

Antes de una mutacion sensible se deben fijar por evidencia SHA-256 el RESULT anterior, `HEAD`, refs relevantes, tree/commit, worktree/staging, integridad `.env`, integridad canonica de BD y los gates remotos que correspondan. Si una operacion puede modificar BD, debe existir un snapshot verificado previo. Si puede modificar una release productiva, la release inmutable anterior debe preservarse.

## Clasificacion de fallos

- **Pre-mutacion:** un defecto de runner puede repararse y el estado exacto puede restaurarse automaticamente al anchor cuando aun no existe commit/mutacion externa.
- **Post Git/GitHub:** no se repite ni se revierte a ciegas; primero se reconcilia el estado nuevo.
- **Post BD:** rollback de codigo nunca implica rollback de datos; se usa el snapshot/procedimiento versionado apropiado.
- **Post deploy:** se conserva la release anterior y cualquier repoint debe demostrar compatibilidad con el estado de datos.

## Consecuencia

Los runners futuros deben declarar el Recovery Anchor antes de mutar y distinguir explicitamente si el fallo ocurrio antes o despues de la primera mutacion. Este protocolo reduce ejecuciones ciegas y convierte los errores de tooling en estados recuperables y auditables.
