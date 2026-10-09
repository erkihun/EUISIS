<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceLetterLanguage;
use App\Enums\Grievance\GrievanceLetterStatus;
use App\Enums\Grievance\GrievanceLetterType;
use App\Enums\Grievance\GrievanceSignatureMethod;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An official grievance letter. Once issued it is immutable: the model itself
 * refuses to change an issued letter's content, so a correction must void it
 * and issue a new version (supersedes_letter_id).
 */
class GrievanceLetter extends Model
{
    use HasUuidPrimaryKey;

    /** Columns that may still change after issue (voiding and delivery bookkeeping). */
    private const MUTABLE_AFTER_ISSUE = ['status', 'voided_by', 'voided_at', 'void_reason', 'updated_at'];

    protected $fillable = [
        'grievance_id',
        'case_stage_id',
        'decision_id',
        'hearing_id',
        'information_request_id',
        'appeal_id',
        'template_id',
        'organization_id',
        'letter_type',
        'language',
        'reference_number',
        'subject',
        'body',
        'letter_date',
        'status',
        'signatory_employee_id',
        'signatory_position_id',
        'signatory_user_id',
        'signature_method',
        'signed_at',
        'seal_id',
        'seal_applied_by',
        'seal_applied_at',
        'pdf_disk',
        'pdf_path',
        'pdf_sha256',
        'generated_at',
        'visible_to_complainant',
        'issued_by',
        'issued_at',
        'voided_by',
        'voided_at',
        'void_reason',
        'supersedes_letter_id',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $letter): void {
            $originalStatus = $letter->getOriginal('status');
            $originalStatus = $originalStatus instanceof GrievanceLetterStatus ? $originalStatus->value : $originalStatus;
            if (! in_array($originalStatus, [GrievanceLetterStatus::Issued->value, GrievanceLetterStatus::Voided->value], true)) {
                return;
            }

            $changed = array_diff(array_keys($letter->getDirty()), self::MUTABLE_AFTER_ISSUE);
            if ($changed !== [] || ($originalStatus === GrievanceLetterStatus::Voided->value && $letter->isDirty('status'))) {
                throw new LogicException('An issued grievance letter is immutable; void it and issue a new version.');
            }
        });

        static::deleting(function (self $letter): void {
            if ($letter->status !== GrievanceLetterStatus::Draft) {
                throw new LogicException('Only a draft grievance letter can be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'letter_type' => GrievanceLetterType::class,
            'language' => GrievanceLetterLanguage::class,
            'status' => GrievanceLetterStatus::class,
            'signature_method' => GrievanceSignatureMethod::class,
            'letter_date' => 'date',
            'signed_at' => 'datetime',
            'seal_applied_at' => 'datetime',
            'generated_at' => 'datetime',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'visible_to_complainant' => 'bool',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(GrievanceDecision::class, 'decision_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(GrievanceLetterTemplate::class, 'template_id');
    }

    public function signatoryEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'signatory_employee_id');
    }

    public function signatoryPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'signatory_position_id');
    }

    public function seal(): BelongsTo
    {
        return $this->belongsTo(OrganizationSeal::class, 'seal_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_letter_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(GrievanceLetterRecipient::class, 'letter_id')->orderBy('sort_order');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(GrievanceLetterAttachment::class, 'letter_id')->orderBy('sort_order');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(GrievanceLetterDispatch::class, 'letter_id');
    }

    public function isIssued(): bool
    {
        return $this->status === GrievanceLetterStatus::Issued;
    }
}
