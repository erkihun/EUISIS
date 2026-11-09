<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class IdCardPrintSnapshot extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected $hidden = ['rendered_values', 'comparison_values', 'artifact_path'];

    protected function casts(): array
    {
        return ['rendered_fields' => 'array', 'rendered_values' => 'encrypted:array', 'comparison_values' => 'encrypted:array', 'printed_at' => 'datetime'];
    }
}
