<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacancyApplicationDocument extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'vacancy_application_id',
        'document_type',
        'original_filename',
        'disk',
        'path',
        'size_bytes',
        'mime_type',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(VacancyApplication::class, 'vacancy_application_id');
    }
}
