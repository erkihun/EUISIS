<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Calculation;

use Brick\Math\BigDecimal;

/**
 * One whitelisted way of turning inputs into an FTE (brief §48). A strategy
 * declares the parameters a methodology must set and the inputs a line
 * must give; the schemas are plain data, never expressions.
 *
 * Schema entry: ['type' => 'decimal'|'integer', 'min' => string|null, 'max' => string|null,
 *                'min_exclusive' => bool, 'max_exclusive' => bool, 'required' => bool]
 */
interface ManpowerStrategy
{
    public function type(): string;

    /** @return array<string, array<string, mixed>> */
    public function parameterSchema(): array;

    /** @return array<string, array<string, mixed>> */
    public function inputSchema(): array;

    /**
     * @param  array<string, BigDecimal>  $parameters  already validated
     * @param  array<string, BigDecimal>  $inputs  already validated
     * @return array{fte: BigDecimal, trace: array<string, mixed>}
     */
    public function calculate(array $parameters, array $inputs): array;
}
