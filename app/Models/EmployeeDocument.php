<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'document_type',
        'file_path',
        'storage_disk',
        'is_private',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'bool',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
