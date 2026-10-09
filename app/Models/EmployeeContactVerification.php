<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class EmployeeContactVerification extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected $hidden = ['value', 'code_hash'];

    protected function casts(): array
    {
        return ['value' => 'encrypted', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
