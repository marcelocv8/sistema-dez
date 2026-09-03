<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditRecorder
{
    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function record(Request $request, Model $entity, string $action, ?array $before, ?array $after): AuditLog
    {
        $entityKey = (string) $entity->getKey();

        return AuditLog::create([
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => Str::isUuid($entityKey) ? $entityKey : null,
            'entity_key' => $entityKey,
            'action' => $action,
            'actor_type' => $request->user()?->getMorphClass(),
            'actor_id' => $request->user()?->getAuthIdentifier(),
            'ip_address' => $request->ip(),
            'before' => $before,
            'after' => $after,
            'metadata' => ['route' => $request->route()?->getName()],
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function recordSystem(Model $entity, string $action, ?array $before, ?array $after): AuditLog
    {
        $entityKey = (string) $entity->getKey();

        return AuditLog::create([
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => Str::isUuid($entityKey) ? $entityKey : null,
            'entity_key' => $entityKey,
            'action' => $action,
            'actor_type' => null,
            'actor_id' => null,
            'ip_address' => null,
            'before' => $before,
            'after' => $after,
            'metadata' => ['route' => null, 'origin' => 'system'],
            'created_at' => now(),
        ]);
    }
}
