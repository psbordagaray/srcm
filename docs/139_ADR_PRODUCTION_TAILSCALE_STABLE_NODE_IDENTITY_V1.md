# ADR 139 — Production Tailscale Stable Node Identity V1

**Status:** Accepted
**Scope:** P11 production CI/CD stable node identity
**Date:** 2026-08-26

## Context

ADR 138 established a private Tailscale transport and intentionally pinned the then-observed Tailscale IPv4 while the transport boundary was being proven. That was appropriate for the foundation stage, but an IP address is routing state rather than durable production identity. Tailscale machine IPs are normally stable, yet they can legitimately change after node re-registration, administrative reassignment, state loss, or other lifecycle operations.

SRCM must not require a source change or GitHub control-plane edit merely because the production node receives a different Tailscale IP.

## Decision

The canonical production transport identity is the logical Tailscale machine name `straleon-prod-01`, not any `100.x.y.z` address.

Before SSH or SCP, every protected production remote-I/O workflow must:

1. establish the pinned Tailscale GitHub Actions WIF transport as `tag:straleon-ci-deploy`;
2. require `SRCM_DEPLOY_HOST=straleon-prod-01`;
3. resolve the current IPv4 at runtime with `tailscale ip --4 "$DEPLOY_HOST"` and require exactly one private Tailscale IPv4;
4. call `tailscale whois --json` for the resolved address;
5. require the whois machine name to resolve to the `straleon-prod-01` machine label;
6. require `tag:straleon-prod` in the whois node tags and require the resolved address to belong to that node;
7. prove the route to the resolved address uses `tailscale0`;
8. reconstruct runner-local `known_hosts` binding the resolved current IP to the already protected SSH host-key material, then verify the pinned ED25519 host-key fingerprint;
9. verify the dedicated deploy private-key fingerprint;
10. use the resolved runtime IP for OpenSSH while asserting the remote OS hostname is `straleon-prod-01` before the first mutating remote command.

The currently observed private IP is not stored in the new workflow authorization contract. ADR 138 remains unchanged as historical evidence of the earlier foundation.

## Fail-closed staging

This source cut does not authorize bootstrap or normal release. `production_release_enabled`, `initial_application_release_bootstrap_enabled`, and `external_gates.production_environment_secrets_and_approvals` remain false. No workflow is dispatched, no GitHub Environment variable is changed, and no remote I/O occurs.

After normal branch governance publishes this source foundation, a separate control-plane cut must migrate `SRCM_DEPLOY_HOST` from the temporary IP value to `straleon-prod-01`. Only after that change may the updated Safe Smoke run read-only to prove name resolution, whois identity/tag, route, pinned SSH identity, and remote hostname together.

## Verification contract

`ReleasePreflightInspector` and `ProductionDeploymentFoundationTest` fail if production remote workflows reintroduce either a public or private literal target, lose the logical-name guard, runtime resolution, whois name/tag proof, Tailscale route proof, runtime known-host rebinding, or either SSH fingerprint pin. The Safe Smoke test separately requires the same stable identity chain and forbids a literal target IP.

## Consequences

A provider public-IP change is irrelevant to the CI/CD transport, and a future legitimate Tailscale private-IP change becomes runtime routing state rather than a source/configuration incident. A change in logical node identity, required tag, SSH host key, deploy key, or remote hostname remains a fail-closed security event that requires explicit reconciliation.
