export const SIGNED_GRANT_TYPE = 'SRCM-OFFLINE-GRANT';
export const SIGNED_GRANT_ALGORITHM = 'EdDSA';
export const SIGNED_GRANT_ISSUER = 'urn:srcm:server';
export const SIGNED_GRANT_AUDIENCE = 'urn:srcm:restricted-offline';
export const SIGNED_GRANT_VERSION = 1;
export const SIGNED_GRANT_MAX_BYTES = 4096;
export const SIGNED_GRANT_CLOCK_SKEW_SECONDS = 120;
export const SIGNED_GRANT_HARD_MAX_TTL_SECONDS = 28800;

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const UUID_V4_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const SHA256_RE = /^[0-9a-f]{64}$/;
const BASE64URL_RE = /^[A-Za-z0-9_-]+$/;
const KID_RE = /^[A-Za-z][A-Za-z0-9._-]{0,63}$/;
const POLICY_RE = /^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/;
const CAPABILITIES = new Set([
    'restricted_offline_read_model',
    'restricted_offline_replay',
]);
const CLAIM_KEYS = [
    'iss', 'aud', 'sub', 'jti', 'iat', 'nbf', 'exp', 'srcm_ver',
    'membership_id', 'organization_id', 'device_public_id',
    'binding_public_id', 'binding_exp', 'capabilities', 'policy_version',
    'credential_id', 'credential_fingerprint', 'cnf',
].sort();

export class RestrictedOfflineSignedGrantError extends Error {
    constructor(code) {
        super(code);
        this.name = 'RestrictedOfflineSignedGrantError';
        this.code = code;
    }
}

function fail(code) {
    throw new RestrictedOfflineSignedGrantError(code);
}

function plainObject(value, code) {
    if (
        value === null
        || typeof value !== 'object'
        || Array.isArray(value)
        || Object.getPrototypeOf(value) !== Object.prototype
    ) {
        fail(code);
    }

    return value;
}

function exactKeys(value, expected, code) {
    const keys = Object.keys(value).sort();

    if (
        keys.length !== expected.length
        || keys.some((key, index) => key !== expected[index])
    ) {
        fail(code);
    }
}

function decodeBase64Url(value, code) {
    if (typeof value !== 'string' || !BASE64URL_RE.test(value)) {
        fail(code);
    }

    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    let binary;

    try {
        binary = atob(value.replace(/-/g, '+').replace(/_/g, '/') + padding);
    } catch {
        fail(code);
    }

    return Uint8Array.from(binary, (char) => char.charCodeAt(0));
}

function decodeJson(segment, code) {
    const bytes = decodeBase64Url(segment, code);
    let text;

    try {
        text = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    } catch {
        fail(code);
    }

    let value;

    try {
        value = JSON.parse(text);
    } catch {
        fail(code);
    }

    return plainObject(value, code);
}

function validatePublicKeyJwk(jwk) {
    plainObject(jwk, 'keyring-jwk-not-object');
    exactKeys(jwk, ['alg', 'crv', 'kty', 'use', 'x'], 'keyring-jwk-shape');

    if (
        jwk.alg !== SIGNED_GRANT_ALGORITHM
        || jwk.crv !== 'Ed25519'
        || jwk.kty !== 'OKP'
        || jwk.use !== 'sig'
        || decodeBase64Url(jwk.x, 'keyring-jwk-x').length !== 32
    ) {
        fail('keyring-jwk-invalid');
    }
}

function validateConfirmationJwk(cnf) {
    plainObject(cnf, 'cnf-not-object');
    exactKeys(cnf, ['jwk'], 'cnf-shape');
    const jwk = plainObject(cnf.jwk, 'cnf-jwk-not-object');
    exactKeys(jwk, ['alg', 'crv', 'kty', 'x', 'y'], 'cnf-jwk-shape');

    if (
        jwk.alg !== 'ES256'
        || jwk.crv !== 'P-256'
        || jwk.kty !== 'EC'
        || decodeBase64Url(jwk.x, 'cnf-jwk-x').length !== 32
        || decodeBase64Url(jwk.y, 'cnf-jwk-y').length !== 32
    ) {
        fail('cnf-jwk-invalid');
    }
}

function positiveInteger(value, code) {
    if (!Number.isSafeInteger(value) || value < 1) {
        fail(code);
    }
}

function epochSeconds(value, code) {
    if (!Number.isSafeInteger(value) || value < 0) {
        fail(code);
    }

    return value;
}

