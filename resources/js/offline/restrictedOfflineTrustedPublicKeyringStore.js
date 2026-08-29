import {
    verifyRestrictedOfflineSignedGrant,
} from './restrictedOfflineSignedGrantVerifier.js';

export const TRUST_STORAGE_SCHEMA_VERSION = 1;
export const TRUST_BUNDLE_VERSION = 1;
export const TRUST_DB_NAME = 'srcm-restricted-offline-trust-v1';
export const TRUST_DB_VERSION = 1;
export const TRUST_STORE_NAME = 'trusted-public-keyrings';
export const TRUST_CURRENT_RECORD_KEY = 'current';
export const TRUST_ENDPOINT =
    '/runtime/restricted-offline/trusted-public-keyring';
export const TRUST_MAX_VALIDITY_SECONDS = 28920;
export const TRUST_CLOCK_SKEW_SECONDS = 120;

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const SHA256_RE = /^[0-9a-f]{64}$/;
const BASE64URL_RE = /^[A-Za-z0-9_-]+$/;
const KID_RE = /^[A-Za-z][A-Za-z0-9._-]{0,63}$/;
const TOP_LEVEL_KEYS = [
    'bundle_version',
    'expires_at',
    'keyring_fingerprint',
    'keyring_version',
    'keys',
    'scope',
    'server_issued_at',
].sort();
const SCOPE_KEYS = [
    'binding_public_id',
    'device_public_id',
].sort();
const JWK_KEYS = ['alg', 'crv', 'kty', 'use', 'x'];

export class TrustedPublicKeyringError extends Error {
    constructor(code, cause = null) {
        super(code);
        this.name = 'TrustedPublicKeyringError';
        this.code = code;
        this.cause = cause;
    }
}

function fail(code) {
    throw new TrustedPublicKeyringError(code);
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

export function canonicalizeTrustedPublicKeyring(value) {
    if (Array.isArray(value)) {
        return value.map((item) => canonicalizeTrustedPublicKeyring(item));
    }

    if (
        value === null
        || typeof value !== 'object'
        || Object.getPrototypeOf(value) !== Object.prototype
    ) {
        return value;
    }

    return Object.fromEntries(
        Object.keys(value)
            .sort()
            .map((key) => [
                key,
                canonicalizeTrustedPublicKeyring(value[key]),
            ]),
    );
}

function requireCrypto(cryptoImpl) {
    if (
        !cryptoImpl
        || !cryptoImpl.subtle
        || typeof cryptoImpl.subtle.digest !== 'function'
    ) {
        throw new TrustedPublicKeyringError('crypto-unavailable');
    }

    return cryptoImpl;
}

function decodeBase64Url(value, code) {
    if (typeof value !== 'string' || !BASE64URL_RE.test(value)) {
        fail(code);
    }

    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    let binary;

    try {
        binary = atob(
            value.replace(/-/g, '+').replace(/_/g, '/') + padding,
        );
    } catch {
        fail(code);
    }

    const bytes = Uint8Array.from(
        binary,
        (character) => character.charCodeAt(0),
    );

    const encoded = btoa(
        String.fromCharCode(...bytes),
    )
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/g, '');

    if (encoded !== value) {
        fail(code);
    }

    return bytes;
}

