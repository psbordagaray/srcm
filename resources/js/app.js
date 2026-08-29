import Alpine from 'alpinejs';
import {
    bootstrapOperationalSnapshotPersistence,
} from './offline/operationalReadModelSnapshotStore.js';
import {
    createOperationalSnapshotReferencePanel,
} from './offline/operationalSnapshotReference.js';
import {
    bootstrapRestrictedOfflineTrustedPublicKeyringPersistence,
} from './offline/restrictedOfflineTrustedPublicKeyringStore.js';
import {
    bootstrapRestrictedOfflineAuthorityLifecycle,
} from './offline/restrictedOfflineAuthorityLifecycle.js';

window.Alpine = Alpine;

bootstrapOperationalSnapshotPersistence({
    manageSensitiveNavigation: false,
});
bootstrapRestrictedOfflineTrustedPublicKeyringPersistence();
bootstrapRestrictedOfflineAuthorityLifecycle();

Alpine.data(
    'operationalSnapshotReferencePanel',
    createOperationalSnapshotReferencePanel,
);

Alpine.start();
