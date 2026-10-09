<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Assessment\FormVersionStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An assessment form. Its content lives in versions; assessments use a
 * published version, never the form itself.
 */
class AssessmentForm extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_forms';

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'description_en',
        'description_am',
        'assessment_type_id',
        'organization_id',
        'status',
        'current_version_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [

        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class, 'assessment_type_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AssessmentFormVersion::class, 'form_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AssessmentFormVersion::class, 'current_version_id');
    }

    public function draftVersion(): ?AssessmentFormVersion
    {
        return $this->versions()->where('status', FormVersionStatus::Draft->value)->latest('version_no')->first();
    }
}
