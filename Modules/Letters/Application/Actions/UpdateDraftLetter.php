<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use App\Support\RichText\RichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Modules\Letters\Application\Services\LetterCopies;
use Modules\Letters\Application\Services\LetterEvents;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\LetterDirection;
use Modules\Letters\Domain\Enums\LetterEventType;
use Modules\Letters\Domain\Models\Letter;

/** Edits a draft, its copy recipients included. Only drafts change; everything after submission is a new letter. */
final class UpdateDraftLetter
{
    public function __construct(
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
        private readonly RichTextSanitizer $sanitizer,
        private readonly LetterCopies $copies,
    ) {}

    /** @param array<string, mixed> $data validated ComposeLetterRequest data */
    public function execute(Letter $letter, array $data, User $actor): Letter
    {
        return DB::transaction(function () use ($letter, $data, $actor): Letter {
            $internal = $letter->direction === LetterDirection::Internal;
            $old = $letter->only(['subject', 'recipient_organization_id', 'external_party_name', 'signatory_user_id', 'letter_date', 'classification', 'file_code']);
            $letter->fill([
                'unit_id' => $data['unit_id'] ?? null,
                'recipient_organization_id' => $internal ? $letter->organization_id : ($data['recipient_organization_id'] ?? null),
                'external_party_name' => $internal ? null : ($data['external_party_name'] ?? null),
                'external_party_address' => $internal ? null : ($data['external_party_address'] ?? null),
                'external_party_email' => $internal ? null : ($data['external_party_email'] ?? null),
                'subject' => $data['subject'],
                'summary' => $data['summary'] ?? null,
                'body' => $this->sanitizer->sanitize((string) ($data['body'] ?? '')),
                'language' => $data['language'] ?? $letter->language,
                'priority' => $data['priority'] ?? $letter->priority->value,
                'classification' => $data['classification'] ?? $letter->classification->value,
                'file_code' => isset($data['file_code']) && trim((string) $data['file_code']) !== '' ? mb_substr(trim((string) $data['file_code']), 0, 60) : null,
                'file_class_id' => $data['file_class_id'] ?? null,
                'letterhead_id' => $data['letterhead_id'] ?? null,
                'letter_date' => $data['letter_date'] ?? $letter->letter_date?->toDateString(),
                'template_context' => ComposeLetter::context($data['template_context'] ?? null),
                'signatory_user_id' => $data['signatory_user_id'] ?? null,
                'updated_by' => $actor->id,
            ])->save();
            if (! $internal) {
                $this->copies->sync($letter, $data['copies'] ?? null);
            }

            $this->events->record($letter, LetterEventType::Edited, $actor);
            $this->audit->record(AuditEventType::LetterEdited, $actor, $letter, $letter->organization_id, ['subject' => $letter->subject, 'classification' => $letter->classification->value], null, $old);

            return $letter;
        });
    }
}
