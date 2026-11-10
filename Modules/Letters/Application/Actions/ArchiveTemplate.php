<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Enums\TemplateStatus;
use Modules\Letters\Domain\Models\LetterTemplate;

/** Stops offering a template. Letters already composed from its versions keep rendering from them. */
final class ArchiveTemplate
{
    public function __construct(private readonly LettersAudit $audit) {}

    public function execute(LetterTemplate $template, User $actor): LetterTemplate
    {
        $template->forceFill(['status' => TemplateStatus::Archived->value, 'updated_by' => $actor->id])->save();
        $this->audit->record(AuditEventType::LetterTemplateArchived, $actor, $template, $template->owner_organization_id, ['code' => $template->code]);

        return $template;
    }
}
