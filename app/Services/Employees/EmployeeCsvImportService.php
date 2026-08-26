<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Actions\Employees\RegisterEmployeeAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportBatchRow;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scoped CSV import of employees.
 *
 * Two phases, deliberately separated:
 *
 *  1. `validate()` reads the file, checks every row, and records the outcome as
 *     a batch. NOTHING is written to `employees`. The user sees a preview and
 *     may walk away.
 *  2. `import()` commits a previously validated batch inside one transaction.
 *     A failure anywhere rolls the whole thing back, so a 400-row file can
 *     never leave 200 employees behind.
 *
 * Organization scope is enforced per row, not per file. A user who may load
 * employees for one bureau cannot smuggle a row for another by editing the CSV,
 * because every `organization_code` is re-checked against their own scope.
 */
class EmployeeCsvImportService
{
    /** Column order of the downloadable template. */
    public const COLUMNS = [
        'employee_number',
        'first_name',
        'father_name',
        'grandfather_name',
        'gender',
        'phone',
        'email',
        'organization_code',
        'organization_unit_code',
        'position_code',
        'employment_status',
        'assignment_start_date',
    ];

    /** Guards against a spreadsheet export with a runaway row count. */
    private const MAX_ROWS = 2000;

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly RegisterEmployeeAction $registerEmployee,
        private readonly GenerateCodeAction $generateCode,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * Parse and validate an uploaded CSV, recording the result as a batch.
     *
     * Always returns a batch, even when every row is bad — the failure record
     * is itself the audit trail.
     */
    public function validate(UploadedFile $file, User $actor, ?Request $request = null): EmployeeImportBatch
    {
        $rows = $this->readRows($file);

        $batch = EmployeeImportBatch::query()->create([
            'uploaded_by' => $actor->getKey(),
            'organization_id' => null,
            'file_name' => $file->getClientOriginalName(),
            'total_rows' => count($rows),
            'valid_rows' => 0,
            'failed_rows' => 0,
            'status' => EmployeeImportBatch::STATUS_PENDING,
        ]);

        $allowedOrganizationIds = $this->scope->isUnrestricted($actor)
            ? null
            : $this->scope->accessibleOrganizationIds($actor)->all();

        /*
         * Positions claimed earlier in this same file. Without this, two rows
         * naming one vacant position would both validate and the second would
         * silently overwrite the first at import time.
         */
        $claimedPositions = [];

        /*
         * Employee numbers supplied in this file, so a duplicate WITHIN the
         * upload is caught as well as a clash with the database.
         */
        $claimedNumbers = [];

        $validCount = 0;
        $failedCount = 0;
        $organizationIds = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // Header occupies line 1.
            $errors = $this->validateRow($row, $allowedOrganizationIds, $claimedPositions, $claimedNumbers);

            $resolved = $errors === [] ? $this->resolveRow($row) : null;

            if ($resolved !== null) {
                $claimedPositions[] = $resolved['position']->id;
                $organizationIds[] = $resolved['organization']->id;

                $number = trim((string) ($row['employee_number'] ?? ''));

                if ($number !== '') {
                    $claimedNumbers[] = mb_strtolower($number);
                }

                $validCount++;
            } else {
                $failedCount++;
            }

            EmployeeImportBatchRow::query()->create([
                'batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'row_data' => $row,
                'status' => $errors === []
                    ? EmployeeImportBatchRow::STATUS_VALID
                    : EmployeeImportBatchRow::STATUS_INVALID,
                'errors' => $errors === [] ? null : $errors,
            ]);
        }

        $uniqueOrganizations = array_values(array_unique($organizationIds));

        $batch->forceFill([
            // Only meaningful when the whole file targets one organization.
            'organization_id' => count($uniqueOrganizations) === 1 ? $uniqueOrganizations[0] : null,
            'valid_rows' => $validCount,
            'failed_rows' => $failedCount,
            'status' => $failedCount > 0
                ? EmployeeImportBatch::STATUS_FAILED
                : EmployeeImportBatch::STATUS_VALIDATED,
        ])->save();

