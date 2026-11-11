<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Employees\RegisterEmployeeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\DisabilityType;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\MaritalStatus;
use App\Models\EducationLevel;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportBatchRow;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\Calendar\EthiopianCalendarService;
use App\Services\CodeGeneration\CodeFormatTokenResolver;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\OrganizationUnits\OrganizationUnitTreeService;
use App\Services\Positions\PositionCapacityService;
use App\Support\Security\NationalIdHash;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Throwable;

/**
 * Scoped import of employees from a CSV or Excel file.
 *
 * Two phases, deliberately separated:
 *
 *  1. `validate()` reads the file, checks every row, and records the outcome as
 *     a batch. NOTHING is written to `employees`. The user sees the check and
 *     may walk away.
 *  2. `import()` commits the rows that passed, inside one transaction. Rows
 *     with problems are left out and stay on the batch, so the importer can
 *     download them, correct them and upload them again. A failure of the
 *     database itself still rolls the whole import back.
 *
 * Organization scope is enforced per row, not per file. A user who may load
 * employees for one bureau cannot smuggle a row for another by editing the
 * file, because every `organization_code` is re-checked against their own
 * scope.
 */
class EmployeeCsvImportService
{
    /** Column order of the code-based file older uploads use. */
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
        ...self::DETAIL_COLUMNS,
    ];

    /**
     * The rest of what the employee form records, all optional. Added at the
     * end, so a file made from an older template is read exactly as before.
     */
    private const DETAIL_COLUMNS = [
        'name_en',
        'national_id',
        'date_of_birth',
        'marital_status',
        'education_level',
        'field_of_study',
        'has_disability',
        'disability_type',
        'service_start_date',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    /**
     * The cells an importer types. A template row that has none of them is a
     * vacant position nobody was entered for, not a mistake.
     */
    private const PERSON_COLUMNS = [
        'first_name',
        'father_name',
        'grandfather_name',
        'gender',
        'phone',
        'email',
        'assignment_start_date',
        'nationality',
        'employment_type',
        ...self::DETAIL_COLUMNS,
    ];

    /** Longest value each typed column may hold, matching the employee form. */
    private const MAX_LENGTHS = [
        'first_name' => 255,
        'father_name' => 255,
        'grandfather_name' => 255,
        'phone' => 32,
        'email' => 255,
        'nationality' => 100,
        'name_en' => 255,
        'field_of_study' => 150,
        'address' => 500,
        'emergency_contact_name' => 255,
        'emergency_contact_phone' => 50,
    ];

    /** Columns holding a date, each read in the calendar chosen for the file. */
    private const DATE_COLUMNS = ['assignment_start_date', 'date_of_birth', 'service_start_date'];

    /** Marks a national ID kept encrypted on a batch row, as the employee record keeps it. */
    private const SEALED_PREFIX = 'sealed:';

    public const CALENDAR_GREGORIAN = 'gregorian';

    public const CALENDAR_ETHIOPIAN = 'ethiopian';

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

    /** @var array<int, string> hashes of the national IDs earlier rows of this pass carry */
    private array $claimedNationalIds = [];

    /** @var array<string, string>|null lower-case code or name => education level id */
    private ?array $educationLevels = null;

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly RegisterEmployeeAction $registerEmployee,
        private readonly CodeRuleResolver $codeRuleResolver,
        private readonly CodeGeneratorService $codeGeneratorService,
        private readonly WriteAuditLogAction $writeAuditLog,
        private readonly EthiopianCalendarService $ethiopianCalendar,
    ) {}

    /**
     * Read and check an uploaded file, recording the result as a batch.
     *
     * Returns a batch whenever the file could be read, even when every row is
     * bad: the failure record is itself the audit trail. A file that cannot be
     * read at all throws instead.
     *
     * @param  string  $calendar  the calendar `assignment_start_date` is written in
     *
     * @throws EmployeeImportFileException
     */
    public function validate(UploadedFile $file, User $actor, ?Request $request = null, string $calendar = self::CALENDAR_GREGORIAN): EmployeeImportBatch
    {
        $calendar = $calendar === self::CALENDAR_ETHIOPIAN ? self::CALENDAR_ETHIOPIAN : self::CALENDAR_GREGORIAN;
        $rows = $this->readRows($file);
        $this->claimedNationalIds = [];
        $this->educationLevels = null;

        // One transaction: a row written on its own costs a disk flush each, which a 2,000-row file cannot afford.
        $batch = DB::transaction(fn (): EmployeeImportBatch => $this->recordCheck($rows, $file->getClientOriginalName(), $actor, $calendar));

        $this->writeAuditLog->execute(
            eventType: AuditEventType::EmployeeImportValidated,
            actor: $actor,
            auditable: $batch,
            organizationId: $batch->organization_id,
            reason: 'Employee import file checked: '.$batch->valid_rows.' valid, '.$batch->failed_rows.' invalid, '.$batch->unfilledRows().' left empty',
            request: $request,
        );

        return $batch->refresh();
    }

    /**
     * Check every row and write the batch with the outcome of each.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function recordCheck(array $rows, string $fileName, User $actor, string $calendar): EmployeeImportBatch
    {
        $batch = EmployeeImportBatch::query()->create([
            'uploaded_by' => $actor->getKey(),
            'organization_id' => null,
            'file_name' => $fileName,
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
         * supplied in the file are deliberately ignored.
         */
        $claimedNumbers = [];

        /* People already seen in this file, to catch a row pasted twice. */
        $seenPeople = [];

        $validCount = 0;
        $failedCount = 0;
        $unfilledCount = 0;
        $organizationIds = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // Header occupies line 1.

            /*
             * The template lists every vacant position. A row whose employee
             * cells were all left empty is a position nobody was entered for:
             * it is counted and left out, never reported as an error.
             */
            if ($this->isUnfilled($row)) {
                $unfilledCount++;

                continue;
            }

            $placementError = $this->resolveTemplatePlacement($row, $allowedOrganizationIds);
            $errors = $placementError !== null
                ? [$placementError]
                : $this->validateRow($row, $allowedOrganizationIds, $claimedPositions, $claimedNumbers, $calendar, $seenPeople);

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

                $personKey = $this->personKey($row);

                if ($personKey !== null) {
                    $seenPeople[$personKey] = $rowNumber;
                }

                $this->claimNationalId($row);

                $validCount++;
            } else {
                $failedCount++;
            }

            EmployeeImportBatchRow::query()->create([
                'batch_id' => $batch->id,
                'row_number' => $rowNumber,
                // The national ID is encrypted on the employee record; it is not left readable here either.
                'row_data' => $this->sealed($row),
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
            // A file with at least one good row can be imported; the rows with
            // problems are left out and offered back for correction.
            'status' => $validCount > 0
                ? EmployeeImportBatch::STATUS_VALIDATED
                : EmployeeImportBatch::STATUS_FAILED,
            'error_summary' => [
                'calendar' => $calendar,
                'unfilled' => $unfilledCount,
                'over_limit' => $this->skippedRowCount,
            ],
        ])->save();

        return $batch;
    }

    /**
     * Commit the rows of a checked batch that passed.
     *
     * Re-runs every check against live data before writing. Between the check
     * and the confirmation another user may have filled the position, so the
     * check is treated as a proposal rather than a guarantee: a row that no
     * longer passes is marked with its problem and left out, and the rest are
     * still imported.
     *
     * Registering an employee takes many queries (number, assignment, seat,
     * history, audit), so a large file does not fit in one request. With
     * `$secondsBudget` the call imports what it can in that time and commits
     * it; the batch stays importable and the next call carries on from the
     * first row still waiting. Without a budget every row is imported in one
     * go.
     *
     * @return array{imported: int, remaining: int, failed: int, total_imported: int, batch: EmployeeImportBatch}
     */
    public function import(EmployeeImportBatch $batch, User $actor, ?Request $request = null, ?float $secondsBudget = null): array
    {
        $deadline = $secondsBudget === null ? null : microtime(true) + $secondsBudget;

        $allowedOrganizationIds = $this->scope->isUnrestricted($actor)
            ? null
            : $this->scope->accessibleOrganizationIds($actor)->all();

        $calendar = (string) ($batch->error_summary['calendar'] ?? self::CALENDAR_GREGORIAN);
        $this->claimedNationalIds = [];
        $this->educationLevels = null;

        $imported = DB::transaction(function () use ($batch, $actor, $allowedOrganizationIds, $calendar, $deadline): int {
            // Held to the end of the pass: two confirmations of one batch (a second click, a second tab) take turns.
            $batch = EmployeeImportBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! $batch->isImportable()) {
                throw new \RuntimeException('Only a checked batch with valid rows may be imported.');
            }

            $count = 0;
            $handled = 0;
            $claimedPositions = [];
            $claimedNumbers = [];
            $seenPeople = [];

            // Paged by row number, not by offset: the rows change status as the loop goes.
            $validRows = $batch->rows()
                ->where('status', EmployeeImportBatchRow::STATUS_VALID)
                ->lazyById(200, 'row_number');

            foreach ($validRows as $batchRow) {
                // Always at least one row, so every pass moves the batch forward.
                if ($deadline !== null && $handled > 0 && microtime(true) >= $deadline) {
                    break;
                }

                $handled++;
                $row = $this->unsealed($batchRow->row_data);

                // Re-validate against the database as it stands right now.
                $errors = $this->validateRow($row, $allowedOrganizationIds, $claimedPositions, $claimedNumbers, $calendar, $seenPeople);

                if ($errors !== []) {
                    $batchRow->forceFill([
                        'status' => EmployeeImportBatchRow::STATUS_INVALID,
                        'errors' => $errors,
                    ])->save();

                    continue;
                }

                $resolved = $this->resolveRow($row);

                try {
                    // A savepoint, so a row the registration refuses leaves the rows before it in place.
                    $employee = DB::transaction(fn (): Employee => $this->createEmployee($row, $resolved, $actor));
                } catch (ValidationException $e) {
                    $batchRow->forceFill([
                        'status' => EmployeeImportBatchRow::STATUS_INVALID,
                        'errors' => array_values(array_merge(...array_values($e->errors()))),
                    ])->save();

                    continue;
                }

                $claimedPositions[] = $resolved['position']->id;
                $this->claimNationalId($row);

                if ($employee->employee_number !== null) {
                    $claimedNumbers[] = mb_strtolower((string) $employee->employee_number);
                }

                $batchRow->forceFill([
                    'status' => EmployeeImportBatchRow::STATUS_IMPORTED,
                    'employee_id' => $employee->id,
                ])->save();

                $count++;
            }

            $remaining = $batch->rows()->where('status', EmployeeImportBatchRow::STATUS_VALID)->count();
            $total = $batch->importedRows() + $count;

            $batch->forceFill([
                // Still `validated` while rows wait, so the next pass may carry on.
                'status' => match (true) {
                    $remaining > 0 => EmployeeImportBatch::STATUS_VALIDATED,
                    $total > 0 => EmployeeImportBatch::STATUS_IMPORTED,
                    default => EmployeeImportBatch::STATUS_FAILED,
                },
                'failed_rows' => $batch->rows()->where('status', EmployeeImportBatchRow::STATUS_INVALID)->count(),
                'error_summary' => [...($batch->error_summary ?? []), 'imported' => $total],
            ])->save();

            return $count;
        });

        $batch->refresh();
        $remaining = $batch->remainingRows();

        if ($remaining === 0) {
            $this->writeAuditLog->execute(
                eventType: AuditEventType::EmployeeImportCompleted,
                actor: $actor,
                auditable: $batch,
                organizationId: $batch->organization_id,
                reason: 'Employee import completed: '.$batch->importedRows().' employees, '.$batch->failed_rows.' rows left out',
                request: $request,
            );
        }

        return [
            'imported' => $imported,
            'remaining' => $remaining,
            'failed' => $batch->failed_rows,
            'total_imported' => $batch->importedRows(),
            'batch' => $batch,
        ];
    }

    /**
     * The checked rows for the import screen, in the reader's language.
     *
     * Contact details are omitted: this is a bulk view of many people, and an
     * importer needs to confirm placement, not read phone numbers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preview(EmployeeImportBatch $batch, string $locale = 'en'): array
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
        $name = static fn (?string $en, ?string $am): ?string => $locale === 'am' ? ($am ?: $en) : ($en ?: $am);

        return $batch->rows()
            ->orderBy('row_number')
            ->get()
            ->map(function (EmployeeImportBatchRow $batchRow) use (&$organizationsByCode, &$unitsByCode, $name): array {
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
                    'organization' => $resolved === null
                        ? (($row['organization_name'] ?? '') ?: ($row['organization_code'] ?? null))
                        : $name($resolved['organization']->name_en, $resolved['organization']->name_am),
                    'organization_unit' => $resolved === null
                        ? (($row['organization_unit_name'] ?? '') ?: ($row['organization_unit_code'] ?? null))
                        : ($resolved['unit'] === null ? null : $name($resolved['unit']->name_en, $resolved['unit']->name_am)),
                    'position' => $resolved === null
                        ? (($row['position_name'] ?? '') ?: ($row['position_code'] ?? null))
                        : $name($resolved['position']->title_en, $resolved['position']->title_am),
                    'start_date' => $row['_start_date'] ?? null,
                    'status' => $batchRow->status,
                    'errors' => $batchRow->errors ?? [],
                ];
            })
            ->all();
    }

    /**
     * The rows that were left out, as a CSV the importer can correct and
     * upload again.
     *
     * Same columns as the file that was uploaded, plus an `errors` column
     * saying what to fix. The reader ignores that column on the way back in.
     */
    public function failedRowsCsv(EmployeeImportBatch $batch): string
    {
        $rows = $batch->rows()
            ->where('status', EmployeeImportBatchRow::STATUS_INVALID)
            ->orderBy('row_number')
            ->get();

        $usesTemplate = $rows->contains(
            fn (EmployeeImportBatchRow $row): bool => trim((string) ($row->row_data['position_reference'] ?? '')) !== '',
        );
        $columns = $usesTemplate ? self::templateColumns() : self::COLUMNS;

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [...$columns, 'errors'], ',', '"', '');

        foreach ($rows as $batchRow) {
            // Given back as it was typed, so the row can be corrected and uploaded again.
            $data = $this->unsealed($batchRow->row_data);
            $values = array_map(
                fn (string $column): string => $this->spreadsheetSafe((string) ($data[$column] ?? '')),
                $columns,
            );
            $values[] = $this->spreadsheetSafe(implode(' | ', $batchRow->errors ?? []));
            fputcsv($stream, $values, ',', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * Build the starter CSV for an organization.
     *
     * Prefills placement names and an unambiguous reference, never employee
     * details. A BOM lets spreadsheet software open Amharic text as UTF-8.
     *
     * The header row stays in canonical English keys whatever the locale:
     * readRows() matches those keys exactly, so a translated header would make
     * the file we just handed out impossible to upload back. Only the
     * human-readable name columns follow the locale.
     */
    public function templateCsv(Organization $organization, string $locale = 'en', ?OrganizationUnit $unit = null): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::templateColumns(), ',', '"', '');

        foreach ($this->templateRows($organization, $locale, $unit) as $row) {
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
     * The same starter as an Excel workbook.
     *
     * Every cell is stored as text, so Excel neither drops the leading zero of
     * a phone number nor rewrites a typed date into its own format — the two
     * ways a CSV edited in Excel most often comes back damaged.
     */
    public function templateXlsx(Organization $organization, string $locale = 'en', ?OrganizationUnit $unit = null): string
    {
        $columns = self::templateColumns();
        $rows = $this->templateRows($organization, $locale, $unit);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('employees');

        foreach ($columns as $index => $column) {
            $sheet->setCellValueExplicit([$index + 1, 1], $column, DataType::TYPE_STRING);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $index => $value) {
                if ((string) $value !== '') {
                    $sheet->setCellValueExplicit([$index + 1, $rowIndex + 2], (string) $value, DataType::TYPE_STRING);
                }
            }
        }

        $lastColumn = $sheet->getHighestColumn();
        $lastRow = max(count($rows) + 1, 2);
        // Text format on the whole used range, including the cells still empty.
        $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (range(1, count($columns)) as $index) {
            $sheet->getColumnDimensionByColumn($index)->setWidth(22);
        }

        /*
         * A second sheet with what the choice columns accept, one column per
         * field, so nobody has to guess a spelling. Only the first sheet is
         * read back.
         */
        $values = $spreadsheet->createSheet()->setTitle('allowed_values');
        $column = 0;

        foreach ($this->allowedValues($locale) as $field => $options) {
            $column++;
            $values->setCellValueExplicit([$column, 1], $field, DataType::TYPE_STRING);

            foreach (array_values($options) as $index => $option) {
                $values->setCellValueExplicit([$column, $index + 2], $option, DataType::TYPE_STRING);
            }

            $values->getColumnDimensionByColumn($column)->setWidth(26);
        }

        $values->getStyle('A1:'.$values->getHighestColumn().'1')->getFont()->setBold(true);
        $values->freezePane('A2');
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'emp-template');
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /**
     * What each choice column of the file accepts.
     *
     * @return array<string, array<int, string>>
     */
    public function allowedValues(string $locale = 'en'): array
    {
        $amharic = $locale === 'am';

        return [
            'gender' => ['male', 'female'],
            'employment_status' => array_map(static fn (EmployeeStatus $case): string => $case->value, EmployeeStatus::cases()),
            'employment_type' => EmploymentType::values(),
            'marital_status' => array_map(static fn (MaritalStatus $case): string => $case->value, MaritalStatus::cases()),
            'education_level' => EducationLevel::query()
                ->where('is_active', true)
                ->orderBy('rank')
                ->get(['name_en', 'name_am'])
                ->map(static fn (EducationLevel $level): string => (string) ($amharic ? ($level->name_am ?: $level->name_en) : ($level->name_en ?: $level->name_am)))
                ->filter()
                ->values()
                ->all(),
            'has_disability' => ['yes', 'no'],
            'disability_type' => array_map(static fn (DisabilityType $case): string => $case->value, DisabilityType::cases()),
        ];
    }

    /**
     * One template row per position with a free slot under the capacity rule
     * (CON-5); an organization with none still gets one starter row. With
     * `$only`, the positions are those of that unit and the units below it.
     *
     * @return array<int, array<string, string>>
     */
    private function templateRows(Organization $organization, string $locale, ?OrganizationUnit $only = null): array
    {
        $amharic = $locale === 'am';

        $positions = app(PositionCapacityService::class)->whereHasFreeSlot(Position::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->when($only !== null, fn ($query) => $query->whereIn('organization_unit_id', app(OrganizationUnitTreeService::class)->activeSubtreeIds($only))))
            ->where(fn ($query) => $query->whereNull('organization_unit_id')->orWhereHas('organizationUnit', fn ($unit) => $unit->where('organization_id', $organization->id)->where('status', 'active')))
            ->with('organizationUnit:id,code,name_en,name_am')
            ->orderBy('job_position_code')->orderBy('id')
            ->limit($this->maxRows())
            ->get(['id', 'organization_unit_id', 'job_position_code', 'code', 'title_en', 'title_am']);

        $rows = [];

        foreach ($positions->isEmpty() ? [null] : $positions as $position) {
            $row = array_fill_keys(self::templateColumns(), '');
            // Preferred locale first, with the other language as the fallback
            // so a record missing one translation still names something.
            $row['organization_name'] = (string) ($amharic
                ? ($organization->name_am ?: $organization->name_en)
                : ($organization->name_en ?: $organization->name_am));
            $unit = $position?->organizationUnit;
            $row['organization_unit_name'] = (string) (($amharic
                ? ($unit?->name_am ?: $unit?->name_en)
                : ($unit?->name_en ?: $unit?->name_am)) ?? '');
            $row['position_name'] = (string) (($amharic
                ? ($position?->title_am ?: $position?->title_en)
                : ($position?->title_en ?: $position?->title_am)) ?? '');
            $row['position_reference'] = (string) ($position?->id ?? '');
            $row['employment_status'] = 'active';
            $rows[] = $row;
        }

        return $rows;
    }

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

    /** `male` or `female` from the forms an importer is likely to type. */
    private function gender(array $row): ?string
    {
        return match (mb_strtolower(trim((string) ($row['gender'] ?? '')))) {
            'male', 'm', 'ወንድ' => 'male',
            'female', 'f', 'ሴት' => 'female',
            default => null,
        };
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

    /** True when no employee cell of the row was typed into. */
    private function isUnfilled(array $row): bool
    {
        foreach (self::PERSON_COLUMNS as $column) {
            if (trim((string) ($row[$column] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Read the file into an array of associative rows.
     *
     * @return array<int, array<string, string>>
     *
     * @throws EmployeeImportFileException when the file as a whole is unusable
     */
    private function readRows(UploadedFile $file): array
    {
        $this->skippedRowCount = 0;

        $lines = $this->isSpreadsheet($file) ? $this->readSpreadsheetLines($file) : $this->readCsvLines($file);

        $header = array_map(
            static fn ($value): string => Str::of((string) $value)->trim()->lower()->replace(' ', '_')->value(),
            array_shift($lines) ?? [],
        );

        $this->assertUsableHeader($header);

        $columns = array_unique([...self::COLUMNS, ...self::templateColumns()]);
        $positions = [];

        foreach ($columns as $column) {
            $positions[$column] = array_search($column, $header, true);
        }

        $rows = [];

        foreach ($lines as $line) {
            // Skip blank lines that spreadsheets append or leave between rows.
            if (trim(implode('', array_map(static fn ($cell): string => (string) $cell, $line))) === '') {
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

            foreach ($positions as $column => $position) {
                $row[$column] = $position === false ? '' : trim((string) ($line[$position] ?? ''));
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw new EmployeeImportFileException(__('employees.import.errors.emptyFile'));
        }

        return $rows;
    }

    /**
     * A file without the columns the importer reads cannot be checked row by
     * row: every row would fail for the same reason. Say so once, and name the
     * missing columns.
     *
     * @param  array<int, string>  $header
     */
    private function assertUsableHeader(array $header): void
    {
        $missing = array_values(array_diff(['first_name', 'father_name', 'grandfather_name', 'gender', 'employment_status'], $header));

        // Either the template's position reference or the older code columns.
        $hasReference = in_array('position_reference', $header, true);
        $hasCodes = in_array('organization_code', $header, true) && in_array('position_code', $header, true);

        if (! $hasReference && ! $hasCodes) {
            $missing[] = 'position_reference';
        }

        if ($missing !== []) {
            throw new EmployeeImportFileException(
                __('employees.import.errors.missingColumns', ['columns' => implode(', ', $missing)]),
            );
        }
    }

    /** An Excel workbook is a zip archive; a CSV never starts with its marker. */
    private function isSpreadsheet(UploadedFile $file): bool
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            return false;
        }

        $marker = fread($handle, 4);
        fclose($handle);

        return $marker === "PK\x03\x04";
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsvLines(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new EmployeeImportFileException(__('employees.import.errors.unreadable'));
        }

        /*
         * Excel writes a UTF-8 BOM. Left in place it becomes part of the first
         * header name, so that column silently fails to match.
         */
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        /*
         * A CSV saved in a legacy code page arrives with every Amharic letter
         * already replaced or unreadable. Importing it would store broken
         * names, so it is refused with the way to save it correctly.
         */
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new EmployeeImportFileException(__('employees.import.errors.notUtf8'));
        }

        $firstLine = strtok($contents, "\r\n") ?: '';
        // Excel uses a semicolon where the comma is the decimal separator.
        $delimiter = ',';

        foreach ([';', "\t"] as $candidate) {
            if (substr_count($firstLine, $candidate) > substr_count($firstLine, $delimiter)) {
                $delimiter = $candidate;
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $lines = [];

        while (($line = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $lines[] = array_map(static fn ($cell): string => (string) $cell, $line);
        }

        fclose($stream);

        return $lines;
    }

    /**
     * The first sheet of an Excel workbook, as rows of text.
     *
     * @return array<int, array<int, string>>
     */
    private function readSpreadsheetLines(UploadedFile $file): array
    {
        // One row for the header, the cap, and as many again to count what was left out.
        $lastRow = 1 + self::MAX_ROWS * 2;

        try {
            $reader = new XlsxReader;
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class($lastRow) implements IReadFilter
            {
                public function __construct(private readonly int $lastRow) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    // Columns A to AZ are far more than the template uses.
                    return $row <= $this->lastRow && strlen($columnAddress) <= 2 && ($columnAddress < 'BA' || strlen($columnAddress) === 1);
                }
            });

            $spreadsheet = $reader->load($file->getRealPath());
            $cells = $spreadsheet->getSheet(0)->toArray(null, false, false, false);
            $spreadsheet->disconnectWorksheets();
        } catch (Throwable $e) {
            report($e);

            throw new EmployeeImportFileException(__('employees.import.errors.unreadable'));
        }

        $header = array_map(
            static fn ($value): string => Str::of((string) $value)->trim()->lower()->replace(' ', '_')->value(),
            $cells[0] ?? [],
        );
        $dateColumns = array_keys(array_intersect($header, self::DATE_COLUMNS));

        $lines = [];

        foreach ($cells as $line) {
            $lines[] = array_map(function ($cell, $index) use ($dateColumns): string {
                if ($cell === null) {
                    return '';
                }

                /*
                 * A date typed into a date-formatted cell is stored as a day
                 * count. Turn it back into the year, month and day the
                 * importer typed; which calendar they belong to is decided
                 * later, by the calendar chosen for the file.
                 */
                if (in_array($index, $dateColumns, true) && (is_int($cell) || is_float($cell)) && $cell > 0) {
                    return SpreadsheetDate::excelToDateTimeObject($cell)->format('Y-m-d');
                }

                // 911000000.0 is how a phone typed as a number is stored.
                if (is_float($cell) && floor($cell) === $cell && abs($cell) < 1e15) {
                    return (string) (int) $cell;
                }

                return is_bool($cell) ? ($cell ? 'true' : 'false') : (string) $cell;
            }, $line, array_keys($line));
        }

        return $lines;
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
            'organization_name' => [$position->organization->name_en, $position->organization->name_am],
            'organization_unit_name' => [$position->organizationUnit?->name_en, $position->organizationUnit?->name_am],
            'position_name' => [$position->title_en, $position->title_am],
        ];
        foreach ($expected as $key => $translations) {
            // Accept either template language regardless of the current UI locale.
            // A missing translation must not allow a populated name to be erased.
            $names = array_values(array_filter(
                array_map(static fn ($name): string => trim((string) $name), $translations),
                static fn (string $name): bool => $name !== '',
            ));
            $acceptedNames = $names === [] ? [''] : $names;
            foreach ($names as $name) {
                if (preg_match('/^[\s]*[=+@-]/u', $name)) {
                    $acceptedNames[] = "'".$name;
                }
            }
            if (! in_array(trim($row[$key] ?? ''), $acceptedNames, true)) {
                return __('employees.import.errors.templateNames');
            }
        }
        $row['organization_code'] = $position->organization->code;
        $row['organization_unit_code'] = $position->organizationUnit?->code ?? '';
        $row['position_code'] = $position->job_position_code ?: $position->code;

        return null;
    }

    /**
     * Check one row. Returns a list of problems, each naming its column and
     * how to fix it. On success the start date is left on the row as
     * `_start_date`, in the Gregorian form the database stores.
     *
     * @param  array<string, string>  $row
     * @param  array<int, string>|null  $allowedOrganizationIds  null means unrestricted
     * @param  array<int, string>  $claimedPositions
     * @param  array<int, string>  $claimedNumbers
     * @param  array<string, int>  $seenPeople  person key => row number of an earlier row
     * @return array<int, string>
     */
    private function validateRow(
        array &$row,
        ?array $allowedOrganizationIds,
        array $claimedPositions,
        array $claimedNumbers,
        string $calendar = self::CALENDAR_GREGORIAN,
        array $seenPeople = [],
    ): array {
        $errors = [];

        /*
         * `grandfather_name` is required like on the employee form: the
         * database refuses an employee without it, and a row that passed the
         * check used to fail there, taking the whole import down with it.
         */
        foreach (['first_name', 'father_name', 'grandfather_name', 'gender', 'organization_code', 'position_code', 'employment_status'] as $required) {
            if (trim((string) ($row[$required] ?? '')) === '') {
                $errors[] = __('employees.import.errors.required', ['field' => $required]);
            }
        }

        foreach (self::MAX_LENGTHS as $column => $max) {
            if (mb_strlen(trim((string) ($row[$column] ?? ''))) > $max) {
                $errors[] = __('employees.import.errors.tooLong', ['field' => $column, 'max' => $max]);
            }
        }

        $gender = trim((string) ($row['gender'] ?? ''));

        if ($gender !== '' && $this->gender($row) === null) {
            $errors[] = __('employees.import.errors.gender', ['value' => $gender]);
        }

        $status = mb_strtolower(trim((string) ($row['employment_status'] ?? '')));

        if ($status !== '' && EmployeeStatus::tryFrom($status) === null) {
            $errors[] = __('employees.import.errors.employmentStatus', [
                'value' => $row['employment_status'],
                'allowed' => implode(', ', array_map(static fn (EmployeeStatus $case): string => $case->value, EmployeeStatus::cases())),
            ]);
        }

        $type = trim((string) ($row['employment_type'] ?? ''));

        // An unknown type used to be dropped without a word, leaving the employee with none.
        if ($type !== '' && $this->employmentType($row) === null) {
            $errors[] = __('employees.import.errors.employmentType', [
                'value' => $type,
                'allowed' => implode(', ', EmploymentType::values()),
            ]);
        }

        $email = trim((string) ($row['email'] ?? ''));

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('employees.import.errors.email', ['value' => $email]);
        }

        $startDate = trim((string) ($row['assignment_start_date'] ?? ''));
        unset($row['_start_date']);

        if ($startDate !== '') {
            $parsed = $this->parseStartDate($startDate, $calendar);

            if ($parsed === null) {
                $errors[] = __('employees.import.errors.startDate', [
                    'value' => $startDate,
                    'calendar' => __('employees.import.calendars.'.$calendar),
                ]);
            } elseif ($parsed > now()->addYear()->toDateString()) {
                /*
                 * Nobody is placed more than a year ahead by a bulk upload. A
                 * date this far out is nearly always a Gregorian date read as
                 * Ethiopian, which lands seven or eight years late.
                 */
                $errors[] = __('employees.import.errors.startDateFuture', ['value' => $startDate, 'date' => $parsed]);
            } else {
                $row['_start_date'] = $parsed;
            }
        }

        array_push($errors, ...$this->validateDetails($row, $calendar));

        $personKey = $this->personKey($row);

        if ($personKey !== null && isset($seenPeople[$personKey])) {
            $errors[] = __('employees.import.errors.duplicateInFile', ['row' => $seenPeople[$personKey]]);
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

            // Capacity rule (CON-5): approved establishment slots, or one
            // person. Earlier rows of this same file count against it.
            $capacity = app(PositionCapacityService::class);
            $claimedInFile = count(array_keys($claimedPositions, $position->id, true));
            if ($capacity->currentOccupants($position->id) + $claimedInFile >= $capacity->capacity($position->id)) {
                $errors[] = $claimedInFile === 0
                    ? __('employees.import.errors.positionOccupied', ['code' => $position->job_position_code])
                    : __('employees.import.errors.positionDuplicatedInFile', ['code' => $position->job_position_code]);
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
     * Check the optional personal details, by the rules of the employee form.
     * What a later step needs is left on the row under an underscore key:
     * the two dates in Gregorian form, the education level as its id, and the
     * disability declaration as yes, no or not stated.
     *
     * @param  array<string, string>  $row
     * @return array<int, string>
     */
    private function validateDetails(array &$row, string $calendar): array
    {
        $errors = [];
        $text = static fn (string $column): string => trim((string) ($row[$column] ?? ''));
        $today = now()->toDateString();
        unset($row['_date_of_birth'], $row['_service_start_date'], $row['_education_level_id'], $row['_has_disability']);

        $nationalId = $this->nationalId($row);

        if ($text('national_id') !== '') {
            if ($nationalId === null) {
                $errors[] = __('employees.import.errors.nationalId', ['value' => $text('national_id')]);
            } else {
                $hash = NationalIdHash::of($nationalId);

                if (in_array($hash, $this->claimedNationalIds, true)) {
                    $errors[] = __('employees.import.errors.nationalIdDuplicatedInFile');
                } elseif (Employee::query()->where('national_id_hash', $hash)->exists()) {
                    $errors[] = __('employees.import.errors.nationalIdExists');
                }
            }
        }

        $birthDate = null;

        if ($text('date_of_birth') !== '') {
            $birthDate = $this->parseStartDate($text('date_of_birth'), $calendar);

            if ($birthDate === null) {
                $errors[] = __('employees.import.errors.date', [
                    'field' => 'date_of_birth',
                    'value' => $text('date_of_birth'),
                    'calendar' => __('employees.import.calendars.'.$calendar),
                ]);
            } elseif ($birthDate >= $today) {
                $errors[] = __('employees.import.errors.dateNotPast', ['field' => 'date_of_birth', 'value' => $text('date_of_birth'), 'date' => $birthDate]);
                $birthDate = null;
            } else {
                $row['_date_of_birth'] = $birthDate;
            }
        }

        if ($text('service_start_date') !== '') {
            $serviceStart = $this->parseStartDate($text('service_start_date'), $calendar);

            if ($serviceStart === null) {
                $errors[] = __('employees.import.errors.date', [
                    'field' => 'service_start_date',
                    'value' => $text('service_start_date'),
                    'calendar' => __('employees.import.calendars.'.$calendar),
                ]);
            } elseif ($serviceStart > $today) {
                $errors[] = __('employees.import.errors.dateNotPast', ['field' => 'service_start_date', 'value' => $text('service_start_date'), 'date' => $serviceStart]);
            } elseif ($birthDate !== null && $serviceStart <= $birthDate) {
                $errors[] = __('employees.import.errors.serviceStartBeforeBirth');
            } else {
                $row['_service_start_date'] = $serviceStart;
            }
        }

        if ($text('marital_status') !== '' && MaritalStatus::tryFrom(mb_strtolower($text('marital_status'))) === null) {
            $errors[] = __('employees.import.errors.maritalStatus', [
                'value' => $text('marital_status'),
                'allowed' => implode(', ', array_map(static fn (MaritalStatus $case): string => $case->value, MaritalStatus::cases())),
            ]);
        }

        if ($text('education_level') !== '') {
            $levelId = $this->educationLevels()[mb_strtolower($text('education_level'))] ?? null;

            if ($levelId === null) {
                $errors[] = __('employees.import.errors.educationLevel', ['value' => $text('education_level')]);
            } else {
                $row['_education_level_id'] = $levelId;
            }
        }

        $disabilityTypes = implode(', ', array_map(static fn (DisabilityType $case): string => $case->value, DisabilityType::cases()));
        $hasDisability = $this->flag($text('has_disability'));
        $disabilityType = $text('disability_type') === '' ? null : DisabilityType::tryFrom(mb_strtolower($text('disability_type')));

        if ($hasDisability === 'unknown') {
            $errors[] = __('employees.import.errors.hasDisability', ['value' => $text('has_disability')]);
        }

        if ($text('disability_type') !== '' && $disabilityType === null) {
            $errors[] = __('employees.import.errors.disabilityType', ['value' => $text('disability_type'), 'allowed' => $disabilityTypes]);
        } elseif ($disabilityType !== null && $hasDisability === false) {
            $errors[] = __('employees.import.errors.disabilityTypeWithoutDisability');
        } elseif ($disabilityType === null && $hasDisability === true) {
            $errors[] = __('employees.import.errors.disabilityTypeRequired', ['allowed' => $disabilityTypes]);
        }

        // A type on its own says the person has a disability.
        $declared = $hasDisability === null && $disabilityType !== null ? true : $hasDisability;

        if (is_bool($declared)) {
            $row['_has_disability'] = $declared ? 'yes' : 'no';
        }

        if ($text('emergency_contact_phone') !== '' && preg_match('/^[0-9+()\-\s]+$/', $text('emergency_contact_phone')) !== 1) {
            $errors[] = __('employees.import.errors.emergencyPhone', ['value' => $text('emergency_contact_phone')]);
        }

        return $errors;
    }

    /**
     * The 16 digits of `national_id`, without the spaces or hyphens they are
     * often written with. Null when the cell is empty or is not 16 digits.
     *
     * @param  array<string, string>  $row
     */
    private function nationalId(array $row): ?string
    {
        $digits = preg_replace('/[\s-]+/', '', trim((string) ($row['national_id'] ?? '')));

        return is_string($digits) && preg_match('/^[0-9]{16}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Remember a valid row's national ID, so a later row with the same one is refused.
     *
     * @param  array<string, string>  $row
     */
    private function claimNationalId(array $row): void
    {
        $nationalId = $this->nationalId($row);

        if ($nationalId !== null) {
            $this->claimedNationalIds[] = (string) NationalIdHash::of($nationalId);
        }
    }

    /** Yes, no, null for an empty cell, or 'unknown' when the cell says something else. */
    private function flag(string $value): bool|string|null
    {
        return match (mb_strtolower(trim($value))) {
            '' => null,
            'no', 'n', 'false', '0', 'አይ', 'አይደለም', 'የለም' => false,
            'yes', 'y', 'true', '1', 'አዎ', 'አለ' => true,
            default => 'unknown',
        };
    }

    /**
     * Active education levels by their code and by either name.
     *
     * @return array<string, string> lower-case code or name => id
     */
    private function educationLevels(): array
    {
        if ($this->educationLevels !== null) {
            return $this->educationLevels;
        }

        $this->educationLevels = [];

        foreach (EducationLevel::query()->where('is_active', true)->orderBy('rank')->get(['id', 'code', 'name_en', 'name_am']) as $level) {
            foreach ([$level->code, $level->name_en, $level->name_am] as $key) {
                if (trim((string) $key) !== '') {
                    $this->educationLevels[mb_strtolower(trim((string) $key))] ??= (string) $level->id;
                }
            }
        }

        return $this->educationLevels;
    }

    /**
     * A row as it is kept on the batch: the national ID encrypted.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function sealed(array $row): array
    {
        $value = trim((string) ($row['national_id'] ?? ''));

        if ($value !== '' && ! str_starts_with($value, self::SEALED_PREFIX)) {
            $row['national_id'] = self::SEALED_PREFIX.Crypt::encryptString($value);
        }

        return $row;
    }

    /**
     * A kept row with its national ID readable again.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function unsealed(array $row): array
    {
        $value = (string) ($row['national_id'] ?? '');

        if (str_starts_with($value, self::SEALED_PREFIX)) {
            try {
                $row['national_id'] = Crypt::decryptString(substr($value, strlen(self::SEALED_PREFIX)));
            } catch (DecryptException) {
                // The application key changed since the check: the row is checked again and refused, not imported blind.
                $row['national_id'] = '';
            }
        }

        return $row;
    }

    /**
     * Read a date cell as year-month-day or day/month/year in the
     * given calendar, and return it as a Gregorian `Y-m-d`.
     *
     * Null when the text is not a real date in that calendar.
     */
    private function parseStartDate(string $value, string $calendar): ?string
    {
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if ($year < 1900) {
            return null;
        }

        if ($calendar === self::CALENDAR_ETHIOPIAN) {
            return $this->ethiopianCalendar->isValidEthiopianDate($year, $month, $day)
                ? $this->ethiopianCalendar->ethiopianToGregorian($year, $month, $day)->toDateString()
                : null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * What makes two rows the same person: the three names together with a
     * phone or an email. Null when the row carries neither contact, since a
     * name alone is shared by too many people to call a duplicate.
     */
    private function personKey(array $row): ?string
    {
        $contact = mb_strtolower(trim((string) ($row['phone'] ?? ''))) ?: mb_strtolower(trim((string) ($row['email'] ?? '')));
        $name = mb_strtolower(trim(implode(' ', [
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['father_name'] ?? '')),
            trim((string) ($row['grandfather_name'] ?? '')),
        ])));

        return $contact === '' || $name === '' ? null : $name.'|'.$contact;
    }

    /**
     * A cell that would run as a formula when the file is opened in a
     * spreadsheet is written as text. A phone number with a leading plus is
     * left alone so it survives being uploaded again.
     */
    private function spreadsheetSafe(string $value): string
    {
        if (preg_match('/^\s*[=@]/u', $value)) {
            return "'".$value;
        }

        if (preg_match('/^\s*[+-]/u', $value) && ! preg_match('/^\s*[+-]?[\d\s().-]+$/u', $value)) {
            return "'".$value;
        }

        return $value;
    }

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
            // RegisterEmployeeAction runs the configured Code Rule for every
            // row; a file value can never override identity numbering.
            'employee_number' => null,
            '_expected_generated_code' => $row['_generated_employee_number'] ?? null,
            'first_name' => trim((string) ($row['first_name'] ?? '')),
            'middle_name' => trim((string) ($row['father_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($row['grandfather_name'] ?? '')) ?: null,
            'full_name' => implode(' ', $names),
            'gender' => $this->gender($row),
            'phone' => trim((string) ($row['phone'] ?? '')) ?: null,
            'email' => trim((string) ($row['email'] ?? '')) ?: null,
            'status' => EmployeeStatus::tryFrom(mb_strtolower(trim((string) ($row['employment_status'] ?? ''))))
                ?? EmployeeStatus::Active,
            'nationality' => trim((string) ($row['nationality'] ?? '')) ?: null,
            'employment_type' => $this->employmentType($row),
            // The optional details, already checked by validateDetails().
            'name_en' => trim((string) ($row['name_en'] ?? '')) ?: null,
            'national_id' => $this->nationalId($row),
            'date_of_birth' => $row['_date_of_birth'] ?? null,
            'marital_status' => MaritalStatus::tryFrom(mb_strtolower(trim((string) ($row['marital_status'] ?? ''))))?->value,
            'education_level_id' => $row['_education_level_id'] ?? null,
            'field_of_study' => trim((string) ($row['field_of_study'] ?? '')) ?: null,
            'has_disability' => match ($row['_has_disability'] ?? null) {
                'yes' => true,
                'no' => false,
                default => null,
            },
            'disability_type' => ($row['_has_disability'] ?? null) === 'yes'
                ? DisabilityType::tryFrom(mb_strtolower(trim((string) ($row['disability_type'] ?? ''))))?->value
                : null,
            'service_start_date' => $row['_service_start_date'] ?? null,
            'address' => trim((string) ($row['address'] ?? '')) ?: null,
            'emergency_contact_name' => trim((string) ($row['emergency_contact_name'] ?? '')) ?: null,
            'emergency_contact_phone' => trim((string) ($row['emergency_contact_phone'] ?? '')) ?: null,
        ];

        $assignmentAttributes = [
            'organization_id' => $organization->id,
            'organization_unit_id' => $unit?->id ?? $position->organization_unit_id,
            'position_id' => $position->id,
            // Checked and converted to Gregorian by validateRow(); an empty
            // cell means the placement starts today.
            'effective_from' => $row['_start_date'] ?? now()->toDateString(),
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

    /** @param array<string, mixed> $row */
    private function employeeNumber(array $row): ?string
    {
        $generatedNumber = trim((string) ($row['_generated_employee_number'] ?? ''));

        return $generatedNumber !== '' ? $generatedNumber : null;
    }
}
