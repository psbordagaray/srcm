import Alpine from 'alpinejs';
import {
    bootstrapOperationalSnapshotPersistence,
} from './offline/operationalReadModelSnapshotStore.js';
import {
    createOperationalSnapshotReferencePanel,
} from './offline/operationalSnapshotReference.js';

window.Alpine = Alpine;

bootstrapOperationalSnapshotPersistence();

Alpine.data(
    'operationalSnapshotReferencePanel',
    createOperationalSnapshotReferencePanel,
);

Alpine.start();
