<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditRecorder
{
    public function record(
        Model $model,
        string $event,
        ?array $oldValues,
        ?array $newValues
    ): AuditLog {
        $request = app()->bound('request')
            ? app('request')
            : null;

        $hasHttpContext = $request instanceof Request
            && $request->route() !== null;

        $user = auth()->user();

        return AuditLog::query()->create([
            'request_id' => $hasHttpContext
                ? $request->attributes->get('request_id')
                : null,

            'user_id' => $user?->getAuthIdentifier(),
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'actor_role' => $user?->role?->value,

            'event' => $event,
            'auditable_type' => $model::class,
            'auditable_id' => (string) $model->getKey(),

            'old_values' => $this->normalize($oldValues),
            'new_values' => $this->normalize($newValues),

            'ip_address' => $hasHttpContext
                ? $request->ip()
                : null,

            'user_agent' => $hasHttpContext
                ? $request->userAgent()
                : null,

            'route_name' => $hasHttpContext
                ? $request->route()?->getName()
                : null,

            'http_method' => $hasHttpContext
                ? $request->method()
                : null,

            'url_path' => $hasHttpContext
                ? $request->getPathInfo()
                : null,
        ]);
    }

    private function normalize(?array $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                default => $value,
            };
        }

        ksort($values);

        return $values;
    }
}
