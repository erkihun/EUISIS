<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Calculation;

/** FTE = a fixed minimum staffing, whatever the workload (statutory or safety minimums). */
final class FixedMinimumStrategy implements ManpowerStrategy
{
    public function type(): string
    {
        return 'FIXED_MINIMUM';
    }

    public function parameterSchema(): array
    {
        return ['minimum_positions' => ['type' => 'integer', 'min' => '0', 'required' => true]];
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function calculate(array $parameters, array $inputs): array
    {
        return ['fte' => $parameters['minimum_positions'], 'trace' => ['formula' => 'fte = minimum_positions']];
    }
}
