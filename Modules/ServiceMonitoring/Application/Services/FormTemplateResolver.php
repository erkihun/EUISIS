<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use Modules\ServiceMonitoring\Domain\Models\FormTemplate;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/**
 * The form template a participant reports on: the published template assigned
 * to its organization's type (publishing guarantees at most one), or null for
 * the official form.
 */
final readonly class FormTemplateResolver
{
    public function forParticipant(Participant $participant): ?FormTemplate
    {
        $typeId = $participant->organization()->value('organization_type_id');
        if ($typeId === null) {
            return null;
        }

        return FormTemplate::query()
            ->published()
            ->whereHas('organizationTypes', static fn ($q) => $q->whereKey($typeId))
            ->orderBy('code')
            ->first();
    }
}