export async function trustedPublicKeyringFingerprint(
    keyringVersion,
    keys,
    cryptoImpl = globalThis.crypto,
) {
    if (!Number.isSafeInteger(keyringVersion) || keyringVersion < 1) {
        fail('keyring-version-invalid');
    }

    plainObject(keys, 'keyring-not-object');
    const canonical = canonicalizeTrustedPublicKeyring({
        keyring_version: keyringVersion,
        keys,
    });
    const bytes = new TextEncoder().encode(JSON.stringify(canonical));
    const digest = await requireCrypto(cryptoImpl).subtle.digest(
        'SHA-256',
        bytes,
    );

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

function validateJwk(jwk) {
    plainObject(jwk, 'keyring-jwk-not-object');
    exactKeys(jwk, JWK_KEYS, 'keyring-jwk-shape');

    if (
        jwk.alg !== 'EdDSA'
        || jwk.crv !== 'Ed25519'
        || jwk.kty !== 'OKP'
        || jwk.use !== 'sig'
        || decodeBase64Url(jwk.x, 'keyring-jwk-x').length !== 32
    ) {
        fail('keyring-jwk-invalid');
    }
}

function requireInstant(value, code) {
    if (typeof value !== 'string' || value === '') {
        fail(code);
    }

    const epoch = Date.parse(value);
    if (!Number.isFinite(epoch)) {
        fail(code);
    }

    return epoch;
}

export async function validateTrustedPublicKeyringBundle(
    bundle,
    {
        nowMs = Date.now(),
        cryptoImpl = globalThis.crypto,
    } = {},
) {
    plainObject(bundle, 'bundle-not-object');
    exactKeys(bundle, TOP_LEVEL_KEYS, 'bundle-shape');

    if (bundle.bundle_version !== TRUST_BUNDLE_VERSION) {
        fail('bundle-version-mismatch');
    }

    const scope = plainObject(bundle.scope, 'scope-not-object');
    exactKeys(scope, SCOPE_KEYS, 'scope-shape');

    if (
        typeof scope.binding_public_id !== 'string'
        || !UUID_RE.test(scope.binding_public_id)
        || typeof scope.device_public_id !== 'string'
        || !UUID_RE.test(scope.device_public_id)
    ) {
        fail('scope-invalid');
    }

    const issuedAtMs = requireInstant(
        bundle.server_issued_at,
        'server-issued-at-invalid',
    );
    const expiresAtMs = requireInstant(
        bundle.expires_at,
        'expires-at-invalid',
    );

    if (
        issuedAtMs > nowMs + TRUST_CLOCK_SKEW_SECONDS * 1000
        || expiresAtMs <= issuedAtMs
        || expiresAtMs <= nowMs
        || expiresAtMs - issuedAtMs > TRUST_MAX_VALIDITY_SECONDS * 1000
    ) {
        fail('bundle-time-contract-invalid');
    }

    if (
        !Number.isSafeInteger(bundle.keyring_version)
        || bundle.keyring_version < 1
    ) {
        fail('keyring-version-invalid');
    }

    const keys = plainObject(bundle.keys, 'keyring-not-object');
    const kids = Object.keys(keys);

    if (kids.length < 1 || kids.length > 16) {
        fail('keyring-cardinality-invalid');
    }

    for (const kid of kids) {
        if (!KID_RE.test(kid)) {
            fail('keyring-kid-invalid');
        }
        validateJwk(keys[kid]);
    }

    if (
        typeof bundle.keyring_fingerprint !== 'string'
        || !SHA256_RE.test(bundle.keyring_fingerprint)
    ) {
        fail('keyring-fingerprint-invalid');
    }

    const fingerprint = await trustedPublicKeyringFingerprint(
        bundle.keyring_version,
        keys,
        cryptoImpl,
    );

    if (fingerprint !== bundle.keyring_fingerprint) {
        fail('keyring-fingerprint-mismatch');
    }

    return {
        bundle,
        issuedAtMs,
        expiresAtMs,
    };
}

export function decideTrustedPublicKeyringUpdate(
    current,
    incoming,
    nowMs = Date.now(),
) {
    if (!current) {
        return 'replace';
    }

    const currentExpiresAt = Date.parse(current.expires_at ?? '');
    if (
        !Number.isFinite(currentExpiresAt)
        || currentExpiresAt <= nowMs
        || !Number.isSafeInteger(current.keyring_version)
        || current.keyring_version < 1
        || typeof current.keyring_fingerprint !== 'string'
        || !SHA256_RE.test(current.keyring_fingerprint)
    ) {
        return 'replace';
    }

    if (incoming.keyring_version < current.keyring_version) {
        return 'reject-rollback';
    }

    if (incoming.keyring_version > current.keyring_version) {
        return 'replace';
    }

    return incoming.keyring_fingerprint === current.keyring_fingerprint
        ? 'refresh'
        : 'reject-version-reuse';
}

export function buildTrustedPublicKeyringEnvelope(
    bundle,
    storedAt = new Date(),
) {
    const storedAtIso = storedAt instanceof Date
        ? storedAt.toISOString()
        : String(storedAt);

    requireInstant(storedAtIso, 'stored-at-invalid');

    return {
        key: TRUST_CURRENT_RECORD_KEY,
        storage_schema_version: TRUST_STORAGE_SCHEMA_VERSION,
        bundle_version: bundle.bundle_version,
        binding_public_id: bundle.scope.binding_public_id,
        device_public_id: bundle.scope.device_public_id,
        keyring_version: bundle.keyring_version,
        keyring_fingerprint: bundle.keyring_fingerprint,
        expires_at: bundle.expires_at,
        stored_at: storedAtIso,
        payload: bundle,
    };
}

function requestPromise(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(
            request.error
                ?? new TrustedPublicKeyringError(
                    'indexeddb-request-failed',
                ),
        );
    });
}

