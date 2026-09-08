<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'updated_by', 'updated_at'])]
class AppSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['updated_at' => 'immutable_datetime'];
    }

    public static function set(string $key, string $value, ?int $userId): void
    {
        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $userId, 'updated_at' => now()],
        );
    }
}
