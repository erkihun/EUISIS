<?php

declare(strict_types=1);

namespace Modules\TipOffs\Application\Services;

use Illuminate\Validation\Rule;

/**
 * The public Tip-off form (owner's sample, 2026-11-08), on top of the tip
 * type, the facts, when and where, others involved and the evidence files:
 * whether the tipster asks for their identity to be kept confidential, the
 * person suspected (name, work unit or level of responsibility, sub-city /
 * woreda), the violations alleged (with "other" in words) and a description
 * of the supporting evidence. Every part is optional: a Tip-off never has to
 * name anyone or anything to be received.
 *
 * Stored encrypted on the submission (`tip_details`); shown to whoever may
 * handle the Tip-off. The tipster's own name and contact stay behind the
 * identity grant as before.
 */
final class PublicTipForm
{
    public const CONFIDENTIALITY = ['confidential', 'not_needed'];

    public const VIOLATIONS = ['bribery', 'abuse_of_power', 'document_fraud', 'waste_of_public_property', 'other'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'tip_form' => ['nullable', 'array'],
            'tip_form.confidentiality' => ['nullable', Rule::in(self::CONFIDENTIALITY)],
            'tip_form.suspect_name' => ['nullable', 'string', 'max:120'],
            'tip_form.suspect_unit' => ['nullable', 'string', 'max:200'],
            // Chosen from the scanned institution's own units and staff (checked against the token's institution).
            'tip_form.suspect_unit_id' => ['nullable', 'uuid'],
            'tip_form.suspect_employee_id' => ['nullable', 'uuid'],
            'tip_form.suspect_location' => ['nullable', 'string', 'max:200'],
            'tip_form.violation_types' => ['nullable', 'array'],
            'tip_form.violation_types.*' => [Rule::in(self::VIOLATIONS)],
            'tip_form.violation_other' => [
                'nullable', 'string', 'max:200',
                Rule::requiredIf(fn (): bool => in_array('other', (array) request()->input('tip_form.violation_types', []), true)),
            ],
            'tip_form.evidence_description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * What is stored: trimmed, empty parts dropped; null when nothing was given.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public static function details(array $input): ?array
    {
        $text = fn (string $key): ?string => is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? trim($input[$key]) : null;
        $violations = array_values(array_intersect(self::VIOLATIONS, is_array($input['violation_types'] ?? null) ? $input['violation_types'] : []));
        $details = array_filter([
            'confidentiality' => in_array($input['confidentiality'] ?? null, self::CONFIDENTIALITY, true) ? $input['confidentiality'] : null,
            'suspect_name' => $text('suspect_name'),
            'suspect_unit' => $text('suspect_unit'),
            'suspect_unit_id' => $text('suspect_unit_id'),
            'suspect_employee_id' => $text('suspect_employee_id'),
            'suspect_location' => $text('suspect_location'),
            'violation_types' => $violations === [] ? null : $violations,
            'violation_other' => in_array('other', $violations, true) ? $text('violation_other') : null,
            'evidence_description' => $text('evidence_description'),
        ], fn ($value) => $value !== null);

        return $details === [] ? null : $details;
    }
}
