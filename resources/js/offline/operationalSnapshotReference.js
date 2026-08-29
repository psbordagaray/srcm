import {
    readOperationalSnapshot,
    refreshOperationalSnapshot,
} from './operationalReadModelSnapshotStore.js';

export const OPERATIONAL_REFERENCE_STATE = Object.freeze({
    ONLINE_REFRESHED: 'online-refreshed',
    OFFLINE_CACHED_VALID: 'offline-cached-valid',
    NO_VALID_CACHE: 'no-valid-cache',
    AUTHORITY_REJECTED: 'authority-rejected',
});

const AUTHORITY_REJECTION_STATUSES = new Set([
    'authority-unavailable',
    'invalid-authoritative-response',
    'invalid-authoritative-snapshot',
]);

export function normalizeOperationalReferenceText(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLocaleLowerCase('es-AR');
}

function asIsoInstant(epochMs) {
    if (!Number.isFinite(epochMs)) {
        return null;
    }

    return new Date(epochMs).toISOString();
}

function pushMapList(map, key, value) {
    const current = map.get(key) ?? [];
    current.push(value);
    map.set(key, current);
}

export function buildOperationalSnapshotReference(validated) {
    if (!validated?.snapshot) {
        return null;
    }

    const snapshot = validated.snapshot;
    const locationById = new Map(
        snapshot.locations.map((location) => [
            Number(location.id),
            location.name,
        ]),
    );
    const conditionByValue = new Map(
        snapshot.conditions.map((condition) => [
            condition.value,
            condition.label,
        ]),
    );
    const pricesByProduct = new Map();
    const availabilityByProduct = new Map();

    for (const price of snapshot.prices) {
        pushMapList(
            pricesByProduct,
            Number(price.product_id),
            {
                currency: price.currency,
                amountMinor: Number(price.amount_minor),
                validFrom: price.valid_from,
                validUntil: price.valid_until,
            },
        );
    }

    for (const position of snapshot.availability) {
        pushMapList(
            availabilityByProduct,
            Number(position.product_id),
            {
                locationId: Number(position.location_id),
                location:
                    locationById.get(Number(position.location_id))
                    ?? `#${position.location_id}`,
                condition: position.condition,
                conditionLabel:
                    conditionByValue.get(position.condition)
                    ?? position.condition,
                availableQuantity: String(
                    position.available_quantity,
                ),
                balanceVersion: Number(position.balance_version),
            },
        );
    }

    const products = snapshot.catalog.map((product) => {
        const searchTerms = Array.isArray(product.search_terms)
            ? product.search_terms
            : [];

        return {
            id: Number(product.id),
            sku: String(product.sku),
            name: String(product.name),
            unit: String(product.unit),
            scale: Number(product.scale),
            category: product.category,
            brand: product.brand,
            manufacturer: product.manufacturer,
            searchTerms,
            prices: pricesByProduct.get(Number(product.id)) ?? [],
            availability:
                availabilityByProduct.get(Number(product.id)) ?? [],
        };
    });

    return {
        snapshotVersion: snapshot.snapshot_version,
        generatedAt: snapshot.generated_at,
        storedAt: asIsoInstant(validated.storedAtMs),
        ageMs: Number(validated.ageMs ?? 0),
        bindingExpiresAt: snapshot.scope.binding_expires_at,
        bindingPublicId: snapshot.scope.binding_public_id,
        devicePublicId: snapshot.scope.device_public_id,
        deviceLabel: snapshot.device.label,
        contentFingerprint: snapshot.content_fingerprint,
        products,
        policy: snapshot.policy,
    };
}

function scoreProduct(product, needle) {
    if (needle === '') {
        return 100;
    }

    const candidates = [
        {
            value: product.sku,
            exact: true,
        },
        {
            value: product.name,
            exact: false,
        },
        {
            value: product.category,
            exact: false,
        },
        {
            value: product.brand,
            exact: false,
        },
        {
            value: product.manufacturer,
            exact: false,
        },
        ...product.searchTerms,
    ];

    let best = Number.POSITIVE_INFINITY;

    for (const candidate of candidates) {
        const normalized = normalizeOperationalReferenceText(
            candidate?.value,
        );

        if (normalized === '') {
            continue;
        }

        if (normalized === needle) {
            best = Math.min(
                best,
                candidate?.exact ? 0 : 1,
            );
            continue;
        }

        if (normalized.startsWith(needle)) {
            best = Math.min(
                best,
                candidate?.exact ? 2 : 3,
            );
            continue;
        }

        if (normalized.includes(needle)) {
            best = Math.min(best, 4);
        }
    }

    return best;
}

