import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    STORAGE_SCHEMA_VERSION,
    SNAPSHOT_VERSION,
    buildStorageEnvelope,
    canonicalize,
    fingerprintOperationalSnapshot,
    isPurgeSensitiveNavigation,
    validateOperationalSnapshot,
    validateStorageEnvelope,
} from '../../../resources/js/offline/operationalReadModelSnapshotStore.js';

function baseSnapshot() {
    return {
        snapshot_version: SNAPSHOT_VERSION,
        generated_at: '2026-08-28T22:00:00+00:00',
        scope: {
            binding_public_id:
                '11111111-1111-4111-8111-111111111111',
            device_public_id:
                '22222222-2222-4222-8222-222222222222',
            binding_expires_at:
                '2026-11-26T22:00:00+00:00',
        },
        content_fingerprint: '0'.repeat(64),
        device: {
            public_id:
                '22222222-2222-4222-8222-222222222222',
            label: 'POS 1',
        },
        catalog: [
            {
                id: 1,
                sku: 'ABC',
                name: 'Artículo',
                unit: 'UN',
                scale: 0,
                category: null,
                brand: 'Marca',
                manufacturer: null,
                search_terms: [
                    {
                        value: 'ABC',
                        kind: 'SKU',
                        exact: true,
                    },
                ],
            },
        ],
        locations: [
            {
                id: 1,
                name: 'Principal',
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
                amount_minor: 1000,
                valid_from: null,
                valid_until: null,
            },
        ],
        availability: [
            {
                product_id: 1,
                location_id: 1,
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
    };
}

async function signedSnapshot() {
    const snapshot = baseSnapshot();

    snapshot.content_fingerprint =
        await fingerprintOperationalSnapshot(snapshot);

    return snapshot;
}

test('canonicalize sorts object keys and preserves list order', () => {
    assert.deepEqual(
        canonicalize({
            z: 1,
            a: {
                c: 2,
                b: 1,
            },
            list: [
                {
                    y: 2,
                    x: 1,
                },
                'keep',
            ],
        }),
        {
            a: {
                b: 1,
                c: 2,
            },
            list: [
                {
                    x: 1,
                    y: 2,
                },
                'keep',
            ],
            z: 1,
        },
    );
});

test('snapshot validates with exact content fingerprint', async () => {
    const snapshot = await signedSnapshot();

    const validated = await validateOperationalSnapshot(
        snapshot,
        {
            nowMs: Date.parse('2026-08-29T00:00:00+00:00'),
        },
    );

    assert.equal(
        validated.snapshot.content_fingerprint,
        snapshot.content_fingerprint,
    );
});

test('content tampering fails fingerprint validation', async () => {
    const snapshot = await signedSnapshot();

    snapshot.catalog[0].name = 'Alterado';

    await assert.rejects(
        validateOperationalSnapshot(
            snapshot,
            {
                nowMs: Date.parse(
                    '2026-08-29T00:00:00+00:00',
                ),
            },
        ),
        (error) => error.code === 'content-fingerprint-mismatch',
    );
});

test('expired binding fails closed', async () => {
    const snapshot = await signedSnapshot();

    await assert.rejects(
        validateOperationalSnapshot(
            snapshot,
            {
                nowMs: Date.parse(
                    '2026-11-27T00:00:00+00:00',
                ),
            },
        ),
        (error) => error.code === 'binding-expired',
    );
});

test('scope and device mismatch fails closed', async () => {
    const snapshot = await signedSnapshot();

    snapshot.scope.device_public_id =
        '33333333-3333-4333-8333-333333333333';

    await assert.rejects(
        validateOperationalSnapshot(
            snapshot,
            {
                nowMs: Date.parse(
                    '2026-08-29T00:00:00+00:00',
                ),
            },
        ),
        (error) => error.code === 'scope-device-mismatch',
    );
});

test('weakened offline authority policy fails closed', async () => {
    const snapshot = await signedSnapshot();

    snapshot.policy.offline_final_sale_allowed = true;
    snapshot.content_fingerprint =
        await fingerprintOperationalSnapshot(snapshot);

    await assert.rejects(
        validateOperationalSnapshot(
            snapshot,
            {
                nowMs: Date.parse(
                    '2026-08-29T00:00:00+00:00',
                ),
            },
        ),
        (error) => (
            error.code
            === 'policy-offline_final_sale_allowed-must-be-false'
        ),
    );
});

test('storage envelope is scope-aware and self-consistent', async () => {
    const snapshot = await signedSnapshot();
    const envelope = buildStorageEnvelope(
        snapshot,
        new Date('2026-08-29T00:00:00+00:00'),
    );

    assert.equal(
        envelope.storage_schema_version,
        STORAGE_SCHEMA_VERSION,
    );
    assert.equal(
        envelope.binding_public_id,
        snapshot.scope.binding_public_id,
    );

    const validated = await validateStorageEnvelope(
        envelope,
        {
            nowMs: Date.parse('2026-08-29T01:00:00+00:00'),
            expectedScope: {
                binding_public_id:
                    snapshot.scope.binding_public_id,
                device_public_id:
                    snapshot.scope.device_public_id,
            },
        },
    );

    assert.equal(validated.ageMs, 60 * 60 * 1000);
});

test('storage envelope rejects another binding scope', async () => {
    const snapshot = await signedSnapshot();
    const envelope = buildStorageEnvelope(
        snapshot,
        new Date('2026-08-29T00:00:00+00:00'),
    );

    await assert.rejects(
        validateStorageEnvelope(
            envelope,
            {
                nowMs: Date.parse(
                    '2026-08-29T01:00:00+00:00',
                ),
                expectedScope: {
                    binding_public_id:
                        '44444444-4444-4444-8444-444444444444',
                    device_public_id:
                        snapshot.scope.device_public_id,
                },
            },
        ),
        (error) => error.code === 'expected-scope-mismatch',
    );
});

test('sensitive navigation classification covers purge events', () => {
    assert.equal(
        isPurgeSensitiveNavigation(
            '/logout',
            '',
            'https://srcm.example',
        ),
        true,
    );

    assert.equal(
        isPurgeSensitiveNavigation(
            '/organizations/12/activate',
            '',
            'https://srcm.example',
        ),
        true,
    );

    assert.equal(
        isPurgeSensitiveNavigation(
            '/operational-device/browser-binding',
            'DELETE',
            'https://srcm.example',
        ),
        true,
    );

    assert.equal(
        isPurgeSensitiveNavigation(
            '/commerce-sales',
            'POST',
            'https://srcm.example',
        ),
        false,
    );
});
