<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationUnitType: string
{
    case Department = 'department';
    case Directorate = 'directorate';
    case Team = 'team';
    case Unit = 'unit';
    case Office = 'office';
    case Section = 'section';

    public function label(): string
    {
        return match ($this) {
            self::Department  => 'Department',
            self::Directorate => 'Directorate',
            self::Team        => 'Team',
            self::Unit        => 'Unit',
            self::Office      => 'Office',
            self::Section     => 'Section',
        };
    }

    public function labelAm(): string
    {
        return match ($this) {
            self::Department  => 'ክፍል',
            self::Directorate => 'ዳይሬክቶሬት',
            self::Team        => 'ቡድን',
            self::Unit        => 'ዩኒት',
            self::Office      => 'ቢሮ',
            self::Section     => 'ሴክሽን',
        };
    }

    /**
     * @return array<int, array{value: string, label: string, label_am: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value'    => $case->value,
                'label'    => $case->label(),
                'label_am' => $case->labelAm(),
            ],
            self::cases(),
        );
    }
}
