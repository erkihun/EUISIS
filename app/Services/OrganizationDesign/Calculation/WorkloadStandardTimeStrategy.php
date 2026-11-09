<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Calculation;

use App\Support\Math\Dec;
use Brick\Math\BigDecimal;

/**
 * FTE = (annual volume × standard minutes ÷ 60)
 *     ÷ (annual working hours × productive % × (1 − leave allowance %)).
 */
final class WorkloadStandardTimeStrategy implements ManpowerStrategy
{
    public function type(): string
    {
        return 'WORKLOAD_STANDARD_TIME';
    }

    public function parameterSchema(): array
    {
        return [
            'annual_working_hours' => ['type' => 'decimal', 'min' => '0', 'min_exclusive' => true, 'max' => '8784', 'required' => true],
            'productive_time_percent' => ['type' => 'decimal', 'min' => '0', 'min_exclusive' => true, 'max' => '100', 'required' => true],
            'leave_allowance_percent' => ['type' => 'decimal', 'min' => '0', 'max' => '100', 'max_exclusive' => true, 'required' => true],
        ];
    }

    public function inputSchema(): array
    {
        return [
            'annual_volume' => ['type' => 'decimal', 'min' => '0', 'required' => true],
            // Taken from the task's standard in force when the line does not give it.
            'standard_time_minutes' => ['type' => 'decimal', 'min' => '0', 'min_exclusive' => true, 'required' => true],
        ];
    }

    public function calculate(array $parameters, array $inputs): array
    {
        $requiredHours = Dec::div($inputs['annual_volume']->multipliedBy($inputs['standard_time_minutes']), BigDecimal::of(60));
        $productive = Dec::div($parameters['productive_time_percent'], Dec::hundred());
        $leave = Dec::div(Dec::hundred()->minus($parameters['leave_allowance_percent']), Dec::hundred());
        $availableHours = $parameters['annual_working_hours']->multipliedBy($productive)->multipliedBy($leave);
        $fte = Dec::div($requiredHours, $availableHours) ?? Dec::zero();

        return [
            'fte' => $fte,
            'trace' => [
                'formula' => 'fte = (annual_volume × standard_time_minutes ÷ 60) ÷ (annual_working_hours × productive_time_percent% × (1 − leave_allowance_percent%))',
                'required_hours' => Dec::str($requiredHours),
                'available_hours_per_fte' => Dec::str($availableHours),
            ],
        ];
    }
}
