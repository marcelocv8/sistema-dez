<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['entity_type', 'entity_id', 'entity_key', 'action', 'actor_type', 'actor_id', 'ip_address', 'before', 'after', 'metadata', 'created_at'])]
class AuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'before' => 'array', 'after' => 'array', 'metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