export function searchOperationalSnapshotReference(
    reference,
    query,
    limit = 20,
) {
    if (!reference) {
        return [];
    }

    const needle = normalizeOperationalReferenceText(query);
    const safeLimit = Math.max(
        1,
        Math.min(50, Number(limit) || 20),
    );

    return reference.products
        .map((product) => ({
            product,
            score: scoreProduct(product, needle),
        }))
        .filter(({ score }) => Number.isFinite(score))
        .sort((left, right) => {
            if (left.score !== right.score) {
                return left.score - right.score;
            }

            const byName = left.product.name.localeCompare(
                right.product.name,
                'es',
                {
                    sensitivity: 'base',
                },
            );

            if (byName !== 0) {
                return byName;
            }

            return left.product.id - right.product.id;
        })
        .slice(0, safeLimit)
        .map(({ product }) => product);
}

function noReferenceOutcome(state, sourceStatus) {
    return {
        state,
        sourceStatus,
        reference: null,
    };
}

function referenceOutcome(state, sourceStatus, validated) {
    const reference = buildOperationalSnapshotReference(validated);

    if (!reference) {
        return noReferenceOutcome(
            OPERATIONAL_REFERENCE_STATE.NO_VALID_CACHE,
            sourceStatus,
        );
    }

    return {
        state,
        sourceStatus,
        reference,
    };
}

export async function readOperationalSnapshotReference({
    readImpl = readOperationalSnapshot,
    indexedDbFactory = globalThis.indexedDB,
    cryptoImpl = globalThis.crypto,
    nowMs = Date.now(),
} = {}) {
    let validated;

    try {
        validated = await readImpl({
            indexedDbFactory,
            cryptoImpl,
            nowMs,
        });
    } catch {
        validated = null;
    }

    if (!validated) {
        return noReferenceOutcome(
            OPERATIONAL_REFERENCE_STATE.NO_VALID_CACHE,
            'no-cache',
        );
    }

    return referenceOutcome(
        OPERATIONAL_REFERENCE_STATE.OFFLINE_CACHED_VALID,
        'cache-read',
        validated,
    );
}

export async function refreshOperationalSnapshotReference({
    refreshImpl = refreshOperationalSnapshot,
    readImpl = readOperationalSnapshot,
    fetchImpl = globalThis.fetch,
    indexedDbFactory = globalThis.indexedDB,
    cryptoImpl = globalThis.crypto,
    now = new Date(),
} = {}) {
    let refresh;

    try {
        refresh = await refreshImpl({
            fetchImpl,
            indexedDbFactory,
            cryptoImpl,
            now,
        });
    } catch {
        refresh = {
            status: 'client-refresh-error',
        };
    }

    if (AUTHORITY_REJECTION_STATUSES.has(refresh.status)) {
        return noReferenceOutcome(
            OPERATIONAL_REFERENCE_STATE.AUTHORITY_REJECTED,
            refresh.status,
        );
    }

    let validated;

    try {
        validated = await readImpl({
            indexedDbFactory,
            cryptoImpl,
            nowMs: now.getTime(),
        });
    } catch {
        validated = null;
    }

    if (
        refresh.status === 'stored'
        && validated
    ) {
        return referenceOutcome(
            OPERATIONAL_REFERENCE_STATE.ONLINE_REFRESHED,
            refresh.status,
            validated,
        );
    }

    if (validated) {
        return referenceOutcome(
            OPERATIONAL_REFERENCE_STATE.OFFLINE_CACHED_VALID,
            refresh.status,
            validated,
        );
    }

    return noReferenceOutcome(
        OPERATIONAL_REFERENCE_STATE.NO_VALID_CACHE,
        refresh.status ?? 'no-cache',
    );
}

export function formatOperationalReferenceAge(ageMs) {
    const totalSeconds = Math.max(
        0,
        Math.floor(Number(ageMs ?? 0) / 1000),
    );
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor(
        (totalSeconds % 86400) / 3600,
    );
    const minutes = Math.floor(
        (totalSeconds % 3600) / 60,
    );
    const seconds = totalSeconds % 60;
    const parts = [];

    if (days > 0) {
        parts.push(`${days} d`);
    }

    if (hours > 0 || days > 0) {
        parts.push(`${hours} h`);
    }

    if (minutes > 0 || hours > 0 || days > 0) {
        parts.push(`${minutes} min`);
    }

    parts.push(`${seconds} s`);

    return parts.join(' ');
}

export function formatOperationalReferenceTimestamp(value) {
    const epoch = Date.parse(String(value ?? ''));

    if (!Number.isFinite(epoch)) {
        return 'No disponible';
    }

    return new Intl.DateTimeFormat(
        'es-AR',
        {
            dateStyle: 'short',
            timeStyle: 'medium',
        },
    ).format(new Date(epoch));
}

