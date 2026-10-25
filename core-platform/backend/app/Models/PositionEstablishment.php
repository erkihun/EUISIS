<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PositionEstablishment extends Model
{
    use HasUuids, SoftDeletes;

    public const APPROVED = 'approved';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approved_slots' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date'];
    }
}
