<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use App\Models\Employee;
use Illuminate\Support\Facades\Cache;

/**
 * My Portal > My court cases (court-ms Flow 4, ADR 0013). Applicants at the
 * administrative tribunal are city employees, so the employee portal shows
 * their court cases and opens the court portal signed in as them. court-ms
 * stays the system of record: nothing here stores a case.
 */
final class EmployeeCourtPortal
{
    /** Pages of the court portal an employee may be sent to (court-ms EmployeeCourtAccess::TARGETS, plus `case:<id>`). */
    public const TARGETS = ['dashboard', 'cases', 'new_case', 'notifications', 'profile'];

    public function __construct(private readonly CourtMsClient $client) {}

    public function enabled(): bool
    {
        return $this->client->employeePortalEnabled();
    }

    public static function validTarget(string $target): bool
    {
        return in_array($target, self::TARGETS, true) || preg_match('/^case:\d{1,10}$/', $target) === 1;
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

    /** The one-time URL that opens `$target` in the court portal, signed in as the employee. */
    public function signInUrl(Employee $employee, string $target): string
    {
        $url = $this->client->signInUrl($this->person($employee), $target);
        // Opening the portal may open the court account or change what it shows.
        self::forgetSummary($employee);

        return $url;
    }

    /**
     * Flow 5: files the employee's case in court-ms (EmployeeCourtFiling builds it).
     *
     * @param  array<string, mixed>  $filing
     * @param  list<array{name: string, contents: string, title: string, source?: string}>  $documents
     * @return array{case_id: int, case_number: string, review_status: string, status: string}
     */
    public function fileCase(Employee $employee, array $filing, array $documents): array
    {
        $result = $this->client->fileCase(['employee' => $this->person($employee), ...$filing], $documents);
        self::forgetSummary($employee);

        return $result;
    }

    private static function forgetSummary(Employee $employee): void
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
