<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceLetterLanguage;
use App\Enums\Grievance\GrievanceLetterType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Official letter template. Bodies use a fixed whitelist of {{tokens}}; nothing in a template is ever executed.
 */
class GrievanceLetterTemplate extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_letter_templates';

    protected $fillable = [
        'organization_id',
        'template_type',
        'language',
        'name',
        'subject_template',
        'body_template',
        'header_config',
        'footer_config',
        'signature_config',
        'seal_config',
        'effective_from',
        'effective_to',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'template_type' => GrievanceLetterType::class,
            'language' => GrievanceLetterLanguage::class,
            'header_config' => 'array',
            'footer_config' => 'array',
            'signature_config' => 'array',
            'seal_config' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'bool',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
