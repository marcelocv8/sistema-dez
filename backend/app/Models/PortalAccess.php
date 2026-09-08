<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Fillable(['patient_id', 'login', 'password_hash', 'token_hash', 'expires_at', 'revoked_at', 'revoked_reason', 'created_by'])]
class PortalAccess extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'access_count' => 'integer', 'last_accessed_at' => 'immutable_datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return array{access: PortalAccess, password: string} */
    public static function issue(Patient $patient, int $ttlDays, int $createdBy): array
    {
        $password = strtoupper(Str::random(8));
        $access = self::query()->create([
            'patient_id' => $patient->id,
            'login' => strtolower(Str::random(8)),
            'password_hash' => Hash::make($password),
            'expires_at' => now()->addDays($ttlDays),
            'created_by' => $createdBy,
        ]);

        return ['access' => $access, 'password' => $password];
    }

    public function issueToken(): string
    {
        $token = Str::random(60);
        $this->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function matchesPassword(string $password): bool
    {
        return Hash::check($password, $this->password_hash);
    }
}
