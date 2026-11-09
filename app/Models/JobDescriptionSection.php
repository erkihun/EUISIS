<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One section of a job description: derived (regenerated from the masters) or custom (written). */
class JobDescriptionSection extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['job_description_id', 'section_key', 'source', 'sequence', 'title_en', 'title_am', 'content_en', 'content_am', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sequence' => 'integer'];
    }

    public function jobDescription(): BelongsTo
    {
        return $this->belongsTo(JobDescription::class);
    }

    public function isCustom(): bool
    {
        return $this->source === 'custom';
    }
}
