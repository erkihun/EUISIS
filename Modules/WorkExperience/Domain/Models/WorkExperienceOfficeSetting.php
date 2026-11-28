<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per institution: who signs the work experience certificate and what it carries. */
class WorkExperienceOfficeSetting extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'work_experience_office_settings';

    protected $fillable = [
        'organization_id', 'signatory_name_am', 'signatory_name_en', 'signatory_title_am', 'signatory_title_en',
        'include_salary', 'include_external', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['include_salary' => 'bool', 'include_external' => 'bool'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
