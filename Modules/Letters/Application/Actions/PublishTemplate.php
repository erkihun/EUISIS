<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Application\Services\TemplateRenderer;
use Modules\Letters\Domain\Enums\TemplateStatus;
use Modules\Letters\Domain\Models\LetterTemplate;
use Modules\Letters\Domain\Models\LetterTemplateVersion;

/**
 * Freezes the draft text as the next numbered version and offers the template.
 * Every custom placeholder the text uses must be declared, so composers are
 * asked for every value the letter needs.
 */
final class PublishTemplate
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly LettersAudit $audit,
    ) {}

    public function execute(LetterTemplate $template, ?string $changeNote, User $actor): LetterTemplateVersion
    {
        $subject = trim((string) $template->draft_subject);
        $body = (string) $template->draft_body;
        if ($subject === '' || trim(strip_tags($body)) === '') {
            throw ValidationException::withMessages(['body' => __('letters.validation.template_text_required')]);
        }
        $declared = array_column((array) ($template->draft_placeholders ?? []), 'key');
        $undeclared = array_values(array_filter(
            [...$this->renderer->keysIn($subject), ...$this->renderer->keysIn($body)],
            static fn (string $key): bool => ! in_array($key, TemplateRenderer::BUILT_IN, true) && ! in_array($key, $declared, true),
        ));
        if ($undeclared !== []) {
            throw ValidationException::withMessages(['placeholders' => __('letters.validation.undeclared_placeholders', ['keys' => implode(', ', array_unique($undeclared))])]);
        }

        return DB::transaction(function () use ($template, $subject, $body, $changeNote, $actor): LetterTemplateVersion {
            $versionNo = $template->current_version_no + 1;
            $version = LetterTemplateVersion::query()->create([
                'template_id' => $template->id,
                'version_no' => $versionNo,
                'subject' => $subject,
                'body' => $body,
                'placeholders' => $template->draft_placeholders ?? [],
                'change_note' => $changeNote,
                'published_by' => $actor->id,
                'published_at' => now(),
                'created_at' => now(),
            ]);
            $template->forceFill(['status' => TemplateStatus::Published->value, 'current_version_no' => $versionNo, 'updated_by' => $actor->id])->save();
            $this->audit->record(AuditEventType::LetterTemplatePublished, $actor, $template, $template->owner_organization_id, ['code' => $template->code, 'version_no' => $versionNo], $changeNote);

            return $version;
        });
    }
}
