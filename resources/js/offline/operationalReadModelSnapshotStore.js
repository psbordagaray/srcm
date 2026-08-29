export const STORAGE_SCHEMA_VERSION = 1;
export const SNAPSHOT_VERSION = 2;
export const DB_NAME = 'srcm-restricted-offline-v1';
export const DB_VERSION = 1;
export const STORE_NAME = 'operational-read-model-snapshots';
export const CURRENT_RECORD_KEY = 'current';
export const SNAPSHOT_ENDPOINT = '/runtime/offline-read-model-snapshot';

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const SHA256_RE = /^[0-9a-f]{64}$/;

export class OfflineSnapshotValidationError extends Error {
    constructor(code) {
        super(code);
        this.name = 'OfflineSnapshotValidationError';
        this.code = code;
    }
}

export class OfflineSnapshotStorageError extends Error {
    constructor(code, cause = null) {
        super(code);
        this.name = 'OfflineSnapshotStorageError';
        this.code = code;
        this.cause = cause;
    }
}

function fail(code) {
    throw new OfflineSnapshotValidationError(code);
}

function isPlainObject(value) {
    return value !== null
        && typeof value === 'object'
        && !Array.isArray(value);
}

function requirePlainObject(value, code) {
    if (!isPlainObject(value)) {
        fail(code);
    }

    return value;
}

function requireArray(value, code) {
    if (!Array.isArray(value)) {
        fail(code);
    }

    return value;
}

function requireIsoInstant(value, code) {
    if (typeof value !== 'string' || value === '') {
        fail(code);
    }

    const epoch = Date.parse(value);

    if (!Number.isFinite(epoch)) {
        fail(code);
    }

    return epoch;
}

export function canonicalize(value) {
    if (Array.isArray(value)) {
        return value.map((item) => canonicalize(item));
    }

    if (!isPlainObject(value)) {
        return value;
    }

    return Object.fromEntries(
        Object.keys(value)
            .sort()
            .map((key) => [key, canonicalize(value[key])]),
    );
}

export function operationalSnapshotContent(snapshot) {
    return {
        device: snapshot.device,
        catalog: snapshot.catalog,
        locations: snapshot.locations,
        conditions: snapshot.conditions,
        prices: snapshot.prices,
        availability: snapshot.availability,
        policy: snapshot.policy,
    };
}

function requireCrypto(cryptoImpl) {
    if (
        !cryptoImpl
        || !cryptoImpl.subtle
        || typeof cryptoImpl.subtle.digest !== 'function'
    ) {
        throw new OfflineSnapshotStorageError('crypto-unavailable');
    }

    return cryptoImpl;
}

