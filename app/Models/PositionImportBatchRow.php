<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionImportBatchRow extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_IMPORTED = 'imported';

    protected $fillable = [
        'batch_id',
        'row_number',
        'row_data',
        'status',
        'errors',
        'position_id',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'row_data' => 'array',
            'errors' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PositionImportBatch::class, 'batch_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
