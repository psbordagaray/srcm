import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { test } from 'node:test';

import {
    verifyRestrictedOfflineSignedGrant,
} from '../../../resources/js/offline/restrictedOfflineSignedGrantVerifier.js';

const crypto = webcrypto;

function base64Url(bytes) {
    return Buffer.from(bytes).toString('base64url');
}

function jsonSegment(value) {
    return Buffer.from(JSON.stringify(value), 'utf8').toString('base64url');
}

async function fixture(overrides = {}) {
    const keyPair = await crypto.subtle.generateKey(
        { name: 'Ed25519' },
        true,
        ['sign', 'verify'],
    );
    const exported = await crypto.subtle.exportKey('jwk', keyPair.publicKey);
    const publicJwk = {
        alg: 'EdDSA',
        crv: 'Ed25519',
        kty: 'OKP',
        use: 'sig',
        x: exported.x,
    };
    const header = {
        alg: 'EdDSA',
        kid: 'offline-k1',
        typ: 'SRCM-OFFLINE-GRANT',
        ...(overrides.header ?? {}),
    };
    const claims = {
        iss: 'urn:srcm:server',
        aud: ['urn:srcm:restricted-offline'],
        sub: base64Url(new Uint8Array(32).fill(7)),
        jti: '11111111-1111-4111-8111-111111111111',
        iat: 1787972400,
        nbf: 1787972400,
        exp: 1787986800,
        srcm_ver: 1,
        membership_id: 17,
        organization_id: 9,
        device_public_id: '22222222-2222-4222-8222-222222222222',
        binding_public_id: '33333333-3333-4333-8333-333333333333',
        binding_exp: 1787990400,
        capabilities: [
            'restricted_offline_read_model',
            'restricted_offline_replay',
        ],
        policy_version: 'offline-v1',
        credential_id: base64Url(Buffer.from('credential-1')),
        credential_fingerprint: 'a'.repeat(64),
        cnf: {
            jwk: {
                alg: 'ES256',
                crv: 'P-256',
                kty: 'EC',
                x: base64Url(new Uint8Array(32).fill(17)),
                y: base64Url(new Uint8Array(32).fill(34)),
            },
        },
        ...(overrides.claims ?? {}),
    };
    const encodedHeader = jsonSegment(header);
    const encodedClaims = jsonSegment(claims);
    const signingInput = new TextEncoder().encode(
        `${encodedHeader}.${encodedClaims}`,
    );
    const signature = await crypto.subtle.sign(
        { name: 'Ed25519' },
        keyPair.privateKey,
        signingInput,
    );

    return {
        compact: `${encodedHeader}.${encodedClaims}.${base64Url(signature)}`,
        publicKeyring: { 'offline-k1': publicJwk },
    };
}

test('browser verifies exact Ed25519 grant from static trusted keyring', async () => {
    const value = await fixture();
    const verified = await verifyRestrictedOfflineSignedGrant(
        value.compact,
        value.publicKeyring,
        {
            nowMs: 1787974200 * 1000,
            cryptoImpl: crypto,
        },
    );

    assert.equal(verified.header.kid, 'offline-k1');
    assert.equal(verified.claims.organization_id, 9);
    assert.deepEqual(verified.claims.capabilities, [
        'restricted_offline_read_model',
        'restricted_offline_replay',
    ]);
});

test('browser rejects token supplied trust anchor headers', async () => {
    const value = await fixture({ header: { jwk: { kty: 'OKP' } } });

    await assert.rejects(
        verifyRestrictedOfflineSignedGrant(
            value.compact,
            value.publicKeyring,
            {
                nowMs: 1787974200 * 1000,
                cryptoImpl: crypto,
            },
        ),
        (error) => error.code === 'header-shape',
    );
});

test('browser rejects unknown kid and never trusts token material', async () => {
    const value = await fixture();

    await assert.rejects(
        verifyRestrictedOfflineSignedGrant(
            value.compact,
            { 'offline-k2': value.publicKeyring['offline-k1'] },
            {
                nowMs: 1787974200 * 1000,
                cryptoImpl: crypto,
            },
        ),
        (error) => error.code === 'unknown-kid',
    );
});

test('browser rejects payload tampering', async () => {
    const value = await fixture();
    const [header, payload, signature] = value.compact.split('.');
    const claims = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
    claims.organization_id = 999;
    const tampered = `${header}.${jsonSegment(claims)}.${signature}`;

    await assert.rejects(
        verifyRestrictedOfflineSignedGrant(
            tampered,
            value.publicKeyring,
            {
                nowMs: 1787974200 * 1000,
                cryptoImpl: crypto,
            },
        ),
        (error) => error.code === 'signature-invalid',
    );
});

test('browser rejects expired grant after the 120 second skew only', async () => {
    const value = await fixture();

    await assert.rejects(
        verifyRestrictedOfflineSignedGrant(
            value.compact,
            value.publicKeyring,
            {
                nowMs: 1787986920 * 1000,
                cryptoImpl: crypto,
            },
        ),
        (error) => error.code === 'expired',
    );
});
