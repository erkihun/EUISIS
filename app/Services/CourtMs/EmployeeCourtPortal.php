<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use App\Models\Employee;
use Illuminate\Support\Facades\Cache;

/**
 * My Portal > My court cases (court-ms Flows 4 to 6, ADR 0013). Applicants at the
 * administrative tribunal are city employees: they file, follow and answer their
 * cases here, and court-ms's own applicant portal is closed. court-ms stays the
 * system of record: nothing here stores a case.
 */
final class EmployeeCourtPortal
{
    public function __construct(private readonly CourtMsClient $client) {}

    public function enabled(): bool
    {
        return $this->client->employeePortalEnabled();
    }

    /**
     * The employee's court summary, cached for a minute so the portal does not call
     * court-ms on every visit; null when court-ms cannot be reached.
     *
     * @return array<string, mixed>|null
     */
    public function summary(Employee $employee): ?array
    {
        // court-ms words the notices in the employee's language, so each language is cached apart.
        $locale = app()->getLocale() === 'am' ? 'am' : 'en';
        $key = "court-ms:employee-summary:{$employee->getKey()}:{$locale}";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $summary = $this->client->employeeSummary($this->identity($employee), $locale);
        } catch (CourtMsException) {
            return null;
        }
        Cache::put($key, $summary, 60);

        return $summary;
    }

    /**
     * Flow 6: a call into the employee's case workspace in court-ms; the summary is
     * read again afterwards, since the call may change what it shows.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array{name: string, contents: string, mime?: string}>  $files
     * @return array<string, mixed>
     */
    public function workspace(Employee $employee, string $path, array $data = [], array $files = [], bool $changes = false): array
    {
        $result = $this->client->workspace($path, (string) $employee->getKey(), $data, $files);
        if ($changes) {
            self::forgetSummary($employee);
        }

        return $result;
    }

    /** @return array{body: string, type: string, name: string} */
    public function file(Employee $employee, int $caseId, string $kind, ?int $id = null, ?int $responseId = null): array
    {
        return $this->client->workspaceFile($caseId, (string) $employee->getKey(), $kind, $id, $responseId);
    }

    /**
     * Flow 5: files the employee's case in court-ms (EmployeeCourtFiling builds it).
     *
     * @param  array<string, mixed>  $filing
     * @param  list<array{name: string, contents: string, title: string, source?: string}>  $documents
     * @return array{case_id: int, case_number: string, review_status: string, status: string}
     */
    public function fileCase(Employee $employee, array $filing, array $documents, ?array $powerOfAttorney = null): array
    {
        $result = $this->client->fileCase(['employee' => $this->person($employee), ...$filing], $documents, $powerOfAttorney);
        self::forgetSummary($employee);

        return $result;
    }

    /**
     * The employee's invitations to join a case and their applications to intervene
     * (ADR 0013 #13); null when court-ms cannot be reached.
     *
     * @return array{invitations: list<array<string, mixed>>, interventions: list<array<string, mixed>>}|null
     */
    public function parties(Employee $employee): ?array
    {
        try {
            return $this->client->parties((string) $employee->getKey());
        } catch (CourtMsException) {
            return null;
        }
    }

    /**
     * The court's register of verified lawyers, for the filing form; empty when
     * court-ms cannot be reached.
     *
     * @return list<array{name: string, license_number: string}>
     */
    public function lawyers(): array
    {
        try {
            return $this->client->lawyers();
        } catch (CourtMsException) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    public function respondToInvitation(Employee $employee, int $partyId, bool $accept): array
    {
        $result = $this->client->respondToInvitation($partyId, $this->person($employee), $accept);
        self::forgetSummary($employee);

        return $result;
    }

    /**
     * @param  array{name: string, contents: string}|null  $document
     * @return array<string, mixed>
     */
    public function intervene(Employee $employee, string $caseNumber, string $statement, ?array $document = null): array
    {
        return $this->client->intervene($this->person($employee), $caseNumber, $statement, $document);
    }

    public static function forgetSummary(Employee $employee): void
    {
        foreach (['en', 'am'] as $locale) {
            Cache::forget("court-ms:employee-summary:{$employee->getKey()}:{$locale}");
        }
    }

    /** court-ms case types, for the filing form; empty when court-ms cannot be reached. */
    public function caseTypes(): array
    {
        return $this->client->caseTypes();
    }

    /** @return array{uuid: string, national_id: ?string, email: ?string} */
    private function identity(Employee $employee): array
    {
        return [
            'uuid' => (string) $employee->getKey(),
            'national_id' => $this->digits($employee->national_id),
            'email' => $employee->email ?: null,
        ];
    }

    /**
     * What court-ms needs to open the court account on first use.
     *
     * @return array<string, mixed>
     */
    private function person(Employee $employee): array
    {
        $employee->loadMissing(['currentAssignment.position', 'currentAssignment.organization']);
        $assignment = $employee->currentAssignment;

        return array_filter([
            ...$this->identity($employee),
            'first_name' => $employee->first_name ?: $employee->name_en ?: $employee->full_name,
            'middle_name' => $employee->middle_name,
            'last_name' => $employee->last_name ?: '-',
            'gender' => in_array(strtolower((string) $employee->gender), ['male', 'female'], true) ? strtolower((string) $employee->gender) : null,
            'phone' => $employee->phone ?: null,
            'position' => $assignment?->position?->title_en,
            'organization_name' => $assignment?->organization?->name_en,
            'address' => $employee->address ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }
}
