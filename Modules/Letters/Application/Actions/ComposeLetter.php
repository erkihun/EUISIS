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
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterTemplateVersion;

/**
 * Starts an outgoing or internal letter as a draft, optionally from a
 * published template version whose subject and body (with their placeholders)
 * become the draft's text, with its copy recipients. Numbers are drawn at
 * dispatch, not here.
 */
final class ComposeLetter
{
    public function __construct(
        private readonly LetterEvents $events,
        private readonly LettersAudit $audit,
        private readonly RichTextSanitizer $sanitizer,
        private readonly LetterCopies $copies,
    ) {}

    /** @param array<string, mixed> $data validated ComposeLetterRequest data */
    public function execute(array $data, ?User $actor, ?string $regardingType = null, ?string $regardingId = null): Letter
    {
        return DB::transaction(function () use ($data, $actor, $regardingType, $regardingId): Letter {
            $version = isset($data['template_version_id']) ? LetterTemplateVersion::query()->find($data['template_version_id']) : null;
            $direction = LetterDirection::from((string) ($data['direction'] ?? 'outgoing'));
            $internal = $direction === LetterDirection::Internal;

            $letter = Letter::query()->create([
                'direction' => $direction->value,
                'status' => LetterStatus::Draft->value,
                'organization_id' => $data['organization_id'],
                'unit_id' => $data['unit_id'] ?? null,
                'recipient_organization_id' => $internal ? $data['organization_id'] : ($data['recipient_organization_id'] ?? null),
                'external_party_name' => $internal ? null : ($data['external_party_name'] ?? null),
                'external_party_address' => $internal ? null : ($data['external_party_address'] ?? null),
                'external_party_email' => $internal ? null : ($data['external_party_email'] ?? null),
                'subject' => $data['subject'] ?? $version?->subject ?? '',
                'summary' => $data['summary'] ?? null,
                'body' => $this->sanitizer->sanitize((string) ($data['body'] ?? $version?->body ?? '')),
                'language' => $data['language'] ?? $version?->template?->language ?? 'am',
                'priority' => $data['priority'] ?? 'normal',
                'classification' => $data['classification'] ?? 'internal',
                'file_code' => isset($data['file_code']) && trim((string) $data['file_code']) !== '' ? mb_substr(trim((string) $data['file_code']), 0, 60) : null,
                'file_class_id' => $data['file_class_id'] ?? null,
                'letterhead_id' => $data['letterhead_id'] ?? null,
                'letter_date' => $data['letter_date'] ?? now()->toDateString(),
                'template_version_id' => $version?->id,
                'template_context' => $this->context($data['template_context'] ?? null),
                'signatory_user_id' => $data['signatory_user_id'] ?? null,
                'in_reply_to_letter_id' => $data['in_reply_to_letter_id'] ?? null,
                'supersedes_letter_id' => $data['supersedes_letter_id'] ?? null,
                'regarding_type' => $regardingType,
                'regarding_id' => $regardingId,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);
            if (! $internal) {
                $this->copies->sync($letter, $data['copies'] ?? null);
            }

            $this->events->record($letter, LetterEventType::Registered, $actor, ['direction' => $direction->value, 'template_version_id' => $version?->id]);
            if ($letter->in_reply_to_letter_id !== null) {
                $this->events->record($letter->inReplyTo, LetterEventType::ReplyCreated, $actor, ['reply_id' => $letter->id]);
            }
            $this->audit->record(AuditEventType::LetterRegistered, $actor, $letter, $letter->organization_id, [
                'direction' => $direction->value, 'subject' => $letter->subject, 'classification' => $letter->classification->value, 'template_version_id' => $version?->id,
                'regarding' => $regardingType !== null ? "{$regardingType}:{$regardingId}" : null,
            ]);

            return $letter;
        });
    }

    /** @return array<string, string>|null */
    public static function context(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $clean = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && preg_match('/^[a-zA-Z0-9_]{1,60}$/', $key) === 1 && (is_scalar($value) || $value === null)) {
                $clean[$key] = mb_substr((string) ($value ?? ''), 0, 2000);
            }
        }

        return $clean === [] ? null : $clean;
    }
}
