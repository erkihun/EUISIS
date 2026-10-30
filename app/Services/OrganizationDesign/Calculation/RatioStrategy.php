<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Calculation;

use App\Support\Math\Dec;

/** FTE = driver quantity ÷ units per position (one position per N citizens, employees, cases…). */
final class RatioStrategy implements ManpowerStrategy
{
    public function type(): string
    {
        return 'RATIO';
    }

    public function parameterSchema(): array
    {
        return ['units_per_position' => ['type' => 'decimal', 'min' => '0', 'min_exclusive' => true, 'required' => true]];
    }

    public function inputSchema(): array
    {
        return ['driver_quantity' => ['type' => 'decimal', 'min' => '0', 'required' => true]];
    }

    public function calculate(array $parameters, array $inputs): array
    {
        $fte = Dec::div($inputs['driver_quantity'], $parameters['units_per_position']) ?? Dec::zero();

        return ['fte' => $fte, 'trace' => ['formula' => 'fte = driver_quantity ÷ units_per_position']];
    }
}
