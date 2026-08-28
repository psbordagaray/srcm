import Alpine from 'alpinejs';
import {
    bootstrapOperationalSnapshotPersistence,
} from './offline/operationalReadModelSnapshotStore.js';

window.Alpine = Alpine;

bootstrapOperationalSnapshotPersistence();

Alpine.start();
