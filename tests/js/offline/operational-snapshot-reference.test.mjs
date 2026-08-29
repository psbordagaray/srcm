import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    OPERATIONAL_REFERENCE_STATE,
    buildOperationalSnapshotReference,
    createOperationalSnapshotReferencePanel,
    formatOperationalReferenceAge,
    normalizeOperationalReferenceText,
    readOperationalSnapshotReference,
    refreshOperationalSnapshotReference,
    searchOperationalSnapshotReference,
} from '../../../resources/js/offline/operationalSnapshotReference.js';

function validatedSnapshot() {
    return {
        snapshot: {
            snapshot_version: 2,
            generated_at: '2026-08-28T22:00:00+00:00',
            scope: {
                binding_public_id:
                    '11111111-1111-4111-8111-111111111111',
                device_public_id:
                    '22222222-2222-4222-8222-222222222222',
                binding_expires_at:
                    '2026-11-26T22:00:00+00:00',
            },
            content_fingerprint: 'a'.repeat(64),
            device: {
                public_id:
                    '22222222-2222-4222-8222-222222222222',
                label: 'POS 1',
            },
            catalog: [
                {
                    id: 1,
                    sku: 'CAF-001',
                    name: 'Cafetera Elite',
                    unit: 'UN',
                    scale: 0,
                    category: 'Electro',
                    brand: 'Marca Uno',
                    manufacturer: null,
                    search_terms: [
                        {
                            value: '7790001112223',
                            kind: 'EAN',
                            exact: true,
                        },
                        {
                            value: 'Cafetera Elite',
                            kind: 'Articulo',
                            exact: false,
                        },
                    ],
                },
                {
                    id: 2,
                    sku: 'TV-002',
                    name: 'Televisor',
                    unit: 'UN',
                    scale: 0,
                    category: 'TV',
                    brand: 'Marca Dos',
                    manufacturer: null,
                    search_terms: [],
                },
            ],
            locations: [
                {
                    id: 10,
                    name: 'Salon',
                },
            ],
            conditions: [
                {
                    value: 'new',
                    label: 'Nuevo',
                },
            ],
            prices: [
                {
                    product_id: 1,
                    currency: 'ARS',
                    amount_minor: 123450,
                    valid_from: null,
                    valid_until: null,
                },
            ],
            availability: [
                {
                    product_id: 1,
                    location_id: 10,
                    condition: 'new',
                    available_quantity: '2',
                    balance_version: 7,
                },
            ],
            policy: {
                server_authoritative_at_confirmation: true,
                price_revalidation_required_at_confirmation: true,
                availability_revalidation_required_at_confirmation: true,
                offline_final_sale_allowed: false,
                offline_payment_finalization_allowed: false,
                offline_fiscal_authorization_allowed: false,
                silent_price_or_stock_conflict_merge_allowed: false,
                contains_customer_data: false,
                contains_customer_credit_data: false,
                contains_cash_session_data: false,
                contains_financial_account_data: false,
                contains_payment_data: false,
                contains_fiscal_credentials: false,
            },
        },
        storedAtMs: Date.parse('2026-08-28T22:05:00+00:00'),
        ageMs: 65_000,
        bindingExpiresAtMs:
            Date.parse('2026-11-26T22:00:00+00:00'),
    };
}

test('reference joins catalog price availability location and condition', () => {
    const reference = buildOperationalSnapshotReference(
        validatedSnapshot(),
    );
    const product = reference.products[0];

    assert.equal(product.name, 'Cafetera Elite');
    assert.equal(product.prices[0].amountMinor, 123450);
    assert.equal(product.availability[0].location, 'Salon');
    assert.equal(product.availability[0].conditionLabel, 'Nuevo');
    assert.equal(product.availability[0].balanceVersion, 7);
    assert.equal(reference.deviceLabel, 'POS 1');
});

test('search is accent-insensitive and exact identifier ranks first', () => {
    const reference = buildOperationalSnapshotReference(
        validatedSnapshot(),
    );

    assert.equal(
        normalizeOperationalReferenceText('  CAFE  Elite '),
        'cafe elite',
    );

    assert.equal(
        searchOperationalSnapshotReference(
            reference,
            'cafetera elite',
        )[0].id,
        1,
    );

    assert.equal(
        searchOperationalSnapshotReference(
            reference,
            '7790001112223',
        )[0].id,
        1,
    );
});

test('offline read classifies a valid cache without inventing freshness', async () => {
    const result = await readOperationalSnapshotReference({
        readImpl: async () => validatedSnapshot(),
        nowMs: Date.parse('2026-08-28T22:06:05+00:00'),
    });

    assert.equal(
        result.state,
        OPERATIONAL_REFERENCE_STATE.OFFLINE_CACHED_VALID,
    );
    assert.equal(result.sourceStatus, 'cache-read');
    assert.equal(result.reference.ageMs, 65_000);
});

test('missing cache fails closed as no valid cache', async () => {
    const result = await readOperationalSnapshotReference({
        readImpl: async () => null,
    });

    assert.equal(
        result.state,
        OPERATIONAL_REFERENCE_STATE.NO_VALID_CACHE,
    );
    assert.equal(result.reference, null);
});

test('authoritative refresh is online-refreshed only after persisted reread', async () => {
    let readCount = 0;

    const result = await refreshOperationalSnapshotReference({
        refreshImpl: async () => ({
            status: 'stored',
        }),
        readImpl: async () => {
            readCount += 1;
            return validatedSnapshot();
        },
        now: new Date('2026-08-28T22:06:05+00:00'),
    });

    assert.equal(readCount, 1);
    assert.equal(
        result.state,
        OPERATIONAL_REFERENCE_STATE.ONLINE_REFRESHED,
    );
    assert.equal(result.sourceStatus, 'stored');
});

test('authority rejection never exposes cached reference', async () => {
    const result = await refreshOperationalSnapshotReference({
        refreshImpl: async () => ({
            status: 'authority-unavailable',
        }),
        readImpl: async () => validatedSnapshot(),
        now: new Date('2026-08-28T22:06:05+00:00'),
    });

    assert.equal(
        result.state,
        OPERATIONAL_REFERENCE_STATE.AUTHORITY_REJECTED,
    );
    assert.equal(result.reference, null);
});

test('server outage may preserve a previously validated cache', async () => {
    const result = await refreshOperationalSnapshotReference({
        refreshImpl: async () => ({
            status: 'server-unavailable',
        }),
        readImpl: async () => validatedSnapshot(),
        now: new Date('2026-08-28T22:06:05+00:00'),
    });

    assert.equal(
        result.state,
        OPERATIONAL_REFERENCE_STATE.OFFLINE_CACHED_VALID,
    );
    assert.equal(result.sourceStatus, 'server-unavailable');
});

test('panel stays read-only and searches the local reference', async () => {
    const panel = createOperationalSnapshotReferencePanel({
        readImpl: async () => validatedSnapshot(),
        navigatorObject: {
            onLine: false,
        },
    });

    await panel.init();

    assert.equal(
        panel.state,
        OPERATIONAL_REFERENCE_STATE.OFFLINE_CACHED_VALID,
    );

    panel.query = 'CAF-001';
    panel.search();

    assert.equal(panel.results.length, 1);
    assert.equal(panel.results[0].id, 1);
    assert.equal(panel.isOnline(), false);
});

test('operator age is displayed as duration without freshness threshold', () => {
    assert.equal(
        formatOperationalReferenceAge(
            ((1 * 60 + 2) * 60 + 3) * 1000,
        ),
        '1 h 2 min 3 s',
    );
});
