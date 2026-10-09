<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCorrectionRequest extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected $hidden = ['requested_value'];

    protected function casts(): array
    {
        return ['requested_value' => 'encrypted', 'reviewed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
