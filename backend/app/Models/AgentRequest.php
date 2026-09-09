<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_token_id', 'endpoint', 'method', 'status_code', 'duration_ms'])]
class AgentRequest extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function agentToken(): BelongsTo
    {
        return $this->belongsTo(AgentToken::class, 'agent_token_id');
    }
}
