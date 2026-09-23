<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Employees\RegisterEmployeeAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportBatchRow;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\CodeGeneration\CodeFormatTokenResolver;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleResolver;
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
        'nationality',
        'employment_type',
    ];

    public static function templateColumns(): array
    {
        $importColumns = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => $column !== 'employee_number',
        ));

        return [...array_map(static fn (string $column) => match ($column) {
            'organization_code' => 'organization_name',
            'organization_unit_code' => 'organization_unit_name',
            'position_code' => 'position_name',
            default => $column,
        }, $importColumns), 'position_reference'];
    }

    /** Guards against a spreadsheet export with a runaway row count. */
    private const MAX_ROWS = 2000;

    private const MAX_RANDOM_CODE_ATTEMPTS = 20;

    /**
     * Rows present in the last file read beyond MAX_ROWS.
     *
     * The reader stops at the cap and `total_rows` counts only what it read,
     * so without this a 2,500-row upload reported "2,000 rows, all valid" and
     * the importer had no way to know 500 rows were never seen.
     */
    private int $skippedRowCount = 0;

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly RegisterEmployeeAction $registerEmployee,
        private readonly CodeRuleResolver $codeRuleResolver,
        private readonly CodeGeneratorService $codeGeneratorService,
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
         * Generated numbers reserved for rows in this validation pass. Values
         * supplied in the CSV are deliberately ignored.
         */
        $claimedNumbers = [];

        $validCount = 0;
        $failedCount = 0;
        $organizationIds = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // Header occupies line 1.
            $placementError = $this->resolveTemplatePlacement($row, $allowedOrganizationIds);
            $errors = $placementError !== null ? [$placementError] : $this->validateRow($row, $allowedOrganizationIds, $claimedPositions, $claimedNumbers);

            $resolved = $errors === [] ? $this->resolveRow($row) : null;

            if ($resolved !== null) {
                $codeContext = ['organization_id' => $resolved['organization']->id];
                $rule = $this->codeRuleResolver->resolve(CodeRuleEntityType::Employee, $codeContext);

                if ($rule === null) {
                    $errors[] = __('code-rules.no_active_rule');
                    $resolved = null;
                } elseif (CodeFormatTokenResolver::usesRandomToken($rule->format)) {
                    $generatedNumber = null;

                    for ($attempt = 0; $attempt < self::MAX_RANDOM_CODE_ATTEMPTS; $attempt++) {
                        $candidate = $this->codeGeneratorService->preview($rule, $codeContext);

                        if (! Employee::query()->where('employee_number', $candidate)->exists()
                            && ! in_array(mb_strtolower($candidate), $claimedNumbers, true)) {
                            $generatedNumber = $candidate;

                            break;
                        }
                    }

                    if ($generatedNumber === null) {
                        $errors[] = __('code-rules.random_code_duplicate');
                        $resolved = null;
                    } else {
                        $row['_generated_employee_number'] = $generatedNumber;
                    }
                }
            }

            if ($resolved !== null && $errors === []) {
                $claimedPositions[] = $resolved['position']->id;
                $organizationIds[] = $resolved['organization']->id;

                $number = $this->employeeNumber($row);

                if ($number !== null) {
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
        /*
         * A file names the same organization on every row and usually the same
         * handful of units, but resolveRow() re-queried both for each row — so
         * a 2,000-row batch cost thousands of queries on every render of the
         * import screen. Organizations and units are looked up once per code
         * here. Positions are not cached: each row must name its own vacant
         * position, so those lookups are genuinely one per row.
         */
        $organizationsByCode = [];
        $unitsByCode = [];

        return $batch->rows()
            ->orderBy('row_number')
            ->get()
            ->map(function (EmployeeImportBatchRow $batchRow) use (&$organizationsByCode, &$unitsByCode): array {
                $row = $batchRow->row_data;
                $resolved = $batchRow->status === EmployeeImportBatchRow::STATUS_INVALID
                    ? null
                    : $this->resolveRowCached($row, $organizationsByCode, $unitsByCode);

                return [
                    'row_number' => $batchRow->row_number,
                    'name' => trim(implode(' ', array_filter([
                        $row['first_name'] ?? null,
                        $row['father_name'] ?? null,
                        $row['grandfather_name'] ?? null,
                    ]))),
                    'employee_number' => $this->employeeNumber($row),
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
     * Prefills placement names and an unambiguous reference, never employee details.
     * A BOM lets spreadsheet software open Amharic codes as UTF-8.
     */
    /**
     * Build the starter CSV for an organization.
     *
     * The header row stays in canonical English keys whatever the locale:
     * readRows() matches those keys exactly, so a translated header would make
     * the file we just handed out impossible to upload back. Only the
     * human-readable name columns follow the locale.
     */
    public function templateCsv(Organization $organization, string $locale = 'en'): string
    {
        $amharic = $locale === 'am';

        $positions = Position::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->whereDoesntHave('assignments', fn ($query) => $query->where('is_current', true)->where('assignment_status', AssignmentStatus::Active))
            ->where(fn ($query) => $query->whereNull('organization_unit_id')->orWhereHas('organizationUnit', fn ($unit) => $unit->where('organization_id', $organization->id)->where('status', 'active')))
            ->with('organizationUnit:id,code,name_en,name_am')
            ->orderBy('job_position_code')->orderBy('id')
            ->limit($this->maxRows())
            ->get(['id', 'organization_unit_id', 'job_position_code', 'code', 'title_en', 'title_am']);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::templateColumns(), ',', '"', '');
        // Empty organizations still get an organization-specific starter row.
        foreach ($positions->isEmpty() ? [null] : $positions as $position) {
            $row = array_fill_keys(self::templateColumns(), '');
            // Preferred locale first, with the other language as the fallback
            // so a record missing one translation still names something.
            $row['organization_name'] = $amharic
                ? ($organization->name_am ?: $organization->name_en)
                : ($organization->name_en ?: $organization->name_am);
            $unit = $position?->organizationUnit;
            $row['organization_unit_name'] = ($amharic
                ? ($unit?->name_am ?: $unit?->name_en)
                : ($unit?->name_en ?: $unit?->name_am)) ?? '';
            $row['position_name'] = ($amharic
                ? ($position?->title_am ?: $position?->title_en)
                : ($position?->title_en ?: $position?->title_am)) ?? '';
            $row['position_reference'] = $position?->id ?? '';
            $row['employment_status'] = 'active';
            // Treat codes as text when opened in spreadsheet software.
            $values = array_map(static fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : $value, array_values($row));
            fputcsv($stream, $values, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * Read the CSV into an array of associative rows.
     *
     * @return array<int, array<string, string>>
     */
    /**
     * How the employee is engaged, from `employment_type`.
     *
     * `employment_status` is deliberately not read here: in this file it has
     * always meant the record lifecycle (active, suspended), and existing
     * import files rely on that. A file may still use `employee_status` as an
     * alias for the new column.
     */
    private function employmentType(array $row): ?string
    {
        $value = mb_strtolower(trim((string) ($row['employment_type'] ?? $row['employee_status'] ?? '')));

        return EmploymentType::tryFrom($value)?->value;
    }

    /** Rows the last read skipped because the file exceeded MAX_ROWS. */
    public function skippedRowCount(): int
    {
        return $this->skippedRowCount;
    }

    /** The largest file this importer will read, in rows. */
    public function maxRows(): int
    {
        return self::MAX_ROWS;
    }

    private function readRows(UploadedFile $file): array
    {
        $this->skippedRowCount = 0;
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

        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false && true) {
            // Skip blank trailing lines that spreadsheets append.
            if ($line === [null] || $line === ['']) {
                continue;
            }

            /*
             * Past the cap, keep counting instead of stopping, so the importer
             * can be told how many rows were left out rather than being shown
             * a total that quietly matches the cap.
             */
            if (count($rows) >= self::MAX_ROWS) {
                $this->skippedRowCount++;

                continue;
            }

            $row = [];

            foreach (array_unique([...self::COLUMNS, ...self::templateColumns()]) as $column) {
                $position = array_search($column, $header, true);
                $row[$column] = $position === false ? '' : trim((string) ($line[$position] ?? ''));
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /** Resolve name-based templates by stable position identity, never by guessing a title. */
    private function resolveTemplatePlacement(array &$row, ?array $allowedOrganizationIds): ?string
    {
        $reference = $row['position_reference'] ?? '';
        if ($reference === '' && ($row['organization_name'] ?? '') === '' && ($row['position_name'] ?? '') === '') {
            return null; // Existing code-based files remain supported.
        }
        if (! Str::isUuid($reference)) {
            return __('employees.import.errors.templateReference');
        }
        $position = Position::query()->with(['organization', 'organizationUnit'])
            ->when($allowedOrganizationIds !== null, fn ($query) => $query->whereIn('organization_id', $allowedOrganizationIds))
            ->find($reference);
        if ($position === null || $position->organization === null || ! $position->is_active) {
            return __('employees.import.errors.templateReference');
        }
        $expected = [
            'organization_name' => $position->organization->name_en ?: $position->organization->name_am,
            'organization_unit_name' => $position->organizationUnit?->name_en ?: ($position->organizationUnit?->name_am ?? ''),
            'position_name' => $position->title_en ?: ($position->title_am ?? ''),
        ];
        foreach ($expected as $key => $name) {
            $name = trim((string) $name);
            $safeName = preg_match('/^[\s]*[=+@-]/u', $name) ? "'".$name : $name;
            if (! in_array(trim($row[$key] ?? ''), [$name, $safeName], true)) {
                return __('employees.import.errors.templateNames');
            }
        }
        $row['organization_code'] = $position->organization->code;
        $row['organization_unit_code'] = $position->organizationUnit?->code ?? '';
        $row['position_code'] = $position->job_position_code ?: $position->code;

        return null;
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

        $number = $this->employeeNumber($row);

        if ($number !== null) {
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
    /**
     * resolveRow() with the organization and unit lookups served from caches
     * held by the caller. Kept beside it so the two stay in step.
     *
     * @param  array<string, Organization|null>  $organizationsByCode
     * @param  array<string, OrganizationUnit|null>  $unitsByCode
     * @return array<string, mixed>|null
     */
    private function resolveRowCached(array $row, array &$organizationsByCode, array &$unitsByCode): ?array
    {
        $organizationCode = trim((string) ($row['organization_code'] ?? ''));
        $organization = $organizationsByCode[$organizationCode] ??= $this->findOrganization($row);

        if ($organization === null) {
            return null;
        }

        $position = $this->findPosition($row, $organization);

        if ($position === null) {
            return null;
        }

        $unitCode = trim((string) ($row['organization_unit_code'] ?? ''));

        if ($unitCode === '') {
            $unit = null;
        } else {
            $unit = $unitsByCode[$organization->id.'|'.$unitCode] ??= OrganizationUnit::query()
                ->where('organization_id', $organization->id)
                ->where('code', $unitCode)
                ->first();
        }

        return ['organization' => $organization, 'unit' => $unit, 'position' => $position];
    }

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
            // RegisterEmployeeAction runs the configured Code Rule for every
            // row; a CSV value can never override identity numbering.
            'employee_number' => null,
            '_expected_generated_code' => $row['_generated_employee_number'] ?? null,
            'first_name' => trim((string) ($row['first_name'] ?? '')),
            'middle_name' => trim((string) ($row['father_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($row['grandfather_name'] ?? '')) ?: null,
            'full_name' => implode(' ', $names),
            'gender' => mb_strtolower(trim((string) ($row['gender'] ?? ''))) ?: null,
            'phone' => trim((string) ($row['phone'] ?? '')) ?: null,
            'email' => trim((string) ($row['email'] ?? '')) ?: null,
            'status' => EmployeeStatus::tryFrom(mb_strtolower(trim((string) ($row['employment_status'] ?? ''))))
                ?? EmployeeStatus::Active,
            'nationality' => trim((string) ($row['nationality'] ?? '')) ?: null,
            'employment_type' => $this->employmentType($row),
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

    /** @param array<string, mixed> $row */
    private function employeeNumber(array $row): ?string
    {
        $generatedNumber = trim((string) ($row['_generated_employee_number'] ?? ''));

        return $generatedNumber !== '' ? $generatedNumber : null;
    }
}
