import {
    isPurgeSensitiveNavigation,
    purgeOperationalSnapshotStore,
} from './operationalReadModelSnapshotStore.js';
import {
    purgeTrustedPublicKeyringStore,
} from './restrictedOfflineTrustedPublicKeyringStore.js';

export async function purgeRestrictedOfflineAuthorityStores(
    {
        indexedDbFactory = globalThis.indexedDB,
        snapshotPurge = purgeOperationalSnapshotStore,
        trustPurge = purgeTrustedPublicKeyringStore,
    } = {},
) {
    await Promise.allSettled([
        snapshotPurge(indexedDbFactory),
        trustPurge(indexedDbFactory),
    ]);
}

export function bootstrapRestrictedOfflineAuthorityLifecycle(
    {
        documentObject = globalThis.document,
        indexedDbFactory = globalThis.indexedDB,
        purgeImpl = purgeRestrictedOfflineAuthorityStores,
    } = {},
) {
    if (!documentObject || !indexedDbFactory) {
        return;
    }

    documentObject.addEventListener(
        'submit',
        (event) => {
            const form = event.target;
            if (!form || typeof form.action !== 'string') {
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

            void purgeImpl({ indexedDbFactory }).finally(() => {
                if (
                    submitter
                    && submitter.name
                    && documentObject.createElement
                ) {
                    const input = documentObject.createElement('input');
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
