<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Calculation;

use App\Support\Math\Dec;

/** FTE = subordinates ÷ span of control (supervisory positions). */
final class SpanOfControlStrategy implements ManpowerStrategy
{
    public function type(): string
    {
        return 'SPAN_OF_CONTROL';
    }

    public function parameterSchema(): array
    {
        return ['span' => ['type' => 'integer', 'min' => '0', 'min_exclusive' => true, 'required' => true]];
    }

    public function inputSchema(): array
    {
        return ['subordinate_count' => ['type' => 'decimal', 'min' => '0', 'required' => true]];
    }

    public function calculate(array $parameters, array $inputs): array
    {
        $fte = Dec::div($inputs['subordinate_count'], $parameters['span']) ?? Dec::zero();

        return ['fte' => $fte, 'trace' => ['formula' => 'fte = subordinate_count ÷ span']];
    }
}
