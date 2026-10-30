<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Database\Factories\ServiceMonitoring\SubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\ServiceMonitoring\Domain\Enums\FormType;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;

/** One revision of one participant's quarterly report (D9). Only the accepted revision counts; is_current marks the working revision. Status and attestation are never mass-assigned from a request: the Actions set them. */
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_submissions';

    protected $fillable = [
        'period_id',
        'participant_id',
        'organization_id',
        'level',
        'form_type',
        'form_version',
        'revision_no',
        'is_current',
        'status',
        'extension_id',
        'submitted_late',
        'supersedes_submission_id',
        'section_1_nothing',
        'section_2_nothing',
        'section_3_nothing',
        'section_4_nothing',
        'special_support_narrative',
        'attested_by',
        'attested_at',
        'attester_name_snapshot',
        'attester_title_snapshot',
        'attester_organization_snapshot',
        'submitted_at',
        'submitted_by',
        'reviewed_at',
        'reviewed_by',
        'accepted_at',
        'accepted_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'level' => ReportingLevel::class,
            'form_type' => FormType::class,
            'status' => SubmissionStatus::class,
            'revision_no' => 'integer',
            'is_current' => 'boolean',
            'submitted_late' => 'boolean',
            'section_1_nothing' => 'boolean',
            'section_2_nothing' => 'boolean',
            'section_3_nothing' => 'boolean',
            'section_4_nothing' => 'boolean',
            'attested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SubmissionFactory
    {
        return SubmissionFactory::new();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'period_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(DeadlineExtension::class, 'extension_id');
    }

    public function accountabilityEntries(): HasMany
    {
        return $this->hasMany(AccountabilityEntry::class)->orderBy('sequence');
    }

    public function institutionBuilding(): HasOne
    {
        return $this->hasOne(InstitutionBuilding::class);
    }

    public function prefillValues(): HasMany
    {
        return $this->hasMany(PrefillValue::class);
    }

    public function digitalServices(): HasMany
    {
        return $this->hasMany(DigitalService::class)->orderBy('sequence');
    }

    public function serviceTotals(): HasOne
    {
        return $this->hasOne(ServiceTotals::class);
    }

    public function standardPerformance(): HasOne
    {
        return $this->hasOne(StandardPerformance::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SubmissionHistory::class)->orderBy('created_at');
    }

    public function reviewComments(): HasMany
    {
        return $this->hasMany(ReviewComment::class)->orderBy('created_at');
    }

    public function correctionRequests(): HasMany
    {
        return $this->hasMany(CorrectionRequest::class)->latest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MonitoringAttachment::class, 'attachable_id')->where('attachable_type', MonitoringAttachment::TYPE_SUBMISSION);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function isEditable(): bool
    {
        return $this->is_current && $this->status->isEditable();
    }

    public function nothingToReport(int $section): bool
    {
        return (bool) $this->getAttribute("section_{$section}_nothing");
    }
}
