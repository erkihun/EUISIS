<?php

declare(strict_types=1);

namespace App\Enums;

enum InstitutionOfficeLevel: string
{
    case City = 'city';
    case SubCity = 'sub_city';
    case Woreda = 'woreda';
    case Branch = 'branch';
    case ServiceCenter = 'service_center';
    case Other = 'other';

    public function label(): string
    {
        return __('institution-offices.levels.'.$this->value);
    }
}