function transactionPromise(transaction) {
    return new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve();
        transaction.onabort = () => reject(
            transaction.error
                ?? new TrustedPublicKeyringError(
                    'indexeddb-transaction-aborted',
                ),
        );
        transaction.onerror = () => reject(
            transaction.error
                ?? new TrustedPublicKeyringError(
                    'indexeddb-transaction-failed',
                ),
        );
    });
}

function requireIndexedDb(indexedDbFactory) {
    if (
        !indexedDbFactory
        || typeof indexedDbFactory.open !== 'function'
    ) {
        throw new TrustedPublicKeyringError('indexeddb-unavailable');
    }

    return indexedDbFactory;
}

export function openTrustedPublicKeyringDatabase(
    indexedDbFactory = globalThis.indexedDB,
) {
    const factory = requireIndexedDb(indexedDbFactory);

    return new Promise((resolve, reject) => {
        const request = factory.open(TRUST_DB_NAME, TRUST_DB_VERSION);

        request.onupgradeneeded = () => {
            const database = request.result;
            if (!database.objectStoreNames.contains(TRUST_STORE_NAME)) {
                database.createObjectStore(
                    TRUST_STORE_NAME,
                    { keyPath: 'key' },
                );
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(
            request.error
                ?? new TrustedPublicKeyringError(
                    'indexeddb-open-failed',
                ),
        );
        request.onblocked = () => reject(
            new TrustedPublicKeyringError('indexeddb-open-blocked'),
        );
    });
}

async function clearCurrentRecord(
    indexedDbFactory = globalThis.indexedDB,
) {
    const database = await openTrustedPublicKeyringDatabase(
        indexedDbFactory,
    );

    try {
        const transaction = database.transaction(
            TRUST_STORE_NAME,
            'readwrite',
        );
        const completed = transactionPromise(transaction);
        transaction.objectStore(TRUST_STORE_NAME).clear();
        await completed;
    } finally {
        database.close();
    }
}

export function destroyTrustedPublicKeyringDatabase(
    indexedDbFactory = globalThis.indexedDB,
) {
    const factory = requireIndexedDb(indexedDbFactory);

    if (typeof factory.deleteDatabase !== 'function') {
        return Promise.resolve(false);
    }

    return new Promise((resolve) => {
        const request = factory.deleteDatabase(TRUST_DB_NAME);
        request.onsuccess = () => resolve(true);
        request.onerror = () => resolve(false);
        request.onblocked = () => resolve(false);
    });
}

export async function purgeTrustedPublicKeyringStore(
    indexedDbFactory = globalThis.indexedDB,
) {
    try {
        await clearCurrentRecord(indexedDbFactory);
        return true;
    } catch {
        return destroyTrustedPublicKeyringDatabase(indexedDbFactory);
    }
}

export async function readTrustedPublicKeyring(
    {
        indexedDbFactory = globalThis.indexedDB,
        nowMs = Date.now(),
        expectedScope = null,
        cryptoImpl = globalThis.crypto,
    } = {},
) {
    let database;

    try {
        database = await openTrustedPublicKeyringDatabase(
            indexedDbFactory,
        );
        const transaction = database.transaction(
            TRUST_STORE_NAME,
            'readonly',
        );
        const completed = transactionPromise(transaction);
        const request = transaction
            .objectStore(TRUST_STORE_NAME)
            .get(TRUST_CURRENT_RECORD_KEY);
        const envelope = await requestPromise(request);
        await completed;

        if (!envelope) {
            return null;
        }

        if (
            envelope.key !== TRUST_CURRENT_RECORD_KEY
            || envelope.storage_schema_version
                !== TRUST_STORAGE_SCHEMA_VERSION
        ) {
            fail('storage-envelope-invalid');
        }

        const validated = await validateTrustedPublicKeyringBundle(
            envelope.payload,
            { nowMs, cryptoImpl },
        );
        const bundle = validated.bundle;

        if (
            envelope.bundle_version !== bundle.bundle_version
            || envelope.binding_public_id
                !== bundle.scope.binding_public_id
            || envelope.device_public_id
                !== bundle.scope.device_public_id
            || envelope.keyring_version !== bundle.keyring_version
            || envelope.keyring_fingerprint
                !== bundle.keyring_fingerprint
            || envelope.expires_at !== bundle.expires_at
        ) {
            fail('storage-envelope-metadata-mismatch');
        }

        if (expectedScope !== null) {
            plainObject(expectedScope, 'expected-scope-not-object');
            if (
                expectedScope.binding_public_id
                    !== bundle.scope.binding_public_id
                || expectedScope.device_public_id
                    !== bundle.scope.device_public_id
            ) {
                fail('expected-scope-mismatch');
            }
        }

        return {
            bundle,
            storedAtMs: requireInstant(
                envelope.stored_at,
                'stored-at-invalid',
            ),
            expiresAtMs: validated.expiresAtMs,
        };
    } catch {
        await purgeTrustedPublicKeyringStore(indexedDbFactory);
        return null;
    } finally {
        if (database) {
            database.close();
        }
    }
}

export async function writeTrustedPublicKeyring(
    bundle,
    {
        indexedDbFactory = globalThis.indexedDB,
        now = new Date(),
        cryptoImpl = globalThis.crypto,
    } = {},
) {
    const nowMs = now instanceof Date
        ? now.getTime()
        : Date.parse(String(now));

    if (!Number.isFinite(nowMs)) {
        fail('write-now-invalid');
    }

    await validateTrustedPublicKeyringBundle(
        bundle,
        { nowMs, cryptoImpl },
    );
    const incomingEnvelope = buildTrustedPublicKeyringEnvelope(
        bundle,
        now,
    );
    const database = await openTrustedPublicKeyringDatabase(
        indexedDbFactory,
    );

    try {
        const transaction = database.transaction(
            TRUST_STORE_NAME,
            'readwrite',
        );
        const completed = transactionPromise(transaction);
        const store = transaction.objectStore(TRUST_STORE_NAME);
        const currentRequest = store.get(TRUST_CURRENT_RECORD_KEY);
        let decision = 'replace';
        let current = null;

        currentRequest.onsuccess = () => {
            current = currentRequest.result ?? null;
            decision = decideTrustedPublicKeyringUpdate(
                current,
                incomingEnvelope,
                nowMs,
            );

            if (decision === 'replace' || decision === 'refresh') {
                store.put(incomingEnvelope);
            }
        };
        currentRequest.onerror = () => transaction.abort();

        await completed;

        return {
            decision,
            envelope:
                decision === 'replace' || decision === 'refresh'
                    ? incomingEnvelope
                    : current,
        };
    } finally {
        database.close();
    }
}

function responseLooksLikeJson(response) {
    const contentType = response.headers?.get?.('content-type') ?? '';
    return contentType.toLowerCase().includes('application/json');
}

export async function refreshTrustedPublicKeyring(
    {
        fetchImpl = globalThis.fetch,
        indexedDbFactory = globalThis.indexedDB,
        cryptoImpl = globalThis.crypto,
        endpoint = TRUST_ENDPOINT,
        now = new Date(),
        readImpl = readTrustedPublicKeyring,
        writeImpl = writeTrustedPublicKeyring,
        purgeImpl = purgeTrustedPublicKeyringStore,
    } = {},
) {
    if (typeof fetchImpl !== 'function') {
        throw new TrustedPublicKeyringError('fetch-unavailable');
    }

    const fallback = async (status) => ({
        status,
        trusted: await readImpl({
            indexedDbFactory,
            nowMs: now.getTime(),
            cryptoImpl,
        }),
    });

    let response;

    try {
        response = await fetchImpl(endpoint, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'manual',
            headers: { Accept: 'application/json' },
        });
    } catch {
        return fallback('network-unavailable');
    }

    if (
        response.type === 'opaqueredirect'
        || response.status === 0
        || response.status === 401
        || response.status === 403
    ) {
        await purgeImpl(indexedDbFactory);
        return { status: 'authority-unavailable', trusted: null };
    }

    if (response.status >= 500) {
        return fallback('server-unavailable');
    }

    if (!response.ok) {
        return fallback('request-rejected');
    }

    if (!responseLooksLikeJson(response)) {
        await purgeImpl(indexedDbFactory);
        return { status: 'invalid-authoritative-response', trusted: null };
    }

    let bundle;

    try {
        bundle = await response.json();
        await validateTrustedPublicKeyringBundle(
            bundle,
            {
                nowMs: now.getTime(),
                cryptoImpl,
            },
        );
    } catch (error) {
        await purgeImpl(indexedDbFactory);
        return {
            status: 'invalid-authoritative-keyring',
            trusted: null,
            errorCode: error?.code ?? 'invalid-keyring',
        };
    }

    const written = await writeImpl(
        bundle,
        {
            indexedDbFactory,
            now,
            cryptoImpl,
        },
    );

    if (written.decision === 'reject-rollback') {
        return fallback('rollback-rejected');
    }

    if (written.decision === 'reject-version-reuse') {
        return fallback('version-reuse-rejected');
    }

    return {
        status: written.decision === 'refresh' ? 'refreshed' : 'stored',
        trusted: {
            bundle,
            expiresAtMs: Date.parse(bundle.expires_at),
        },
    };
}

export async function refreshTrustedPublicKeyringForIssuance(options = {}) {
    const refreshed = await refreshTrustedPublicKeyring(options);

    if (!['stored', 'refreshed'].includes(refreshed.status)) {
        throw new TrustedPublicKeyringError(
            'pre-issuance-trust-refresh-required',
        );
    }

    return refreshed.trusted;
}

export async function verifyRestrictedOfflineSignedGrantWithPersistedTrust(
    compact,
    {
        indexedDbFactory = globalThis.indexedDB,
        nowMs = Date.now(),
        cryptoImpl = globalThis.crypto,
    } = {},
) {
    const trusted = await readTrustedPublicKeyring({
        indexedDbFactory,
        nowMs,
        cryptoImpl,
    });

    if (!trusted) {
        throw new TrustedPublicKeyringError('trusted-keyring-unavailable');
    }

    const verified = await verifyRestrictedOfflineSignedGrant(
        compact,
        trusted.bundle.keys,
        { nowMs, cryptoImpl },
    );

    if (
        verified.claims.binding_public_id
            !== trusted.bundle.scope.binding_public_id
        || verified.claims.device_public_id
            !== trusted.bundle.scope.device_public_id
    ) {
        throw new TrustedPublicKeyringError('grant-trust-scope-mismatch');
    }

    return verified;
}

export function bootstrapRestrictedOfflineTrustedPublicKeyringPersistence(
    {
        windowObject = globalThis.window,
        navigatorObject = globalThis.navigator,
        indexedDbFactory = globalThis.indexedDB,
        fetchImpl = globalThis.fetch,
        cryptoImpl = globalThis.crypto,
    } = {},
) {
    if (
        !windowObject
        || !navigatorObject
        || !indexedDbFactory
    ) {
        return;
    }

    const safeRefresh = () => {
        void refreshTrustedPublicKeyring({
            fetchImpl,
            indexedDbFactory,
            cryptoImpl,
        }).catch(() => undefined);
    };

    if (windowObject.location?.pathname === '/login') {
        void purgeTrustedPublicKeyringStore(indexedDbFactory);
    } else if (navigatorObject.onLine) {
        safeRefresh();
    }

    windowObject.addEventListener('online', safeRefresh);
}
