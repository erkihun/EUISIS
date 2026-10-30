<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** The final action taken (D13). "Disciplinary action in progress" is not a value: only final actions are reported. */
enum FinalAction: string
{
    case Warning = 'warning';
    case SalaryFine = 'salary_fine';
    case Demotion = 'demotion';
    case RemovalFromResponsibility = 'removal_from_responsibility';
    case Dismissal = 'dismissal';
    case LegalAction = 'legal_action';
    case Other = 'other';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.final_action.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
