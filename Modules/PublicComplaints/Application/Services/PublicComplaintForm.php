<?php

declare(strict_types=1);

namespace Modules\PublicComplaints\Application\Services;

use App\Rules\PublicPhoneNumber;
use Illuminate\Validation\Rule;

/**
 * The public complaint form (owner's template, 2026-11-08): beyond the name,
 * phone, address and the complaint itself, a complainant gives their sex and
 * identity document, any representation, the body and person complained
 * about, the service, where it was heard before (woreda, sub-city) and with
 * what decision, the remedy sought, which supporting documents are attached,
 * and a declaration that it is all true.
 *
 * Stored encrypted on the submission (`complaint_details`) in two parts: the
 * complainant's own details, shown only with the identity grant like their
 * name and phone, and the matter, shown to every handler.
 */
final class PublicComplaintForm
{
    public const ID_TYPES = ['residence_id', 'national_id', 'passport', 'driving_licence', 'other'];

    public const GENDERS = ['male', 'female'];

    /** Supporting documents the complainant says are attached (the files are the evidence uploads). */
    public const ATTACHED = ['id_copy', 'decision_copy', 'poa_copy'];

    private const COMPLAINANT = ['gender', 'id_type', 'id_number', 'alt_phone', 'represented', 'principal_name', 'poa_reference', 'poa_date'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        $priorDate = ['nullable', 'date', 'before_or_equal:today'];

        return [
            // Section 1: the complainant (name and phone are the shared submitter fields).
            'submitter_address' => ['required', 'array:city,sub_city,woreda,detail,house_number'],
            'submitter_address.sub_city' => ['required', 'string', 'max:100'],
            'submitter_address.woreda' => ['required', 'string', 'max:100'],
            'submitter_address.house_number' => ['nullable', 'string', 'max:30'],
            'complaint_form' => ['required', 'array'],
            'complaint_form.gender' => ['required', Rule::in(self::GENDERS)],
            'complaint_form.id_type' => ['required', Rule::in(self::ID_TYPES)],
            'complaint_form.id_number' => ['required', 'string', 'max:60'],
            'complaint_form.alt_phone' => ['nullable', 'string', 'max:40', new PublicPhoneNumber],
            'complaint_form.represented' => ['nullable', 'boolean'],
            'complaint_form.principal_name' => ['nullable', 'required_if_accepted:complaint_form.represented', 'string', 'max:120'],
            'complaint_form.poa_reference' => ['nullable', 'required_if_accepted:complaint_form.represented', 'string', 'max:100'],
            'complaint_form.poa_date' => $priorDate,
            // Section 2: the body complained about is the QR target; the unit or person is optional.
            'complaint_form.unit_or_staff' => ['nullable', 'string', 'max:200'],
            // Section 3: the matter.
            'complaint_form.service_type' => ['nullable', 'string', 'max:200'],
            'complaint_form.prior_woreda_date' => $priorDate,
            'complaint_form.prior_woreda_decision' => ['nullable', 'string', 'max:1000'],
            'complaint_form.prior_sub_city_date' => $priorDate,
            'complaint_form.prior_sub_city_decision' => ['nullable', 'string', 'max:1000'],
            'complaint_form.remedy_sought' => ['required', 'string', 'max:2000'],
            // Section 4: attached documents.
            'complaint_form.attached' => ['nullable', 'array'],
            'complaint_form.attached.*' => [Rule::in(self::ATTACHED)],
            'complaint_form.other_pages' => ['nullable', 'integer', 'min:0', 'max:500'],
            // Declaration in place of the paper signature.
            'complaint_form.declaration' => ['accepted'],
        ];
    }

    /**
     * What is stored: trimmed, empty parts dropped, the declaration recorded with its time.
     *
     * @param  array<string, mixed>  $input
     * @return array{complainant: array<string, mixed>, matter: array<string, mixed>, declared_at: string}
     */
    public static function details(array $input): array
    {
        $text = fn (string $key): ?string => is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? trim($input[$key]) : null;
        $represented = filter_var($input['represented'] ?? false, FILTER_VALIDATE_BOOL);
        $complainant = array_filter([
            'gender' => $text('gender'),
            'id_type' => $text('id_type'),
            'id_number' => $text('id_number'),
            'alt_phone' => $text('alt_phone'),
            'represented' => $represented,
            'principal_name' => $represented ? $text('principal_name') : null,
            'poa_reference' => $represented ? $text('poa_reference') : null,
            'poa_date' => $represented ? $text('poa_date') : null,
        ], fn ($value) => $value !== null);
        $prior = fn (string $level): ?array => ($text("prior_{$level}_date") ?? $text("prior_{$level}_decision")) === null ? null
            : array_filter(['date' => $text("prior_{$level}_date"), 'decision' => $text("prior_{$level}_decision")], fn ($v) => $v !== null);
        $attached = array_values(array_intersect(self::ATTACHED, is_array($input['attached'] ?? null) ? $input['attached'] : []));
        $matter = array_filter([
            'unit_or_staff' => $text('unit_or_staff'),
            'service_type' => $text('service_type'),
            'prior_woreda' => $prior('woreda'),
            'prior_sub_city' => $prior('sub_city'),
            'remedy_sought' => $text('remedy_sought'),
            'attached' => $attached === [] ? null : $attached,
            'other_pages' => isset($input['other_pages']) && $input['other_pages'] !== '' ? (int) $input['other_pages'] : null,
        ], fn ($value) => $value !== null);

        return ['complainant' => $complainant, 'matter' => $matter, 'declared_at' => now()->toIso8601String()];
    }

    /** @return list<string> */
    public static function complainantKeys(): array
    {
        return self::COMPLAINANT;
    }
}
