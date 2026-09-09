<?php

namespace App\Models;

use Database\Factories\AgentTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'token_hash', 'scopes', 'active', 'revoked_at', 'created_by'])]
class AgentToken extends Model
{
    /** @use HasFactory<AgentTokenFactory> */
    use HasFactory, HasUuids;

    public const SCOPES = ['agenda.read', 'patients.read', 'finance.read', 'mt.read'];

    /** @var list<string> */
    private const AllowedScopes = self::SCOPES;

    protected function casts(): array
    {
        return ['scopes' => 'array', 'active' => 'boolean', 'revoked_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime'];
    }

    /** @param list<string> $scopes */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /** @return list<string> */
    public static function allowedScopes(): array
    {
        return self::AllowedScopes;
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AgentRequest::class, 'agent_token_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
