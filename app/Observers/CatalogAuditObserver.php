<?php

namespace App\Observers;

use App\Domain\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;

class CatalogAuditObserver
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder
    ) {
    }

    public function created(Model $model): void
    {
        $this->auditRecorder->record(
            $model,
            'created',
            null,
            $this->snapshot($model)
        );
    }

    public function updated(Model $model): void
    {
        $changedKeys = array_values(
            array_diff(
                array_keys($model->getChanges()),
                ['updated_at']
            )
        );

        if ($changedKeys === []) {
            return;
        }

        $oldValues = [];
        $newValues = [];

        foreach ($changedKeys as $key) {
            $oldValues[$key] = $model->getOriginal($key);
            $newValues[$key] = $model->getAttribute($key);
        }

        $this->auditRecorder->record(
            $model,
            $this->resolveEvent($newValues),
            $oldValues,
            $newValues
        );
    }

    private function resolveEvent(array $newValues): string
    {
        if (array_keys($newValues) !== ['active']) {
            return 'updated';
        }

        return (bool) $newValues['active']
            ? 'activated'
            : 'deactivated';
    }

    private function snapshot(Model $model): array
    {
        $snapshot = [];

        foreach ($model->getAttributes() as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at'], true)) {
                continue;
            }

            $snapshot[$key] = $model->getAttribute($key);
        }

        return $snapshot;
    }
}