export function formatOperationalReferenceMoney(
    amountMinor,
    currency,
) {
    const amount = Number(amountMinor) / 100;

    if (!Number.isFinite(amount)) {
        return 'No disponible';
    }

    try {
        return new Intl.NumberFormat(
            'es-AR',
            {
                style: 'currency',
                currency: String(currency),
            },
        ).format(amount);
    } catch {
        return `${String(currency)} ${amount.toFixed(2)}`;
    }
}

export function formatOperationalReferenceQuantity(
    value,
    scale,
) {
    const text = String(value ?? '0').trim();
    const safeScale = Math.max(
        0,
        Math.min(6, Number(scale) || 0),
    );
    const negative = text.startsWith('-');
    const unsigned = text.replace(/^[+-]/, '');
    const [integerRaw, fractionRaw = ''] = unsigned.split('.', 2);
    const integer = integerRaw === '' ? '0' : integerRaw;

    if (safeScale === 0) {
        return `${negative ? '-' : ''}${integer}`;
    }

    const fraction = fractionRaw
        .padEnd(safeScale, '0')
        .slice(0, safeScale);

    return `${negative ? '-' : ''}${integer},${fraction}`;
}

const STATE_LABELS = {
    loading: 'Leyendo referencia local',
    [OPERATIONAL_REFERENCE_STATE.ONLINE_REFRESHED]:
        'Actualizada desde servidor',
    [OPERATIONAL_REFERENCE_STATE.OFFLINE_CACHED_VALID]:
        'Cache local valido',
    [OPERATIONAL_REFERENCE_STATE.NO_VALID_CACHE]:
        'Sin cache valido',
    [OPERATIONAL_REFERENCE_STATE.AUTHORITY_REJECTED]:
        'Autoridad rechazada',
};

const SOURCE_LABELS = {
    'cache-read': 'Lectura local validada',
    stored: 'Snapshot validado y actualizado',
    'network-unavailable': 'Red no disponible',
    'server-unavailable': 'Servidor no disponible',
    'authority-unavailable': 'Sesion, organizacion o binding no autorizado',
    'invalid-authoritative-response': 'Respuesta autoritativa invalida',
    'invalid-authoritative-snapshot': 'Snapshot autoritativo invalido',
    'client-refresh-error': 'No se pudo actualizar la referencia',
    'no-cache': 'No existe un cache valido',
};

export function createOperationalSnapshotReferencePanel(
    dependencies = {},
) {
    return {
        query: '',
        state: 'loading',
        sourceStatus: 'not-loaded',
        reference: null,
        results: [],
        busy: false,

        async init() {
            await this.loadCached();
        },

        async loadCached() {
            this.busy = true;

            try {
                this.applyOutcome(
                    await readOperationalSnapshotReference(
                        dependencies,
                    ),
                );
            } finally {
                this.busy = false;
            }
        },

        async refresh() {
            if (this.busy || !this.isOnline()) {
                return;
            }

            this.busy = true;

            try {
                this.applyOutcome(
                    await refreshOperationalSnapshotReference(
                        {
                            ...dependencies,
                            now: new Date(),
                        },
                    ),
                );
            } finally {
                this.busy = false;
            }
        },

        applyOutcome(outcome) {
            this.state = outcome.state;
            this.sourceStatus = outcome.sourceStatus;
            this.reference = outcome.reference;
            this.search();
        },

        search() {
            this.results = searchOperationalSnapshotReference(
                this.reference,
                this.query,
                20,
            );
        },

        isOnline() {
            const navigatorObject = dependencies.navigatorObject
                ?? globalThis.navigator;

            return navigatorObject?.onLine === true;
        },

        stateLabel() {
            return STATE_LABELS[this.state] ?? this.state;
        },

        sourceLabel() {
            return SOURCE_LABELS[this.sourceStatus]
                ?? this.sourceStatus;
        },

        stateClass() {
            if (
                this.state
                === OPERATIONAL_REFERENCE_STATE.ONLINE_REFRESHED
            ) {
                return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200';
            }

            if (
                this.state
                === OPERATIONAL_REFERENCE_STATE.OFFLINE_CACHED_VALID
            ) {
                return 'border-cyan-500/30 bg-cyan-500/10 text-cyan-200';
            }

            if (
                this.state
                === OPERATIONAL_REFERENCE_STATE.AUTHORITY_REJECTED
            ) {
                return 'border-red-500/30 bg-red-500/10 text-red-200';
            }

            return 'border-amber-500/30 bg-amber-500/10 text-amber-100';
        },

        formatAge(value) {
            return formatOperationalReferenceAge(value);
        },

        formatTimestamp(value) {
            return formatOperationalReferenceTimestamp(value);
        },

        formatMoney(amountMinor, currency) {
            return formatOperationalReferenceMoney(
                amountMinor,
                currency,
            );
        },

        formatQuantity(value, scale) {
            return formatOperationalReferenceQuantity(
                value,
                scale,
            );
        },
    };
}
