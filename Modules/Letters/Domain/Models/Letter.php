<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Letters\Domain\Enums\DispatchMode;
use Modules\Letters\Domain\Enums\LetterClassification;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\LetterPriority;
use Modules\Letters\Domain\Enums\LetterStatus;

/**
 * One official letter (ADR 0037). `organization_id` is the institution that
 * owns the document: the sender of an outgoing or internal letter, the
 * receiver of a letter that came from outside the system. Each institution
 * that handles it has a LetterRegistration with its own number.
 */
class Letter extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'letters';

    protected $fillable = [
        'direction', 'status', 'organization_id', 'unit_id', 'sender_organization_id', 'recipient_organization_id',
        'external_party_name', 'external_party_address', 'external_party_email', 'external_reference', 'subject', 'summary', 'body', 'language', 'priority',
        'classification', 'file_code', 'file_class_id', 'letterhead_id', 'letter_date', 'template_version_id', 'template_context', 'signatory_user_id', 'approved_by', 'approved_at',
        'dispatched_by', 'dispatched_at', 'dispatch_mode', 'dispatch_reference', 'dispatch_note', 'verification_code',
        'in_reply_to_letter_id', 'supersedes_letter_id', 'regarding_type', 'regarding_id',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => LetterDirection::class,
            'status' => LetterStatus::class,
            'priority' => LetterPriority::class,
            'classification' => LetterClassification::class,
            'dispatch_mode' => DispatchMode::class,
            'letter_date' => 'date:Y-m-d',
            'template_context' => 'array',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'unit_id');
    }

    public function senderOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'sender_organization_id');
    }

    public function recipientOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'recipient_organization_id');
    }

    public function signatory(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signatory_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(LetterTemplateVersion::class, 'template_version_id');
    }

    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_letter_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_letter_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'in_reply_to_letter_id')->orderBy('created_at');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(LetterRegistration::class)->orderBy('created_at');
    }

    public function fileClass(): BelongsTo
    {
        return $this->belongsTo(LetterFileClass::class, 'file_class_id');
    }

    /** The header-and-footer layout the letter prints on (null: the institution's default). */
    public function letterhead(): BelongsTo
    {
        return $this->belongsTo(LetterLetterhead::class, 'letterhead_id');
    }

    /** Copy recipients (ግልባጭ), in the order written on the letter. */
    public function copies(): HasMany
    {
        return $this->hasMany(LetterRecipient::class)->where('kind', LetterRecipient::KIND_CC)->orderBy('position');
    }

    public function isRestricted(): bool
    {
        return $this->classification->isRestricted();
    }

    public function routings(): HasMany
    {
        return $this->hasMany(LetterRouting::class)->orderBy('step');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LetterAttachment::class)->orderBy('created_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LetterEvent::class)->orderBy('created_at');
    }

    /** The owning institution's registry entry (the sender's for composed letters). */
    public function ownRegistration(): ?LetterRegistration
    {
        return $this->registrations->firstWhere('organization_id', $this->organization_id);
    }

    /** The sending institution's number, once drawn. */
    public function referenceNumber(): ?string
    {
        return $this->ownRegistration()?->registry_number;
    }

    public function isComposed(): bool
    {
        return $this->direction !== LetterDirection::Incoming;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
