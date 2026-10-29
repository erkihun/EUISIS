<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationDesignStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The legal authority under which an organization exists and operates.
 * Versioned: rows sharing a `lineage_key` are versions of one mandate, and
 * exactly one of them is in force on any date.
 */
class InstitutionMandate extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'lineage_key', 'version_no', 'code',
        'title_en', 'title_am', 'description_en', 'description_am',
        'legal_instrument_type_id', 'legal_instrument_number', 'legal_reference', 'article_reference', 'issuing_authority',
        'legal_source_id',
        'effective_from', 'effective_to', 'status', 'approved_by', 'approved_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'status' => OrganizationDesignStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The legal source register entry this mandate rests on, when one is recorded. */
    public function legalSource(): BelongsTo
    {
        return $this->belongsTo(LegalSource::class, 'legal_source_id');
    }

    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(LegalInstrumentType::class, 'legal_instrument_type_id');
    }

    public function powers(): HasMany
    {
        return $this->hasMany(OrganizationPower::class, 'mandate_id')->orderBy('sequence');
    }

    public function functions(): HasMany
    {
        return $this->hasMany(OrganizationFunction::class, 'mandate_id')->orderBy('sequence');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Every version of this mandate, oldest first. */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'lineage_key', 'lineage_key')->orderBy('version_no');
    }
}
