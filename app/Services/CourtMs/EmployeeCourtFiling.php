<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use App\Enums\EmployeeStatus;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Modules\Discipline\Contracts\DisciplineCases;
use Modules\Grievance\Contracts\GrievanceCourtRecords;

/**
 * My Portal > My court cases > File a case (court-ms Flow 5). Employees file
 * their tribunal case here, not in court-ms: against an institution registered
 * in EUISIS, with the grievances it concerns. Each grievance travels with what
 * the employee was given on it (the letters issued to them and the issued
 * decisions) and its PDFs (those letters and the evidence they submitted).
 * Only what the employee can already see in My Grievances is sent.
 */
final class EmployeeCourtFiling
{
    /** court-ms accepts at most 20 documents of 10 MB each. */
    public const MAX_DOCUMENTS = 20;

    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly EmployeeCourtPortal $portal) {}

    /** Institutions registered in EUISIS that may be sued: the active ones. */
    public function organizations(): Builder
    {
        return Organization::query()->where('status', OrganizationStatus::Active->value);
    }

    /** The institution the employee works for, the default respondent. */
    public function ownOrganizationId(Employee $employee): ?string
    {
        $employee->loadMissing('currentAssignment');

        return $employee->currentAssignment?->organization_id;
    }

    /** An active employee by employee number: a co-applicant the employee files with. */
    public function colleague(string $employeeNumber): ?Employee
    {
        $number = trim($employeeNumber);

        return $number === '' ? null : Employee::query()->where('employee_number', $number)->where('status', EmployeeStatus::Active->value)->first();
    }

    /** The name court-ms shows for an employee: the English name, as their court account is opened. */
    public function nameOf(Employee $employee): string
    {
        $name = trim((string) ($employee->fullNameEn() ?: implode(' ', array_filter([$employee->first_name, $employee->middle_name, $employee->last_name]))));

        return $name !== '' ? $name : (string) $employee->employee_number;
    }

    /**
     * @param  array{case_type_id: int, description: string, relief_requested?: ?string, organization_id: string, respondent_address?: ?string, filing_id: string}  $data
     * @param  list<string>  $grievanceIds  the employee's own grievances (checked by the caller)
     * @param  list<UploadedFile>  $uploads  PDFs the employee adds
     * @param  list<array{uuid: string, name: string}>  $coApplicants  other employees filing with them (checked by the caller)
     * @param  ?string  $lawyerLicense  a lawyer from the court's register, with the power of attorney
     * @param  list<string>  $disciplineIds  the employee's own decided discipline cases (checked by the caller)
     * @return array{case_id: int, case_number: string, review_status: string, status: string}
     *
     * @throws CourtMsException
     */
    public function file(Employee $employee, User $user, array $data, array $grievanceIds, array $uploads, array $coApplicants = [], ?string $lawyerLicense = null, ?UploadedFile $powerOfAttorney = null, array $disciplineIds = []): array
    {
        $organization = $this->organizations()->findOrFail($data['organization_id']);
        $grievanceFile = app(GrievanceCourtRecords::class)->courtFile($user, $grievanceIds, self::MAX_DOCUMENT_BYTES);
        $disciplines = app(DisciplineCases::class)->courtSnapshots(app(DisciplineCases::class)->ownDeliveredCaseIds((string) $employee->getKey(), $disciplineIds));

        $documents = [];
        array_push($documents, ...$grievanceFile['documents']);
        foreach ($uploads as $upload) {
            $documents[] = [
                'name' => $upload->getClientOriginalName() ?: 'document.pdf',
                'contents' => (string) $upload->get(),
                'title' => pathinfo($upload->getClientOriginalName() ?: 'document.pdf', PATHINFO_FILENAME),
                'source' => 'EUISIS employee portal',
            ];
        }

        return $this->portal->fileCase($employee, [
            'filing_id' => $data['filing_id'],
            'case_type_id' => $data['case_type_id'],
            'description' => $data['description'],
            'relief_requested' => $data['relief_requested'] ?? null,
            'respondent' => array_filter([
                'organization_uuid' => (string) $organization->getKey(),
                'name' => $organization->name_am ?: $organization->name_en,
                'address' => $data['respondent_address'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'grievances' => json_encode($grievanceFile['snapshots'], JSON_UNESCAPED_UNICODE),
            // The discipline cases it concerns, in the grievances' form (ADR 0013 #14): the appeal record starts there.
            'disciplines' => $disciplines === [] ? null : json_encode($disciplines, JSON_UNESCAPED_UNICODE),
            'co_applicants' => $coApplicants !== [] ? $coApplicants : null,
            'lawyer_license_number' => $powerOfAttorney !== null ? $lawyerLicense : null,
        ], array_slice($documents, 0, self::MAX_DOCUMENTS), $powerOfAttorney !== null && $lawyerLicense !== null ? [
            'name' => $powerOfAttorney->getClientOriginalName() ?: 'power-of-attorney.pdf',
            'contents' => (string) $powerOfAttorney->get(),
        ] : null);
    }
}
