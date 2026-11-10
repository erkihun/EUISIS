<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use App\Support\RichText\RichTextSanitizer;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\TemplateStatus;
use Modules\Letters\Domain\Models\LetterTemplate;

/** Creates or edits a template's draft text. Published versions are untouched until publish. */
final class SaveTemplate
{
    public function __construct(
        private readonly LettersAudit $audit,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    /** @param array<string, mixed> $data validated SaveTemplateRequest data */
    public function execute(?LetterTemplate $template, array $data, User $actor): LetterTemplate
    {
        $owner = $template?->owner_organization_id ?? ($data['owner_organization_id'] ?? null);
        $code = strtoupper(trim((string) $data['code']));
        $duplicate = LetterTemplate::query()->where('code', $code)
            ->where(fn ($q) => $owner === null ? $q->whereNull('owner_organization_id') : $q->where('owner_organization_id', $owner))
            ->when($template !== null, fn ($q) => $q->whereKeyNot($template->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['code' => __('letters.validation.template_code_taken')]);
        }

        $attributes = [
            'code' => $code,
            'title_en' => $data['title_en'],
            'title_am' => $data['title_am'] ?? null,
            'category' => $data['category'] ?? 'general',
            'language' => $data['language'] ?? 'am',
            'draft_subject' => $data['subject'] ?? null,
            'draft_body' => $this->sanitizer->sanitize((string) ($data['body'] ?? '')),
            'draft_placeholders' => self::placeholders($data['placeholders'] ?? null),
            'updated_by' => $actor->id,
        ];

        if ($template === null) {
            $template = LetterTemplate::query()->create([...$attributes, 'owner_organization_id' => $owner, 'status' => TemplateStatus::Draft->value, 'created_by' => $actor->id]);
            $this->audit->record(AuditEventType::LetterTemplateSaved, $actor, $template, $owner, ['code' => $code, 'created' => true]);
        } else {
            $old = $template->only(['code', 'title_en', 'title_am', 'category', 'language']);
            $template->fill($attributes)->save();
            $this->audit->record(AuditEventType::LetterTemplateSaved, $actor, $template, $owner, ['code' => $code], null, $old);
        }

        return $template;
    }

    /** @return list<array{key: string, label_en: string, label_am: string|null, required: bool}> */
    public static function placeholders(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $clean = [];
        $seen = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,59}$/', $key) !== 1 || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = [
                'key' => $key,
                'label_en' => mb_substr(trim((string) ($row['label_en'] ?? $key)), 0, 120) ?: $key,
                'label_am' => isset($row['label_am']) && trim((string) $row['label_am']) !== '' ? mb_substr(trim((string) $row['label_am']), 0, 120) : null,
                'required' => (bool) ($row['required'] ?? false),
            ];
        }

        return $clean;
    }
}
