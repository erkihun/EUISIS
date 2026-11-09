<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** A form template version: only a draft changes; reports point at published versions. */
enum FormTemplateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
    case Archived = 'archived';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.form_template_status.'.$this->value, [], $locale);
    }
}