export async function sha256Hex(text, cryptoImpl = globalThis.crypto) {
    const cryptoApi = requireCrypto(cryptoImpl);
    const bytes = new TextEncoder().encode(text);
    const digest = await cryptoApi.subtle.digest('SHA-256', bytes);

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

export async function fingerprintOperationalSnapshot(
    snapshot,
    cryptoImpl = globalThis.crypto,
) {
    const canonical = canonicalize(
        operationalSnapshotContent(snapshot),
    );

    return sha256Hex(
        JSON.stringify(canonical),
        cryptoImpl,
    );
}

function validatePolicy(policy) {
    requirePlainObject(policy, 'policy-not-object');

    const requiredTrue = [
        'server_authoritative_at_confirmation',
        'price_revalidation_required_at_confirmation',
        'availability_revalidation_required_at_confirmation',
    ];

    const requiredFalse = [
        'offline_final_sale_allowed',
        'offline_payment_finalization_allowed',
        'offline_fiscal_authorization_allowed',
        'silent_price_or_stock_conflict_merge_allowed',
        'contains_customer_data',
        'contains_customer_credit_data',
        'contains_cash_session_data',
        'contains_financial_account_data',
        'contains_payment_data',
        'contains_fiscal_credentials',
    ];

    for (const key of requiredTrue) {
        if (policy[key] !== true) {
            fail(`policy-${key}-must-be-true`);
        }
    }

    for (const key of requiredFalse) {
        if (policy[key] !== false) {
            fail(`policy-${key}-must-be-false`);
        }
    }
}

export async function validateOperationalSnapshot(
    snapshot,
    {
        nowMs = Date.now(),
        cryptoImpl = globalThis.crypto,
    } = {},
) {
    requirePlainObject(snapshot, 'snapshot-not-object');

    if (snapshot.snapshot_version !== SNAPSHOT_VERSION) {
        fail('snapshot-version-mismatch');
    }

    const generatedAt = requireIsoInstant(
        snapshot.generated_at,
        'generated-at-invalid',
    );

    const scope = requirePlainObject(
        snapshot.scope,
        'scope-not-object',
    );

    if (
        typeof scope.binding_public_id !== 'string'
        || !UUID_RE.test(scope.binding_public_id)
    ) {
        fail('binding-public-id-invalid');
    }

    if (
        typeof scope.device_public_id !== 'string'
        || !UUID_RE.test(scope.device_public_id)
    ) {
        fail('device-public-id-invalid');
    }

    const bindingExpiresAt = requireIsoInstant(
        scope.binding_expires_at,
        'binding-expires-at-invalid',
    );

    if (bindingExpiresAt <= nowMs) {
        fail('binding-expired');
    }

    const device = requirePlainObject(
        snapshot.device,
        'device-not-object',
    );

    if (device.public_id !== scope.device_public_id) {
        fail('scope-device-mismatch');
    }

    if (
        typeof snapshot.content_fingerprint !== 'string'
        || !SHA256_RE.test(snapshot.content_fingerprint)
    ) {
        fail('content-fingerprint-invalid');
    }

    requireArray(snapshot.catalog, 'catalog-not-array');
    requireArray(snapshot.locations, 'locations-not-array');
    requireArray(snapshot.conditions, 'conditions-not-array');
    requireArray(snapshot.prices, 'prices-not-array');
    requireArray(snapshot.availability, 'availability-not-array');

    validatePolicy(snapshot.policy);

    const computedFingerprint = await fingerprintOperationalSnapshot(
        snapshot,
        cryptoImpl,
    );

    if (computedFingerprint !== snapshot.content_fingerprint) {
        fail('content-fingerprint-mismatch');
    }

    return {
        snapshot,
        generatedAtMs: generatedAt,
        bindingExpiresAtMs: bindingExpiresAt,
    };
}

export function buildStorageEnvelope(
    snapshot,
    storedAt = new Date(),
) {
    const storedAtIso = storedAt instanceof Date
        ? storedAt.toISOString()
        : String(storedAt);

    requireIsoInstant(storedAtIso, 'stored-at-invalid');

    return {
        key: CURRENT_RECORD_KEY,
        storage_schema_version: STORAGE_SCHEMA_VERSION,
        snapshot_version: snapshot.snapshot_version,
        binding_public_id: snapshot.scope.binding_public_id,
        device_public_id: snapshot.scope.device_public_id,
        binding_expires_at: snapshot.scope.binding_expires_at,
        content_fingerprint: snapshot.content_fingerprint,
        generated_at: snapshot.generated_at,
        stored_at: storedAtIso,
        payload: snapshot,
    };
}

export async function validateStorageEnvelope(
    envelope,
    {
        nowMs = Date.now(),
        expectedScope = null,
        cryptoImpl = globalThis.crypto,
    } = {},
) {
    requirePlainObject(envelope, 'envelope-not-object');

    if (envelope.key !== CURRENT_RECORD_KEY) {
        fail('envelope-key-mismatch');
    }

    if (envelope.storage_schema_version !== STORAGE_SCHEMA_VERSION) {
        fail('storage-schema-version-mismatch');
    }

    if (envelope.snapshot_version !== SNAPSHOT_VERSION) {
        fail('envelope-snapshot-version-mismatch');
    }

    const storedAtMs = requireIsoInstant(
        envelope.stored_at,
        'stored-at-invalid',
    );

    const validated = await validateOperationalSnapshot(
        envelope.payload,
        {
            nowMs,
            cryptoImpl,
        },
    );

    const scope = validated.snapshot.scope;

    if (
        envelope.binding_public_id !== scope.binding_public_id
        || envelope.device_public_id !== scope.device_public_id
        || envelope.binding_expires_at !== scope.binding_expires_at
        || envelope.content_fingerprint
            !== validated.snapshot.content_fingerprint
        || envelope.generated_at !== validated.snapshot.generated_at
    ) {
        fail('envelope-metadata-mismatch');
    }

    if (expectedScope !== null) {
        requirePlainObject(
            expectedScope,
            'expected-scope-not-object',
        );

        if (
            expectedScope.binding_public_id
                !== scope.binding_public_id
            || expectedScope.device_public_id
                !== scope.device_public_id
        ) {
            fail('expected-scope-mismatch');
        }
    }

    return {
        snapshot: validated.snapshot,
        storedAtMs,
        ageMs: Math.max(0, nowMs - storedAtMs),
        bindingExpiresAtMs: validated.bindingExpiresAtMs,
    };
}

function requestPromise(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(
            request.error
                ?? new OfflineSnapshotStorageError(
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
                ?? new OfflineSnapshotStorageError(
                    'indexeddb-transaction-aborted',
                ),
        );
        transaction.onerror = () => reject(
            transaction.error
                ?? new OfflineSnapshotStorageError(
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
        throw new OfflineSnapshotStorageError(
            'indexeddb-unavailable',
        );
    }

    return indexedDbFactory;
}

export function openOperationalSnapshotDatabase(
    indexedDbFactory = globalThis.indexedDB,
) {
    const factory = requireIndexedDb(indexedDbFactory);

    return new Promise((resolve, reject) => {
        const request = factory.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const database = request.result;

            if (!database.objectStoreNames.contains(STORE_NAME)) {
                database.createObjectStore(
                    STORE_NAME,
                    {
                        keyPath: 'key',
                    },
                );
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(
            request.error
                ?? new OfflineSnapshotStorageError(
                    'indexeddb-open-failed',
                ),
        );
        request.onblocked = () => reject(
            new OfflineSnapshotStorageError(
                'indexeddb-open-blocked',
            ),
        );
    });
}

async function clearCurrentRecord(
    indexedDbFactory = globalThis.indexedDB,
) {
    const database = await openOperationalSnapshotDatabase(
        indexedDbFactory,
    );

    try {
        const transaction = database.transaction(
            STORE_NAME,
            'readwrite',
        );
        const completed = transactionPromise(transaction);

        transaction.objectStore(STORE_NAME).clear();

        await completed;
    } finally {
        database.close();
    }
}

export async function purgeOperationalSnapshotStore(
    indexedDbFactory = globalThis.indexedDB,
) {
    try {
        await clearCurrentRecord(indexedDbFactory);

        return true;
    } catch {
        return destroyOperationalSnapshotDatabase(
            indexedDbFactory,
        );
    }
}

export function destroyOperationalSnapshotDatabase(
    indexedDbFactory = globalThis.indexedDB,
) {
    const factory = requireIndexedDb(indexedDbFactory);

    if (typeof factory.deleteDatabase !== 'function') {
        return Promise.resolve(false);
    }

    return new Promise((resolve) => {
        const request = factory.deleteDatabase(DB_NAME);

        request.onsuccess = () => resolve(true);
        request.onerror = () => resolve(false);
        request.onblocked = () => resolve(false);
    });
}

export async function writeOperationalSnapshot(
    snapshot,
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

    await validateOperationalSnapshot(
        snapshot,
        {
            nowMs,
            cryptoImpl,
        },
    );

    const envelope = buildStorageEnvelope(snapshot, now);
    const database = await openOperationalSnapshotDatabase(
        indexedDbFactory,
    );

    try {
        const transaction = database.transaction(
            STORE_NAME,
            'readwrite',
        );
        const completed = transactionPromise(transaction);

        transaction.objectStore(STORE_NAME).put(envelope);

        await completed;

        return envelope;
    } catch (error) {
        if (
            error?.name === 'QuotaExceededError'
            || error?.code === 'QuotaExceededError'
        ) {
            await purgeOperationalSnapshotStore(
                indexedDbFactory,
            );

            throw new OfflineSnapshotStorageError(
                'quota-exceeded',
                error,
            );
        }

        throw new OfflineSnapshotStorageError(
            'indexeddb-write-failed',
            error,
        );
    } finally {
        database.close();
    }
}

export async function readOperationalSnapshot(
    {
        indexedDbFactory = globalThis.indexedDB,
        nowMs = Date.now(),
        expectedScope = null,
        cryptoImpl = globalThis.crypto,
    } = {},
) {
    let database;

    try {
        database = await openOperationalSnapshotDatabase(
            indexedDbFactory,
        );

        const transaction = database.transaction(
            STORE_NAME,
            'readonly',
        );
        const completed = transactionPromise(transaction);
        const request = transaction
            .objectStore(STORE_NAME)
            .get(CURRENT_RECORD_KEY);

        const envelope = await requestPromise(request);

        await completed;

        if (!envelope) {
            return null;
        }

        return await validateStorageEnvelope(
            envelope,
            {
                nowMs,
                expectedScope,
                cryptoImpl,
            },
        );
    } catch (error) {
        if (error instanceof OfflineSnapshotValidationError) {
            await purgeOperationalSnapshotStore(
                indexedDbFactory,
            );

            return null;
        }

        await destroyOperationalSnapshotDatabase(
            indexedDbFactory,
        );

        return null;
    } finally {
        if (database) {
            database.close();
        }
    }
}

function responseLooksLikeJson(response) {
    const contentType = response.headers?.get?.('content-type')
        ?? '';

    return contentType
        .toLowerCase()
        .includes('application/json');
}

export async function refreshOperationalSnapshot(
    {
        fetchImpl = globalThis.fetch,
        indexedDbFactory = globalThis.indexedDB,
        cryptoImpl = globalThis.crypto,
        endpoint = SNAPSHOT_ENDPOINT,
        now = new Date(),
    } = {},
) {
    if (typeof fetchImpl !== 'function') {
        throw new OfflineSnapshotStorageError(
            'fetch-unavailable',
        );
    }

    let response;

    try {
        response = await fetchImpl(
            endpoint,
            {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'manual',
                headers: {
                    Accept: 'application/json',
                },
            },
        );
    } catch {
        return {
            status: 'network-unavailable',
            snapshot: await readOperationalSnapshot({
                indexedDbFactory,
                nowMs: now.getTime(),
                cryptoImpl,
            }),
        };
    }

    if (
        response.type === 'opaqueredirect'
        || response.status === 0
        || (response.status >= 300 && response.status < 500)
    ) {
        await purgeOperationalSnapshotStore(
            indexedDbFactory,
        );

        return {
            status: 'authority-unavailable',
            snapshot: null,
        };
    }

    if (!response.ok) {
        return {
            status: 'server-unavailable',
            snapshot: await readOperationalSnapshot({
                indexedDbFactory,
                nowMs: now.getTime(),
                cryptoImpl,
            }),
        };
    }

    if (!responseLooksLikeJson(response)) {
        await purgeOperationalSnapshotStore(
            indexedDbFactory,
        );

        return {
            status: 'invalid-authoritative-response',
            snapshot: null,
        };
    }

    let snapshot;

    try {
        snapshot = await response.json();

        await writeOperationalSnapshot(
            snapshot,
            {
                indexedDbFactory,
                now,
                cryptoImpl,
            },
        );
    } catch (error) {
        await purgeOperationalSnapshotStore(
            indexedDbFactory,
        );

        return {
            status: 'invalid-authoritative-snapshot',
            snapshot: null,
            errorCode: error?.code ?? 'invalid-snapshot',
        };
    }

    return {
        status: 'stored',
        snapshot,
    };
}

export function isPurgeSensitiveNavigation(
    action,
    methodOverride = '',
    baseUrl = 'http://localhost',
) {
    let url;

    try {
        url = new URL(action, baseUrl);
    } catch {
        return false;
    }

    const method = String(methodOverride).toUpperCase();

    if (url.pathname === '/logout') {
        return true;
    }

    if (
        /^\/organizations\/[0-9]+\/activate$/.test(
            url.pathname,
        )
    ) {
        return true;
    }

    return (
        url.pathname === '/operational-device/browser-binding'
        && method === 'DELETE'
    );
}

function installSensitiveNavigationPurge(
    documentObject,
    indexedDbFactory,
) {
    documentObject.addEventListener(
        'submit',
        (event) => {
            const form = event.target;

            if (
                !form
                || typeof form.action !== 'string'
            ) {
                return;
            }

            const override = form.elements
                ?.namedItem?.('_method')
                ?.value
                ?? '';

            if (
                !isPurgeSensitiveNavigation(
                    form.action,
                    override,
                    documentObject.location?.href
                        ?? 'http://localhost',
                )
            ) {
                return;
            }

            event.preventDefault();

            const submitter = event.submitter ?? null;

            void purgeOperationalSnapshotStore(
                indexedDbFactory,
            ).finally(() => {
                if (
                    submitter
                    && submitter.name
                    && documentObject.createElement
                ) {
                    const input = documentObject.createElement(
                        'input',
                    );
                    input.type = 'hidden';
                    input.name = submitter.name;
                    input.value = submitter.value;
                    form.appendChild(input);
                }

                const formPrototype = documentObject
                    .defaultView
                    ?.HTMLFormElement
                    ?.prototype;

                if (
                    formPrototype
                    && typeof formPrototype.submit === 'function'
                ) {
                    formPrototype.submit.call(form);
                    return;
                }

                form.submit();
            });
        },
        true,
    );
}

export function bootstrapOperationalSnapshotPersistence(
    {
        windowObject = globalThis.window,
        documentObject = globalThis.document,
        navigatorObject = globalThis.navigator,
        indexedDbFactory = globalThis.indexedDB,
        fetchImpl = globalThis.fetch,
        cryptoImpl = globalThis.crypto,
        manageSensitiveNavigation = true,
    } = {},
) {
    if (
        !windowObject
        || !documentObject
        || !navigatorObject
        || !indexedDbFactory
    ) {
        return;
    }

    if (manageSensitiveNavigation) {
        installSensitiveNavigationPurge(
            documentObject,
            indexedDbFactory,
        );
    }


    const safeRefresh = () => {
        void refreshOperationalSnapshot({
            fetchImpl,
            indexedDbFactory,
            cryptoImpl,
        }).catch(() => undefined);
    };

    if (windowObject.location?.pathname === '/login') {
        void purgeOperationalSnapshotStore(
            indexedDbFactory,
        );
    } else if (navigatorObject.onLine) {
        safeRefresh();
    }

    windowObject.addEventListener(
        'online',
        safeRefresh,
    );
}
