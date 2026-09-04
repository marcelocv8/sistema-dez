<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'extra_percent', 'active'])]
class PaymentMethod extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['extra_percent' => 'decimal:2', 'active' => 'boolean'];
    }
}
