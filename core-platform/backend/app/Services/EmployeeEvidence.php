<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EligibilityRule;

/**
 * The identity evidence an employee must have on record (ADR 0009), as
 * EligibilityRule SQL, so employee approval (EmployeeVerification) and card
 * eligibility (CardEligibility) evaluate exactly the same rules:
 *  - PHOTO_REQUIRED / PHOTO_NOT_VERIFIED;
 *  - SIGNATURE_REQUIRED / SIGNATURE_NOT_VERIFIED;
 *  - REQUIRED_DOCUMENT_MISSING:<type> / REQUIRED_DOCUMENT_NOT_VERIFIED:<type>.
 * "On record" is the current version, pending or verified; "verified" is a
 * verified current version not past its expiry date.
 */
final readonly class EmployeeEvidence
{
    public function __construct(private CoreSettings $settings) {}

    /** @return array{photo: bool, signature: bool, documents: list<string>} what approval needs (Core settings, workforce) */
    public function forApproval(): array
    {
        return [
            'photo' => (bool) $this->settings->get('workforce.verification_requires_photo'),
            'signature' => (bool) $this->settings->get('workforce.verification_requires_signature'),
            'documents' => $this->requiredDocuments(),
            'photo_verified' => true,
        ];
    }

    /** @return array{photo: bool, signature: bool, documents: list<string>, photo_verified: bool} what a card request needs (Core settings, id_cards) */
    public function forCards(): array
    {
        return [
            'photo' => (bool) $this->settings->get('id_cards.require_photo'),
            'photo_verified' => (bool) $this->settings->get('id_cards.require_verified_photo'),
            'signature' => (bool) $this->settings->get('id_cards.require_signature'),
            'documents' => $this->requiredDocuments(),
        ];
    }

    /**
     * @param  array{photo: bool, signature: bool, documents: list<string>, photo_verified?: bool}  $requirements
     * @return list<EligibilityRule>
     */
    public function rules(array $requirements, string $employeeId = 'employees.id'): array
    {
        $rules = [];
        if ($requirements['photo']) {
            $rules[] = new EligibilityRule('PHOTO_REQUIRED', 'not '.$this->onRecord($employeeId), [EmployeeDocument::PHOTO, EmployeeDocument::PHOTO],
                'The employee has no current photo on record.');
            if ($requirements['photo_verified'] ?? true) {
                $rules[] = new EligibilityRule('PHOTO_NOT_VERIFIED', $this->onRecord($employeeId).' and not '.$this->verified($employeeId),
                    [EmployeeDocument::PHOTO, EmployeeDocument::PHOTO, EmployeeDocument::PHOTO, EmployeeDocument::PHOTO, today()->toDateString()],
                    'The employee\'s photo has not been verified, or its verification has expired.');
            }
        }
        if ($requirements['signature']) {
            $rules[] = new EligibilityRule('SIGNATURE_REQUIRED', 'not '.$this->onRecord($employeeId), [EmployeeDocument::SIGNATURE, EmployeeDocument::SIGNATURE],
                'The employee has no current signature on record.');
            $rules[] = new EligibilityRule('SIGNATURE_NOT_VERIFIED', $this->onRecord($employeeId).' and not '.$this->verified($employeeId),
                [EmployeeDocument::SIGNATURE, EmployeeDocument::SIGNATURE, EmployeeDocument::SIGNATURE, EmployeeDocument::SIGNATURE, today()->toDateString()],
                'The employee\'s signature has not been verified.');
        }
        foreach ($requirements['documents'] as $type) {
            $rules[] = new EligibilityRule('REQUIRED_DOCUMENT_MISSING:'.$type, 'not '.$this->onRecord($employeeId), [EmployeeDocument::DOCUMENT, $type],
                'A required document is not on record: '.str_replace('_', ' ', $type).'.');
            $rules[] = new EligibilityRule('REQUIRED_DOCUMENT_NOT_VERIFIED:'.$type, $this->onRecord($employeeId).' and not '.$this->verified($employeeId),
                [EmployeeDocument::DOCUMENT, $type, EmployeeDocument::DOCUMENT, $type, today()->toDateString()],
                'A required document is not verified or has expired: '.str_replace('_', ' ', $type).'.');
        }

        return $rules;
    }

    /**
     * The evidence an employee lacks, as code => message (empty when complete).
     *
     * @param  array{photo: bool, signature: bool, documents: list<string>, photo_verified?: bool}  $requirements
     * @return array<string, string>
     */
    public function failures(Employee $employee, array $requirements): array
    {
        $rules = $this->rules($requirements);
        if ($rules === []) {
            return [];
        }
        $row = EligibilityRule::selectFlags(Employee::query()->whereKey($employee->getKey())->select('employees.id'), $rules)->toBase()->first();

        return $row === null ? [] : EligibilityRule::failures($row, $rules);
    }

    /** @return list<string> */
    private function requiredDocuments(): array
    {
        return array_values(array_intersect((array) $this->settings->get('workforce.required_document_types'), EmployeeDocument::DOCUMENT_TYPES));
    }

    /** A current version pending or verified (bindings: kind, document type). */
    private function onRecord(string $employeeId): string
    {
        $true = EligibilityRule::true();

        return "exists (select 1 from employee_documents ed where ed.employee_id = {$employeeId} and ed.kind = ? and ed.document_type = ?"
            ." and ed.is_current = {$true} and ed.status in ('pending', 'verified'))";
    }

    /** A current verified version not past its expiry (bindings: kind, document type, today). */
    private function verified(string $employeeId): string
    {
        $true = EligibilityRule::true();

        return "exists (select 1 from employee_documents ev where ev.employee_id = {$employeeId} and ev.kind = ? and ev.document_type = ?"
            ." and ev.is_current = {$true} and ev.status = 'verified' and (ev.expires_at is null or ev.expires_at >= ?))";
    }
}
