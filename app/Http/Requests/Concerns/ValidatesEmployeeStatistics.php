<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\DisabilityType;
use App\Enums\MaritalStatus;
use Illuminate\Validation\Rule;

/**
 * Date of birth, gender and the statistical fields of an employee, shared by
 * the create and edit requests. A form that leaves a statistical field out
 * keeps its value; an emptied one clears it. "No disability" clears the type.
 */
trait ValidatesEmployeeStatistics
{
    /** @var list<string> */
    private const STATISTICS_FIELDS = [
        'marital_status', 'education_level_id', 'field_of_study',
        'has_disability', 'disability_type', 'service_start_date',
    ];

    protected function normalizeEmployeeStatistics(): void
    {
        $values = [];
        foreach (self::STATISTICS_FIELDS as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $values[$field] = is_string($value) && trim($value) === '' ? null : $value;
            }
        }

        if (array_key_exists('has_disability', $values) && $values['has_disability'] !== null) {
            $values['has_disability'] = filter_var($values['has_disability'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $values['has_disability'];
            if ($values['has_disability'] === false) {
                $values['disability_type'] = null;
            }
        }

        $this->merge($values);
    }

    /**
     * @param  string|null  $currentGender  a legacy gender the record already carries may stay
     * @return array<string, list<mixed>>
     */
    protected function employeeStatisticsRules(?string $currentGender = null): array
    {
        $genders = array_values(array_unique(array_filter(['male', 'female', $currentGender])));

        return [
            'date_of_birth' => ['nullable', 'date', 'after:1900-01-01', 'before:today'],
            'gender' => ['nullable', 'string', Rule::in($genders)],
            'marital_status' => ['sometimes', 'nullable', Rule::enum(MaritalStatus::class)],
            'education_level_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('education_levels', 'id')->where('is_active', true)],
            'field_of_study' => ['sometimes', 'nullable', 'string', 'max:150'],
            'has_disability' => ['sometimes', 'nullable', 'boolean'],
            'disability_type' => ['sometimes', 'nullable', Rule::enum(DisabilityType::class), Rule::requiredIf(fn (): bool => $this->input('has_disability') === true)],
            'service_start_date' => array_merge(
                ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
                // Compared with a birth date only when one is given ("after:" an empty field always fails).
                filled($this->input('date_of_birth')) ? ['after:date_of_birth'] : [],
            ),
        ];
    }
}
