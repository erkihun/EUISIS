<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceConfidentiality;
use App\Enums\Grievance\GrievanceEvidenceStatus;
use App\Enums\Grievance\GrievanceEvidenceType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of evidence on the private disk. Accepted evidence is never replaced in place: a new version supersedes it and both are kept.
 */
class GrievanceEvidence extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_evidence';

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'information_request_id',
        'information_response_id',
        'appeal_id',
        'evidence_type',
        'title',
        'description',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'classification',
        'status',
        'scan_status',
        'version_no',
        'supersedes_evidence_id',
        'submitted_by_complainant',
        'submitted_by',
        'submitted_by_employee_id',
        'submitted_at',
        'accepted_by',
        'accepted_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'evidence_type' => GrievanceEvidenceType::class,
            'status' => GrievanceEvidenceStatus::class,
            'classification' => GrievanceConfidentiality::class,
            'submitted_by_complainant' => 'bool',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'size_bytes' => 'int',
            'version_no' => 'int',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(GrievanceCaseStage::class, 'case_stage_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function custody(): HasMany
    {
        return $this->hasMany(GrievanceEvidenceCustody::class, 'evidence_id');
    }
}
