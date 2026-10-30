<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrievanceDecisionLetter extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'grievance_id',
        'response_id',
        'letter_reference',
        'file_path',
        'generated_by_user_id',
        'generated_at',
        'downloaded_at',
        'downloaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(GrievanceResponse::class, 'response_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function downloadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'downloaded_by_user_id');
    }

    public function getDownloadUrlAttribute(): ?string
    {
        return $this->file_path ? '/storage/'.$this->file_path : null;
    }
}
