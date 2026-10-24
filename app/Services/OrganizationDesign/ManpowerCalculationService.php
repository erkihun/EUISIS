<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\RoundingRule;
use App\Services\OrganizationDesign\Calculation\FixedMinimumStrategy;
use App\Services\OrganizationDesign\Calculation\ManpowerStrategy;
use App\Services\OrganizationDesign\Calculation\RatioStrategy;
use App\Services\OrganizationDesign\Calculation\SpanOfControlStrategy;
use App\Services\OrganizationDesign\Calculation\WorkloadStandardTimeStrategy;
use App\Services\Performance\Calculation\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Manpower estimation (brief §46–55): whitelisted strategies, parameters
 * and inputs validated against plain schemas (no expressions), DECIMAL
 * maths through Dec, a trace of every intermediate value, and the
 * rounding rule that turns FTE into a headcount (D-7).
 */
class ManpowerCalculationService
{
    /** @var array<string, ManpowerStrategy> */
    private array $strategies;

    public function __construct()
    {
        foreach ([new WorkloadStandardTimeStrategy, new RatioStrategy, new FixedMinimumStrategy, new SpanOfControlStrategy] as $strategy) {
            $this->strategies[$strategy->type()] = $strategy;
        }
    }

    /** @return list<string> */
    public function strategies(): array
    {
        return array_keys($this->strategies);
    }

    public function strategy(string $type): ManpowerStrategy
    {
        return $this->strategies[$type] ?? throw ValidationException::withMessages(['calculation_type' => __('organization-design.methodology_type_invalid')]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $inputs
     * @return array{calculated_fte: string, recommended_headcount: int|null, trace: array<string, mixed>}
     */
    public function calculate(string $type, array $parameters, array $inputs, ?string $rounding): array
    {
        $strategy = $this->strategy($type);
        $params = $this->validated($strategy->parameterSchema(), $parameters, 'parameters', complete: true);
        $values = $this->validated($strategy->inputSchema(), $inputs, 'inputs', complete: true);
        $result = $strategy->calculate($params, $values);
        $fte = $result['fte']->toScale(Dec::SCALE, RoundingMode::HalfUp);
        $rule = $rounding === null ? null : RoundingRule::tryFrom($rounding);

        return [
            'calculated_fte' => (string) $fte,
            'recommended_headcount' => $rule?->apply($fte),
            'trace' => [
                ...$result['trace'],
                'strategy' => $type,
                'parameters' => array_map(fn (BigDecimal $v) => Dec::str($v), $params),
                'inputs' => array_map(fn (BigDecimal $v) => Dec::str($v), $values),
                'calculated_fte' => (string) $fte,
                'rounding_rule' => $rounding,
            ],
        ];
    }

    /**
     * Errors keyed `parameters.<name>` for the given values; `complete` also
     * reports missing required parameters (publishing, D-6).
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
     */
    public function parameterErrors(string $type, array $parameters, bool $complete): array
    {
        if (! isset($this->strategies[$type])) {
            return ['calculation_type' => __('organization-design.methodology_type_invalid')];
        }
        try {
            $this->validated($this->strategies[$type]->parameterSchema(), $parameters, 'parameters', $complete);
        } catch (ValidationException $e) {
            return array_map(fn ($messages) => $messages[0], $e->errors());
        }

        return [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $values
     * @return array<string, BigDecimal>
     */
    private function validated(array $schema, array $values, string $prefix, bool $complete): array
    {
        $errors = [];
        $out = [];
        foreach (array_keys($values) as $key) {
            if (! isset($schema[$key])) {
                $errors["{$prefix}.{$key}"] = __('organization-design.calc_unknown_key', ['key' => $key]);
            }
        }
        foreach ($schema as $key => $rule) {
            $raw = $values[$key] ?? null;
            if ($raw === null || $raw === '') {
                if ($complete && ($rule['required'] ?? true)) {
                    $errors["{$prefix}.{$key}"] = __('organization-design.calc_missing', ['key' => $key]);
                }

                continue;
            }
            $text = is_string($raw) || is_int($raw) ? trim((string) $raw) : null;
            if ($text === null || ! preg_match($rule['type'] === 'integer' ? '/^-?\d+$/' : '/^-?\d+(\.\d+)?$/', $text)) {
                $errors["{$prefix}.{$key}"] = __('organization-design.calc_not_a_number', ['key' => $key]);

                continue;
            }
            $value = BigDecimal::of($text);
            // Rule bounds may be floats in config; brick/math 1.0 takes strings only.
            $min = isset($rule['min']) ? Dec::of($rule['min']) : null;
            $max = isset($rule['max']) ? Dec::of($rule['max']) : null;
            $belowMin = $min !== null && (($rule['min_exclusive'] ?? false) ? $value->isLessThanOrEqualTo($min) : $value->isLessThan($min));
            $aboveMax = $max !== null && (($rule['max_exclusive'] ?? false) ? $value->isGreaterThanOrEqualTo($max) : $value->isGreaterThan($max));
            if ($belowMin || $aboveMax) {
                $errors["{$prefix}.{$key}"] = __('organization-design.calc_out_of_range', ['key' => $key, 'min' => $rule['min'] ?? '-∞', 'max' => $rule['max'] ?? '∞']);

                continue;
            }
            $out[$key] = $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
