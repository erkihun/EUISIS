<?php

declare(strict_types=1);

namespace Modules\Discipline\Contracts\Data;

/**
 * The only penalties Regulation 201/2018 provides. Amount caps are statutory:
 * Art. 10(1)(c) up to 15 days' salary; Art. 11(1) up to three months' salary;
 * Art. 11(2) demotion for up to two years. No other penalty may be recorded.
 */
enum DisciplinaryPenalty: string
{
    case OralWarning = 'oral_warning';
    case WrittenWarning = 'written_warning';
    case SalaryFineDays = 'salary_fine_days';
    case SalaryFineMonths = 'salary_fine_months';
    case Demotion = 'demotion';
    case Dismissal = 'dismissal';

    public function severity(): DisciplinarySeverity
    {
        return match ($this) {
            self::OralWarning, self::WrittenWarning, self::SalaryFineDays => DisciplinarySeverity::Minor,
            default => DisciplinarySeverity::Serious,
        };
    }

    public function article(): string
    {
        return match ($this) {
            self::OralWarning => 'Art. 10(1)(a)',
            self::WrittenWarning => 'Art. 10(1)(b)',
            self::SalaryFineDays => 'Art. 10(1)(c)',
            self::SalaryFineMonths => 'Art. 11(1)',
            self::Demotion => 'Art. 11(2)',
            self::Dismissal => 'Art. 11(3)',
        };
    }

    /** Unit of the amount (days of salary, months of salary, months of demotion) or null. */
    public function amountUnit(): ?string
    {
        return match ($this) {
            self::SalaryFineDays => 'days',
            self::SalaryFineMonths, self::Demotion => 'months',
            default => null,
        };
    }

    public function maxAmount(): ?int
    {
        return match ($this) {
            self::SalaryFineDays => 15,
            self::SalaryFineMonths => 3,
            self::Demotion => 24,
            default => null,
        };
    }

    /** Art. 29(1): fines and demotion take effect 30 days after notice; Art. 29(2): dismissal on delivery. */
    public function takesEffectOnDelivery(): bool
    {
        return $this === self::Dismissal;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
