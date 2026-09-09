<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendanceEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: cada login gera um evento; nunca se apaga ou edita.
 */
#[Fillable(['user_id', 'service_date', 'logged_in_at'])]
class AttendanceEvent extends Model
{
    /** @use HasFactory<AttendanceEventFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['service_date' => 'immutable_date:Y-m-d', 'logged_in_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(User $user): self
    {
        $timezone = (string) config('sistema_dez.timezone');

        return self::query()->create([
            'user_id' => $user->id,
            'service_date' => CarbonImmutable::now($timezone)->toDateString(),
            'logged_in_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Horário de saída configurado pelo Admin (padrão 18:00). */
    public static function exitHour(): string
    {
        $setting = AppSetting::query()->find('attendance.exit_hour');

        return $setting?->value ?? '18:00';
    }
}