        $this->writeAuditLog->execute(
            eventType: AuditEventType::EmployeeImportValidated,
            actor: $actor,
            auditable: $batch,
            organizationId: $batch->organization_id,
            reason: 'Employee CSV validated: '.$validCount.' valid, '.$failedCount.' invalid',
            request: $request,
        );

        return $batch->refresh();
    }

    /**
     * Commit a validated batch.
     *
     * Re-runs every check against live data before writing. Between preview and
     * confirm, another user may have filled the position or created the
     * employee number, so the preview is treated as a proposal rather than a
     * guarantee.
     *
     * @return array{imported: int, batch: EmployeeImportBatch}
     */
    public function import(EmployeeImportBatch $batch, User $actor, ?Request $request = null): array
    {
        if (! $batch->isImportable()) {
            throw new \RuntimeException('Only a fully validated batch may be imported.');
        }

        $allowedOrganizationIds = $this->scope->isUnrestricted($actor)
            ? null
            : $this->scope->accessibleOrganizationIds($actor)->all();

        $imported = DB::transaction(function () use ($batch, $actor, $allowedOrganizationIds): int {
            $count = 0;
            $claimedPositions = [];
            $claimedNumbers = [];

            foreach ($batch->rows()->orderBy('row_number')->get() as $batchRow) {
                $row = $batchRow->row_data;

                // Re-validate against the database as it stands right now.
                $errors = $this->validateRow($row, $allowedOrganizationIds, $claimedPositions, $claimedNumbers);

                if ($errors !== []) {
                    // Abort the whole import: a partial load is worse than none.
                    throw new \RuntimeException(
                        'Row '.$batchRow->row_number.' is no longer valid: '.implode('; ', $errors)
                    );
                }

                $resolved = $this->resolveRow($row);
                $employee = $this->createEmployee($row, $resolved, $actor);

                $claimedPositions[] = $resolved['position']->id;

                if ($employee->employee_number !== null) {
                    $claimedNumbers[] = mb_strtolower((string) $employee->employee_number);
                }

                $batchRow->forceFill([
                    'status' => EmployeeImportBatchRow::STATUS_IMPORTED,
                    'employee_id' => $employee->id,
                ])->save();

                $count++;
            }

            $batch->forceFill(['status' => EmployeeImportBatch::STATUS_IMPORTED])->save();

            return $count;
        });

        $this->writeAuditLog->execute(
            eventType: AuditEventType::EmployeeImportCompleted,
            actor: $actor,
            auditable: $batch,
            organizationId: $batch->organization_id,
            reason: 'Employee CSV imported: '.$imported.' employees',
            request: $request,
        );

        return ['imported' => $imported, 'batch' => $batch->refresh()];
    }

    /**
     * Preview rows for the confirmation screen.
     *
     * Contact details are omitted: a preview is a bulk view of many people, and
     * an importer needs to confirm placement, not read phone numbers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preview(EmployeeImportBatch $batch): array
    {
        return $batch->rows()
            ->orderBy('row_number')
            ->get()
            ->map(function (EmployeeImportBatchRow $batchRow): array {
                $row = $batchRow->row_data;
                $resolved = $batchRow->status === EmployeeImportBatchRow::STATUS_INVALID
                    ? null
                    : $this->resolveRow($row);

                return [
                    'row_number' => $batchRow->row_number,
                    'name' => trim(implode(' ', array_filter([
                        $row['first_name'] ?? null,
                        $row['father_name'] ?? null,
                        $row['grandfather_name'] ?? null,
                    ]))),
                    'employee_number' => trim((string) ($row['employee_number'] ?? '')) ?: null,
                    'organization' => $resolved['organization']->name_en ?? ($row['organization_code'] ?? null),
                    'organization_unit' => $resolved['unit']->name_en ?? ($row['organization_unit_code'] ?? null),
                    'position' => $resolved['position']->title_en ?? ($row['position_code'] ?? null),
                    'status' => $batchRow->status,
                    'errors' => $batchRow->errors ?? [],
                ];
            })
            ->all();
    }

    /**
     * The template body an importer downloads.
     *
     * Carries one example row so the expected date format and the meaning of a
     * blank employee_number are obvious without reading documentation. A BOM is
     * prepended so Excel opens Amharic names as UTF-8.
     */
    public function templateCsv(): string
    {
        $sample = [
            '', 'Abebe', 'Kebede', 'Tesfaye', 'male', '0911000000', 'abebe@example.et',
            'ORG-001', 'UNIT-001', 'POS-001', 'active', '2026-01-01',
        ];

        return "\xEF\xBB\xBF".implode(',', self::COLUMNS)."\n".implode(',', $sample)."\n";
    }

    /**
     * Read the CSV into an array of associative rows.
     *
     * @return array<int, array<string, string>>
     */
    private function readRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            return [];
        }

        /*
         * Excel writes a UTF-8 BOM. Left in place it becomes part of the first
         * header name, so `employee_number` silently fails to match and every
         * row loses its first column.
         */
        $bom = fread($handle, 3);

        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(
            static fn ($value): string => Str::of((string) $value)->trim()->lower()->replace(' ', '_')->value(),
            $header,
        );

        $rows = [];

        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false && count($rows) < self::MAX_ROWS) {
            // Skip blank trailing lines that spreadsheets append.
            if ($line === [null] || $line === ['']) {
                continue;
            }

            $row = [];

            foreach (self::COLUMNS as $column) {
                $position = array_search($column, $header, true);
                $row[$column] = $position === false ? '' : trim((string) ($line[$position] ?? ''));
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Check one row. Returns a list of human-readable problems.
     *
     * @param  array<string, string>  $row
     * @param  array<int, string>|null  $allowedOrganizationIds  null means unrestricted
     * @param  array<int, string>  $claimedPositions
     * @param  array<int, string>  $claimedNumbers
     * @return array<int, string>
     */
    private function validateRow(array $row, ?array $allowedOrganizationIds, array $claimedPositions, array $claimedNumbers): array
    {
        $errors = [];

        foreach (['first_name', 'father_name', 'gender', 'organization_code', 'position_code', 'employment_status'] as $required) {
            if (trim((string) ($row[$required] ?? '')) === '') {
                $errors[] = __('employees.import.errors.required', ['field' => $required]);
            }
        }

        $gender = mb_strtolower(trim((string) ($row['gender'] ?? '')));

        if ($gender !== '' && ! in_array($gender, ['male', 'female'], true)) {
            $errors[] = __('employees.import.errors.gender');
        }

        $status = mb_strtolower(trim((string) ($row['employment_status'] ?? '')));

        if ($status !== '' && EmployeeStatus::tryFrom($status) === null) {
            $errors[] = __('employees.import.errors.employmentStatus');
        }

        $email = trim((string) ($row['email'] ?? ''));

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('employees.import.errors.email');
        }

        $organization = $this->findOrganization($row);

        if ($organization === null) {
            if (trim((string) ($row['organization_code'] ?? '')) !== '') {
                $errors[] = __('employees.import.errors.organizationNotFound', ['code' => $row['organization_code']]);
            }

            // Everything below depends on the organization, so stop here.
            return $errors;
        }

        // The scope gate: a row outside the importer's organizations is refused
        // no matter what the file says.
        if ($allowedOrganizationIds !== null && ! in_array($organization->id, $allowedOrganizationIds, true)) {
            $errors[] = __('employees.import.errors.outsideScope', ['code' => $organization->code]);

            return $errors;
        }

        $unitCode = trim((string) ($row['organization_unit_code'] ?? ''));
        $unit = null;

        if ($unitCode !== '') {
            $unit = OrganizationUnit::query()
                ->where('organization_id', $organization->id)
                ->where('code', $unitCode)
                ->first();

            if ($unit === null) {
                $errors[] = __('employees.import.errors.unitNotInOrganization', ['code' => $unitCode]);
            }
        }

        $position = $this->findPosition($row, $organization);

        if ($position === null) {
            if (trim((string) ($row['position_code'] ?? '')) !== '') {
                $errors[] = __('employees.import.errors.positionNotInOrganization', ['code' => $row['position_code']]);
            }
        } else {
            if ($unit !== null && $position->organization_unit_id !== null && $position->organization_unit_id !== $unit->id) {
                $errors[] = __('employees.import.errors.positionNotInUnit', ['code' => $position->job_position_code]);
            }

            if ($this->positionIsOccupied($position->id)) {
                $errors[] = __('employees.import.errors.positionOccupied', ['code' => $position->job_position_code]);
            }

            // Claimed by an earlier row of this same file.
            if (in_array($position->id, $claimedPositions, true)) {
                $errors[] = __('employees.import.errors.positionDuplicatedInFile', ['code' => $position->job_position_code]);
            }
        }

        $number = trim((string) ($row['employee_number'] ?? ''));

        if ($number !== '') {
            if (Employee::query()->where('employee_number', $number)->exists()) {
                $errors[] = __('employees.import.errors.employeeNumberExists', ['number' => $number]);
            }

            if (in_array(mb_strtolower($number), $claimedNumbers, true)) {
                $errors[] = __('employees.import.errors.employeeNumberDuplicatedInFile', ['number' => $number]);
            }
        }

        return $errors;
    }

    /**
     * Resolve the models a valid row points at.
     *
     * @param  array<string, string>  $row
     * @return array{organization: Organization, unit: OrganizationUnit|null, position: Position}|null
     */
    private function resolveRow(array $row): ?array
    {
        $organization = $this->findOrganization($row);

        if ($organization === null) {
            return null;
        }

        $position = $this->findPosition($row, $organization);

        if ($position === null) {
            return null;
        }

        $unitCode = trim((string) ($row['organization_unit_code'] ?? ''));

        $unit = $unitCode === ''
            ? null
            : OrganizationUnit::query()
                ->where('organization_id', $organization->id)
                ->where('code', $unitCode)
                ->first();

        return ['organization' => $organization, 'unit' => $unit, 'position' => $position];
    }

    /**
     * @param  array<string, string>  $row
     * @param  array{organization: Organization, unit: OrganizationUnit|null, position: Position}  $resolved
     */
    private function createEmployee(array $row, array $resolved, User $actor): Employee
    {
        $organization = $resolved['organization'];
        $position = $resolved['position'];
        $unit = $resolved['unit'];

        $names = array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['father_name'] ?? '')),
            trim((string) ($row['grandfather_name'] ?? '')),
        ]);

        $employeeAttributes = [
            // Blank means "generate one" — RegisterEmployeeAction runs the
            // configured Code Rule when no manual number is supplied.
            'employee_number' => trim((string) ($row['employee_number'] ?? '')) ?: null,
            'first_name' => trim((string) ($row['first_name'] ?? '')),
            'middle_name' => trim((string) ($row['father_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($row['grandfather_name'] ?? '')) ?: null,
            'full_name' => implode(' ', $names),
            'gender' => mb_strtolower(trim((string) ($row['gender'] ?? ''))) ?: null,
            'phone' => trim((string) ($row['phone'] ?? '')) ?: null,
            'email' => trim((string) ($row['email'] ?? '')) ?: null,
            'status' => EmployeeStatus::tryFrom(mb_strtolower(trim((string) ($row['employment_status'] ?? ''))))
                ?? EmployeeStatus::Active,
        ];

        $startDate = trim((string) ($row['assignment_start_date'] ?? ''));

        $assignmentAttributes = [
            'organization_id' => $organization->id,
            'organization_unit_id' => $unit?->id ?? $position->organization_unit_id,
            'position_id' => $position->id,
            'effective_from' => $startDate !== '' ? $startDate : now()->toDateString(),
        ];

        return $this->registerEmployee->execute($employeeAttributes, $assignmentAttributes, $actor);
    }

    /** @param array<string, string> $row */
    private function findOrganization(array $row): ?Organization
    {
        $code = trim((string) ($row['organization_code'] ?? ''));

        return $code === '' ? null : Organization::query()->where('code', $code)->first();
    }

    /** @param array<string, string> $row */
    private function findPosition(array $row, Organization $organization): ?Position
    {
        $code = trim((string) ($row['position_code'] ?? ''));

        if ($code === '') {
            return null;
        }

        return Position::query()
            ->where('organization_id', $organization->id)
            ->where(fn ($query) => $query->where('job_position_code', $code)->orWhere('code', $code))
            ->first();
    }

    private function positionIsOccupied(string $positionId): bool
    {
        return EmployeeAssignment::query()
            ->where('position_id', $positionId)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active)
            ->exists();
    }
}
