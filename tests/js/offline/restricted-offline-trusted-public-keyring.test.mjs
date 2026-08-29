import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    TRUST_MAX_VALIDITY_SECONDS,
    canonicalizeTrustedPublicKeyring,
    decideTrustedPublicKeyringUpdate,
    refreshTrustedPublicKeyring,
    refreshTrustedPublicKeyringForIssuance,
    trustedPublicKeyringFingerprint,
    validateTrustedPublicKeyringBundle,
} from '../../../resources/js/offline/restrictedOfflineTrustedPublicKeyringStore.js';
import {
    purgeRestrictedOfflineAuthorityStores,
} from '../../../resources/js/offline/restrictedOfflineAuthorityLifecycle.js';

function base64Url(byte) {
    return Buffer.alloc(32, byte).toString('base64url');
}

function key(byte) {
    return {
        kty: 'OKP',
        crv: 'Ed25519',
        x: base64Url(byte),
        alg: 'EdDSA',
        use: 'sig',
    };
}

async function bundle({ version = 7, nowMs = Date.parse('2026-08-29T14:00:00Z') } = {}) {
    const keys = {
        'sg-ed25519-b': key(0x22),
        'sg-ed25519-a': key(0x11),
    };
    const fingerprint = await trustedPublicKeyringFingerprint(
        version,
        keys,
    );

    return {
        bundle_version: 1,
        scope: {
            binding_public_id:
                '11111111-1111-4111-8111-111111111111',
            device_public_id:
                '22222222-2222-4222-8222-222222222222',
        },
        server_issued_at: new Date(nowMs).toISOString(),
        expires_at: new Date(
            nowMs + TRUST_MAX_VALIDITY_SECONDS * 1000,
        ).toISOString(),
        keyring_version: version,
        keyring_fingerprint: fingerprint,
        keys,
    };
}

test('canonical public keyring fingerprint matches the PHP cross-runtime vector', async () => {
    const value = await bundle();
    assert.equal(
        value.keyring_fingerprint,
        '9707aadc29d198db54a9d32607430102f6bbcd6115d460f5ab5a8cc1cbadc121',
    );

    assert.deepEqual(
        Object.keys(canonicalizeTrustedPublicKeyring(value.keys)),
        ['sg-ed25519-a', 'sg-ed25519-b'],
    );
});

test('trusted bundle validates exact public-only schema and maximum validity', async () => {
    const nowMs = Date.parse('2026-08-29T14:00:00Z');
    const value = await bundle({ nowMs });
    const validated = await validateTrustedPublicKeyringBundle(
        value,
        { nowMs },
    );

    assert.equal(validated.bundle.keyring_version, 7);
    assert.equal(
        validated.expiresAtMs - validated.issuedAtMs,
        TRUST_MAX_VALIDITY_SECONDS * 1000,
    );
});

test('invalid JWK shape and fingerprint fail closed', async () => {
    const nowMs = Date.parse('2026-08-29T14:00:00Z');
    const value = await bundle({ nowMs });
    value.keys['sg-ed25519-a'].d = 'private-material-forbidden';

    await assert.rejects(
        validateTrustedPublicKeyringBundle(value, { nowMs }),
        (error) => error.code === 'keyring-jwk-shape',
    );
});

test('monotonic keyring policy rejects rollback and version reuse', async () => {
    const nowMs = Date.parse('2026-08-29T14:00:00Z');
    const current = await bundle({ version: 8, nowMs });
    const lower = await bundle({ version: 7, nowMs });
    const higher = await bundle({ version: 9, nowMs });
    const reused = await bundle({ version: 8, nowMs });
    reused.keyring_fingerprint = 'f'.repeat(64);

    assert.equal(
        decideTrustedPublicKeyringUpdate(current, lower, nowMs),
        'reject-rollback',
    );
    assert.equal(
        decideTrustedPublicKeyringUpdate(current, current, nowMs),
        'refresh',
    );
    assert.equal(
        decideTrustedPublicKeyringUpdate(current, reused, nowMs),
        'reject-version-reuse',
    );
    assert.equal(
        decideTrustedPublicKeyringUpdate(current, higher, nowMs),
        'replace',
    );
});

test('network and 5xx preserve last valid trust while 403 purges authority', async () => {
    const now = new Date('2026-08-29T14:00:00Z');
    const stored = { bundle: await bundle({ nowMs: now.getTime() }) };
    const readImpl = async () => stored;
    let purged = 0;
    const purgeImpl = async () => { purged += 1; return true; };

    const network = await refreshTrustedPublicKeyring({
        now,
        fetchImpl: async () => { throw new Error('offline'); },
        readImpl,
        purgeImpl,
    });
    assert.equal(network.status, 'network-unavailable');
    assert.equal(network.trusted, stored);

    const server = await refreshTrustedPublicKeyring({
        now,
        fetchImpl: async () => ({ status: 503, ok: false, type: 'basic' }),
        readImpl,
        purgeImpl,
    });
    assert.equal(server.status, 'server-unavailable');
    assert.equal(server.trusted, stored);

    const forbidden = await refreshTrustedPublicKeyring({
        now,
        fetchImpl: async () => ({ status: 403, ok: false, type: 'basic' }),
        readImpl,
        purgeImpl,
    });
    assert.equal(forbidden.status, 'authority-unavailable');
    assert.equal(forbidden.trusted, null);
    assert.equal(purged, 1);
});

test('pre-issuance refresh refuses stale network fallback', async () => {
    const now = new Date('2026-08-29T14:00:00Z');

    await assert.rejects(
        refreshTrustedPublicKeyringForIssuance({
            now,
            fetchImpl: async () => { throw new Error('offline'); },
            readImpl: async () => ({
                bundle: await bundle({ nowMs: now.getTime() }),
            }),
        }),
        (error) => error.code === 'pre-issuance-trust-refresh-required',
    );
});

test('combined authority purge waits for snapshot and trust stores', async () => {
    const calls = [];

    await purgeRestrictedOfflineAuthorityStores({
        indexedDbFactory: {},
        snapshotPurge: async () => { calls.push('snapshot'); },
        trustPurge: async () => { calls.push('trust'); },
    });

    assert.deepEqual(calls.sort(), ['snapshot', 'trust']);
});
