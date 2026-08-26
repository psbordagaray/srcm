# ADR 140 — Production Manual Release Protected Main Dispatch Identity V1

Estado: **ACEPTADA**
Fecha: **2026-08-26**
Alcance: P11 — endurecimiento de identidad de release manual previo a autorización del bootstrap inicial

## Contexto

SRCM/Straleon ya cerró la identidad estable del nodo de producción mediante Tailscale,
el Environment protegido de GitHub y un Safe Smoke real read-only. El RECON posterior
confirmó que la instalación inicial puede permanecer estrictamente inactiva: sin
migraciones, sin `current`, sin servicios y sin tráfico público.

Durante la preparación del corte que iba a habilitar los switches fuente del bootstrap
se detectó una frontera adicional de gobernanza. GitHub `workflow_dispatch` permite
seleccionar una rama o tag como `ref`, y los workflows de producción aceptaban además
un `release_sha` de entrada. Sin una vinculación ejecutable entre el ref despachado y
ese SHA, un commit de feature con gates fuente habilitados podría llegar a ser objetivo
de un workflow manual antes de atravesar la protección y revisión de `main`.

Para SRCM/Straleon la protección de la rama principal no puede ser sólo una convención
operativa. Seguridad, privacidad, integridad, recuperabilidad y trazabilidad son
requisitos arquitectónicos de primer nivel y deben expresarse como invariantes
fail-closed cuando exista una frontera de producción.

## Decisión

Los dos workflows manuales de producción —bootstrap inicial y deploy normal— deben
probar antes de cualquier autorización remota que:

- el evento fue despachado sobre un `branch`;
- `GITHUB_REF_NAME` es exactamente `main`;
- `GITHUB_REF_PROTECTED` es `true`;
- el `release_sha` solicitado coincide exactamente con `GITHUB_SHA` del dispatch.

El bootstrap verifica esta identidad tanto en el job de construcción como nuevamente
en el job protegido de instalación. El deploy normal la verifica en su job protegido.

`ReleasePreflightInspector` incorpora gates estáticos permanentes para ambos contratos
y la suite de release comprueba las cantidades exactas de guards, evitando regresiones
silenciosas.

## Frontera de este corte

Este hardening **no autoriza producción ni bootstrap**. Permanecen sin cambios:

- `production_release_enabled=false`;
- `initial_application_release_bootstrap_enabled=false`;
- `external_gates.production_environment_secrets_and_approvals=false`.

No se ejecuta ningún workflow, SSH, bootstrap, deploy, migración, cambio de `current`,
inicio de servicios ni mutación de la BD productiva.

La autorización del bootstrap inicial se realizará solamente en un corte fuente
posterior, después de que este hardening haya pasado CI, revisión, merge a `main` y
realineación de la feature. Ese corte posterior podrá habilitar exclusivamente el
bootstrap inactivo y el gate fuente del Environment, manteniendo el release normal
bloqueado.

## Consecuencias

Un SHA de feature, tag u otra rama deja de ser elegible para las operaciones manuales
de producción aun si en el futuro contiene switches de autorización habilitados. La
identidad operativa del release queda ligada al commit exacto del `main` protegido que
recibió el dispatch.

Los documentos maestros se actualizarán después del cierre GREEN, publicación y
gobernanza de este hardening, siguiendo la política documental del proyecto.