export async function verifyRestrictedOfflineSignedGrant(
    compact,
    publicKeyring,
    {
        nowMs = Date.now(),
        cryptoImpl = globalThis.crypto,
    } = {},
) {
    if (
        typeof compact !== 'string'
        || compact.length < 1
        || new TextEncoder().encode(compact).length > SIGNED_GRANT_MAX_BYTES
    ) {
        fail('compact-size');
    }

    const segments = compact.split('.');

    if (segments.length !== 3 || segments.some((segment) => segment === '')) {
        fail('compact-structure');
    }

    const header = decodeJson(segments[0], 'header-invalid');
    exactKeys(header, ['alg', 'kid', 'typ'], 'header-shape');

    if (
        header.alg !== SIGNED_GRANT_ALGORITHM
        || header.typ !== SIGNED_GRANT_TYPE
        || typeof header.kid !== 'string'
        || !KID_RE.test(header.kid)
    ) {
        fail('header-values');
    }

    const keyring = plainObject(publicKeyring, 'keyring-not-object');
    const jwk = keyring[header.kid];

    if (!jwk) {
        fail('unknown-kid');
    }

    validatePublicKeyJwk(jwk);

    if (
        !cryptoImpl
        || !cryptoImpl.subtle
        || typeof cryptoImpl.subtle.importKey !== 'function'
        || typeof cryptoImpl.subtle.verify !== 'function'
    ) {
        fail('crypto-unavailable');
    }

    let verificationKey;

    try {
        verificationKey = await cryptoImpl.subtle.importKey(
            'jwk',
            jwk,
            { name: 'Ed25519' },
            false,
            ['verify'],
        );
    } catch {
        fail('key-import-failed');
    }

    const signature = decodeBase64Url(segments[2], 'signature-invalid');
    const signedBytes = new TextEncoder().encode(
        `${segments[0]}.${segments[1]}`,
    );

    let validSignature = false;

    try {
        validSignature = await cryptoImpl.subtle.verify(
            { name: 'Ed25519' },
            verificationKey,
            signature,
            signedBytes,
        );
    } catch {
        fail('signature-verification-failed');
    }

    if (!validSignature) {
        fail('signature-invalid');
    }

    const claims = decodeJson(segments[1], 'claims-invalid');
    exactKeys(claims, CLAIM_KEYS, 'claim-shape');

    if (
        claims.iss !== SIGNED_GRANT_ISSUER
        || !Array.isArray(claims.aud)
        || claims.aud.length !== 1
        || claims.aud[0] !== SIGNED_GRANT_AUDIENCE
        || claims.srcm_ver !== SIGNED_GRANT_VERSION
    ) {
        fail('issuer-audience-version');
    }

    if (
        typeof claims.sub !== 'string'
        || decodeBase64Url(claims.sub, 'subject-invalid').length !== 32
        || typeof claims.jti !== 'string'
        || !UUID_V4_RE.test(claims.jti)
        || typeof claims.device_public_id !== 'string'
        || !UUID_RE.test(claims.device_public_id)
        || typeof claims.binding_public_id !== 'string'
        || !UUID_RE.test(claims.binding_public_id)
    ) {
        fail('identity-scope-invalid');
    }

    positiveInteger(claims.membership_id, 'membership-id-invalid');
    positiveInteger(claims.organization_id, 'organization-id-invalid');

    const iat = epochSeconds(claims.iat, 'iat-invalid');
    const nbf = epochSeconds(claims.nbf, 'nbf-invalid');
    const exp = epochSeconds(claims.exp, 'exp-invalid');
    const bindingExp = epochSeconds(claims.binding_exp, 'binding-exp-invalid');

    if (
        nbf !== iat
        || exp <= iat
        || exp - iat > SIGNED_GRANT_HARD_MAX_TTL_SECONDS
        || exp > bindingExp
    ) {
        fail('time-contract-invalid');
    }

    const nowSeconds = Math.floor(nowMs / 1000);

    if (iat > nowSeconds + SIGNED_GRANT_CLOCK_SKEW_SECONDS) {
        fail('issued-in-future');
    }

    if (nowSeconds >= exp + SIGNED_GRANT_CLOCK_SKEW_SECONDS) {
        fail('expired');
    }

    if (
        !Array.isArray(claims.capabilities)
        || claims.capabilities.length < 1
        || new Set(claims.capabilities).size !== claims.capabilities.length
        || claims.capabilities.some(
            (capability) =>
                typeof capability !== 'string'
                || !CAPABILITIES.has(capability),
        )
    ) {
        fail('capabilities-invalid');
    }

    const sortedCapabilities = [...claims.capabilities].sort();
    if (
        sortedCapabilities.some(
            (capability, index) => capability !== claims.capabilities[index],
        )
    ) {
        fail('capabilities-not-canonical');
    }

    if (
        typeof claims.policy_version !== 'string'
        || !POLICY_RE.test(claims.policy_version)
        || typeof claims.credential_id !== 'string'
        || claims.credential_id.length < 1
        || claims.credential_id.length > 1024
        || !BASE64URL_RE.test(claims.credential_id)
        || typeof claims.credential_fingerprint !== 'string'
        || !SHA256_RE.test(claims.credential_fingerprint)
    ) {
        fail('policy-or-credential-invalid');
    }

    validateConfirmationJwk(claims.cnf);

    return Object.freeze({
        header: Object.freeze({ ...header }),
        claims: Object.freeze({ ...claims }),
    });
}
