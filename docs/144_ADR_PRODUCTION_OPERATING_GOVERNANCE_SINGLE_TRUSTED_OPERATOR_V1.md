# ADR 144 — Production Operating Governance: Single Trusted Operator V1

Estado: **ACEPTADA**
Fecha: **2026-08-27**
Alcance: P11 — modelo transitorio de gobernanza operativa previo al onboarding de un segundo operador humano independiente

## Evidencia

PERHR4 cerro GREEN con SHA-256 `69acd943afa581acf539630ce052c1f00dfc52d5558a1036ccf545770faa981f` y demostro que el unico reviewer requerido del Environment `production` es el propietario del repositorio, sin ningun colaborador `User` distinto tecnicamente evidenciado. `HEAD`, `main` y `feature/core-entity` permanecen alineados en `f68049cadbd3fe805f0963376adc595cf5f2294c`, mientras bootstrap, gate externo y release normal siguen revocados.

## Decision

Se versiona `release.deployment.operating_governance` con `current_mode=single_trusted_operator` y `second_operator_status=planned_not_yet_onboarded`. Este modo describe honestamente la etapa actual de STRALEON; no fabrica una segunda identidad ni convierte una segunda cuenta del mismo operador en control independiente.

La ausencia temporal del segundo operador no limita el desarrollo, pruebas, CI, arquitectura ni potencial funcional de STRALEON Full. Sin embargo, la release normal de produccion permanece fail-closed. El modo actual no puede habilitar `production_release_enabled=true`. Antes de una release normal deberan coexistir `prevent_self_review=true`, `current_mode=independent_second_operator` y `second_operator_status=onboarded_verified`, junto con evidencia live concordante.

## Evolucion

Cuando exista una segunda persona humana de confianza, su onboarding sera un corte separado: identidad GitHub propia, minimo privilegio, elegibilidad tecnica, designacion humana explicita y prueba de aprobacion. El rol societario, funcional o de desarrollo de esa persona no se infiere de su rol de reviewer de produccion.
