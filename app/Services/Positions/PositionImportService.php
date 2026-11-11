<?php

declare(strict_types=1);

namespace App\Services\Positions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Positions\CreatePositionAction;
use App\Actions\Positions\EnsureSingleUnitHead;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\GradeLevel;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionImportBatch;
use App\Models\PositionImportBatchRow;
use App\Models\User;
use App\Services\CodeGeneration\CodeRuleResolver;
use App\Services\CodeGeneration\PositionCodeContextResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\OrganizationUnits\OrganizationUnitTreeService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Throwable;

/**
 * Scoped import of positions from a CSV or Excel file.
 *
 * Built like the employee import, in two separate phases:
 *
 *  1. `validate()` reads the file, checks every row and records the outcome as
 *     a batch. Nothing is written to `positions`.
 *  2. `import()` creates the rows that passed, inside one transaction, through
 *     the same action the position form uses, so codes, the one-head rule and
 *     the audit trail are the same as for a position typed by hand.
 *
 * Organization scope is enforced per row: every `organization_code` is
 * re-checked against the importer's own scope.
 */
class PositionImportService
{
    /** Column order of the template. The two name columns are for the reader only. */
    public const COLUMNS = [
        'organization_code',
        'organization_name',
        'organization_unit_code',
        'organization_unit_name',
        'title_en',
        'title_am',
        'occupation_code',
        'grade_level',
        'job_family',
        'old_code',
        'bpr_name',
        'is_unit_head',
    ];

    /** The cells an importer types. A template row with none of them is a unit nobody added a position to. */
    private const POSITION_COLUMNS = [
        'title_en',
        'title_am',
        'occupation_code',
        'grade_level',
        'job_family',
        'old_code',
        'bpr_name',
        'is_unit_head',
    ];

    /** Columns a file must carry to be checked at all. */
    private const REQUIRED_COLUMNS = ['organization_code', 'title_en', 'title_am', 'occupation_code'];

    /** Longest value each typed column may hold, matching the position form. */
    private const MAX_LENGTHS = [
        'title_en' => 255,
        'title_am' => 255,
        'job_family' => 255,
        'bpr_name' => 255,
        'old_code' => 100,
    ];

    /** Guards against a spreadsheet export with a runaway row count. */
    private const MAX_ROWS = 2000;

    /** Rows present in the last file read beyond MAX_ROWS. */
    private int $skippedRowCount = 0;

    /** @var array<string, Organization|null> */
    private array $organizationsByCode = [];

    /** @var array<string, OrganizationUnit|null> */
    private array $unitsByCode = [];

    /** @var array<string, Occupation|null> */
    private array $occupationsByCode = [];

    /** @var array<string, string>|null lower-case name => stored name */
    private ?array $gradeLevels = null;

    /** @var array<string, string|null> organization|unit => why a code cannot be generated there */
    private array $codeProblems = [];

    /** @var array<string, array<string, true>> organization|unit => lower-case titles already there */
    private array $existingTitles = [];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly CreatePositionAction $createPosition,
        private readonly CodeRuleResolver $codeRuleResolver,
        private readonly PositionCodeContextResolver $codeContextResolver,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * Read and check an uploaded file, recording the result as a batch.
     *
     * @throws PositionImportFileException when the file as a whole is unusable
     */
    public function validate(UploadedFile $file, User $actor, ?Request $request = null): PositionImportBatch
    {
        $this->forgetLookups();
        $rows = $this->readRows($file);

        // One transaction: a row written on its own costs a disk flush each, which a 2,000-row file cannot afford.
        $batch = DB::transaction(fn (): PositionImportBatch => $this->recordCheck($rows, $file->getClientOriginalName(), $actor));

        $this->writeAuditLog->execute(
            eventType: AuditEventType::PositionImportValidated,
            actor: $actor,
            auditable: $batch,
            organizationId: $batch->organization_id,
            reason: 'Position import file checked: '.$batch->valid_rows.' valid, '.$batch->failed_rows.' invalid, '.$batch->unfilledRows().' left empty',
            request: $request,
        );

        return $batch->refresh();
    }

    /**
     * Check every row and write the batch with the outcome of each.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function recordCheck(array $rows, string $fileName, User $actor): PositionImportBatch
    {
        $batch = PositionImportBatch::query()->create([
            'uploaded_by' => $actor->getKey(),
            'organization_id' => null,
            'file_name' => $fileName,
            'total_rows' => count($rows),
            'valid_rows' => 0,
            'failed_rows' => 0,
            'status' => PositionImportBatch::STATUS_PENDING,
        ]);

        $allowedOrganizationIds = $this->allowedOrganizationIds($actor);

        // Units (or organizations) an earlier row of this file already gave a head position.
        $claimedHeads = [];
        $validCount = 0;
        $failedCount = 0;
        $unfilledCount = 0;
        $organizationIds = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // Header occupies line 1.

            // The template lists every unit; a row nothing was typed into is skipped, not an error.
            if ($this->isUnfilled($row)) {
                $unfilledCount++;

                continue;
            }

            $resolved = null;
            $errors = $this->checkRow($row, $allowedOrganizationIds, $claimedHeads, $resolved);

            if ($errors === [] && $resolved !== null) {
                $organizationIds[] = $resolved['organization']->id;

                if ($resolved['is_unit_head']) {
                    $claimedHeads[] = $resolved['head_key'];
                }

                $validCount++;
            } else {
                $failedCount++;
            }

            PositionImportBatchRow::query()->create([
                'batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'row_data' => $row,
                'status' => $errors === []
                    ? PositionImportBatchRow::STATUS_VALID
                    : PositionImportBatchRow::STATUS_INVALID,
                'errors' => $errors === [] ? null : $errors,
            ]);
        }

        $uniqueOrganizations = array_values(array_unique($organizationIds));

        $batch->forceFill([
            'organization_id' => count($uniqueOrganizations) === 1 ? $uniqueOrganizations[0] : null,
            'valid_rows' => $validCount,
            'failed_rows' => $failedCount,
            'status' => $validCount > 0
                ? PositionImportBatch::STATUS_VALIDATED
                : PositionImportBatch::STATUS_FAILED,
            'error_summary' => [
                'unfilled' => $unfilledCount,
                'over_limit' => $this->skippedRowCount,
            ],
        ])->save();

        return $batch;
    }

    /**
     * Create the positions of a checked batch.
     *
     * Every row is checked again against live data first: between the check
     * and the confirmation a unit may have been archived or given a head. A
     * row that no longer passes is marked with its problem and left out; the
     * rest are still imported.
     *
     * A position takes many queries to number and audit, so a large file does
     * not fit in one request. With `$secondsBudget` the call imports what it
     * can in that time and commits it; the batch stays importable and the next
     * call carries on from the first row still waiting. Without a budget
     * every row is imported in one go.
     *
     * @return array{imported: int, remaining: int, failed: int, total_imported: int, batch: PositionImportBatch}
     */
    public function import(PositionImportBatch $batch, User $actor, ?Request $request = null, ?float $secondsBudget = null): array
    {
        $this->forgetLookups();
        $allowedOrganizationIds = $this->allowedOrganizationIds($actor);
        $deadline = $secondsBudget === null ? null : microtime(true) + $secondsBudget;

        $imported = DB::transaction(function () use ($batch, $actor, $allowedOrganizationIds, $deadline): int {
            // Held to the end of the pass: two confirmations of one batch (a second click, a second tab) take turns.
            $batch = PositionImportBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! $batch->isImportable()) {
                throw new \RuntimeException('Only a checked batch with valid rows may be imported.');
            }

            $count = 0;
            $handled = 0;
            $claimedHeads = [];

            // Paged by row number, not by offset: the rows change status as the loop goes.
            $validRows = $batch->rows()
                ->where('status', PositionImportBatchRow::STATUS_VALID)
                ->lazyById(200, 'row_number');

            foreach ($validRows as $batchRow) {
                // Always at least one row, so every pass moves the batch forward.
                if ($deadline !== null && $handled > 0 && microtime(true) >= $deadline) {
                    break;
                }

                $handled++;
                $resolved = null;
                $errors = $this->checkRow($batchRow->row_data, $allowedOrganizationIds, $claimedHeads, $resolved);
                $position = null;

                if ($errors === [] && $resolved !== null) {
                    try {
                        // A savepoint, so a refused row leaves the rows before it in place.
                        $position = DB::transaction(fn (): Position => $this->createPosition->execute(
                            $this->positionAttributes($batchRow->row_data, $resolved),
                            $actor,
                        ));
                    } catch (ValidationException $e) {
                        $errors = array_values(array_merge(...array_values($e->errors())));
                    }
                }

                if ($position === null) {
                    $batchRow->forceFill([
                        'status' => PositionImportBatchRow::STATUS_INVALID,
                        'errors' => $errors,
                    ])->save();

                    continue;
                }

                if ($resolved['is_unit_head']) {
                    $claimedHeads[] = $resolved['head_key'];
                }

                $batchRow->forceFill([
                    'status' => PositionImportBatchRow::STATUS_IMPORTED,
                    'position_id' => $position->id,
                ])->save();

                $count++;
            }

            $remaining = $batch->rows()->where('status', PositionImportBatchRow::STATUS_VALID)->count();
            $total = $batch->importedRows() + $count;

            $batch->forceFill([
                // Still `validated` while rows wait, so the next pass may carry on.
                'status' => match (true) {
                    $remaining > 0 => PositionImportBatch::STATUS_VALIDATED,
                    $total > 0 => PositionImportBatch::STATUS_IMPORTED,
                    default => PositionImportBatch::STATUS_FAILED,
                },
                'failed_rows' => $batch->rows()->where('status', PositionImportBatchRow::STATUS_INVALID)->count(),
                'error_summary' => [...($batch->error_summary ?? []), 'imported' => $total],
            ])->save();

            return $count;
        });

        $batch->refresh();
        $remaining = $batch->remainingRows();

        if ($remaining === 0) {
            $this->writeAuditLog->execute(
                eventType: AuditEventType::PositionImportCompleted,
                actor: $actor,
                auditable: $batch,
                organizationId: $batch->organization_id,
                reason: 'Position import completed: '.$batch->importedRows().' positions, '.$batch->failed_rows.' rows left out',
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
     * @return array<int, array<string, mixed>>
     */
    public function preview(PositionImportBatch $batch, string $locale = 'en'): array
    {
        $this->forgetLookups();
        $name = static fn (?string $en, ?string $am): ?string => ($locale === 'am' ? ($am ?: $en) : ($en ?: $am)) ?: null;

        return $batch->rows()
            ->with('position:id,job_position_code')
            ->orderBy('row_number')
            ->get()
            ->map(function (PositionImportBatchRow $batchRow) use ($name, $batch): array {
                $row = $batchRow->row_data;
                $organization = $this->findOrganization($row);
                $unit = $organization === null ? null : $this->findUnit($row, $organization);
                $occupation = $this->findOccupation($row);

                return [
                    // Only a hint: several positions of one title in a unit are normal, a file imported twice is not.
                    'already_exists' => $batchRow->status === PositionImportBatchRow::STATUS_VALID
                        && $organization !== null
                        && $this->titleExists($batch, $organization, $unit, $row),
                    'row_number' => $batchRow->row_number,
                    'title' => $name($row['title_en'] ?? null, $row['title_am'] ?? null),
                    'organization' => $organization === null
                        ? (($row['organization_name'] ?? '') ?: ($row['organization_code'] ?? null))
                        : $name($organization->name_en, $organization->name_am),
                    'organization_unit' => $unit === null
                        ? ((($row['organization_unit_name'] ?? '') ?: ($row['organization_unit_code'] ?? '')) ?: null)
                        : $name($unit->name_en, $unit->name_am),
                    'occupation' => $occupation === null
                        ? (($row['occupation_code'] ?? '') ?: null)
                        : $name($occupation->name_en, $occupation->name_am),
                    'grade_level' => ($row['grade_level'] ?? '') ?: null,
                    'is_unit_head' => $this->flag($row['is_unit_head'] ?? '') === true,
                    'position_code' => $batchRow->position?->job_position_code,
                    'status' => $batchRow->status,
                    'errors' => $batchRow->errors ?? [],
                ];
            })
            ->all();
    }

    /**
     * True when the unit (or the organization itself) already holds a
     * position with the row's English or Amharic title. Positions this batch
     * created are not counted, so an interrupted import does not flag the
     * rows still waiting against the ones it has just imported.
     *
     * @param  array<string, string>  $row
     */
    private function titleExists(PositionImportBatch $batch, Organization $organization, ?OrganizationUnit $unit, array $row): bool
    {
        $key = $organization->id.'|'.($unit?->id ?? '');

        if (! array_key_exists($key, $this->existingTitles)) {
            $titles = [];

            $positions = Position::query()
                ->where('organization_id', $organization->id)
                ->when(
                    $unit !== null,
                    fn ($query) => $query->where('organization_unit_id', $unit->id),
                    fn ($query) => $query->whereNull('organization_unit_id'),
                )
                ->whereNotIn('id', PositionImportBatchRow::query()
                    ->where('batch_id', $batch->id)
                    ->whereNotNull('position_id')
                    ->select('position_id'))
                ->get(['title_en', 'title_am']);

            foreach ($positions as $position) {
                foreach ([$position->title_en, $position->title_am] as $title) {
                    if (trim((string) $title) !== '') {
                        $titles[mb_strtolower(trim((string) $title))] = true;
                    }
                }
            }

            $this->existingTitles[$key] = $titles;
        }

        foreach (['title_en', 'title_am'] as $column) {
            $title = mb_strtolower(trim((string) ($row[$column] ?? '')));

            if ($title !== '' && isset($this->existingTitles[$key][$title])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows that were left out, as a CSV to correct and upload again. The
     * extra `errors` column is ignored on the way back in.
     */
    public function failedRowsCsv(PositionImportBatch $batch): string
    {
        $rows = $batch->rows()
            ->where('status', PositionImportBatchRow::STATUS_INVALID)
            ->orderBy('row_number')
            ->get()
            ->map(fn (PositionImportBatchRow $batchRow): array => [
                ...array_map(static fn (string $column): string => (string) ($batchRow->row_data[$column] ?? ''), self::COLUMNS),
                implode(' | ', $batchRow->errors ?? []),
            ])
            ->all();

        return $this->csv([...self::COLUMNS, 'errors'], $rows);
    }

    /**
     * The starter CSV for an organization. The header stays in the canonical
     * English keys whatever the locale, because the reader matches them
     * exactly; only the name columns follow the locale. With a unit, the
     * template lists that unit and the units below it only.
     */
    public function templateCsv(Organization $organization, string $locale = 'en', ?OrganizationUnit $unit = null): string
    {
        return $this->csv(self::COLUMNS, array_map('array_values', $this->templateRows($organization, $locale, $unit)));
    }

    /**
     * The same starter as an Excel workbook, every cell stored as text. Two
     * more sheets list the occupation codes and grade levels a row may use;
     * only the first sheet is read back.
     */
    public function templateXlsx(Organization $organization, string $locale = 'en', ?OrganizationUnit $unit = null): string
    {
        $amharic = $locale === 'am';
        $spreadsheet = new Spreadsheet;

        $this->fillSheet($spreadsheet->getActiveSheet()->setTitle('positions'), self::COLUMNS, array_map('array_values', $this->templateRows($organization, $locale, $unit)));

        $occupations = Occupation::query()
            ->orderBy('isco_code')->orderBy('code')
            ->get(['code', 'isco_code', 'name_en', 'name_am'])
            ->map(static fn (Occupation $occupation): array => [
                (string) ($occupation->isco_code ?: $occupation->code),
                (string) ($amharic ? ($occupation->name_am ?: $occupation->name_en) : ($occupation->name_en ?: $occupation->name_am)),
            ])
            ->all();
        $this->fillSheet($spreadsheet->createSheet()->setTitle('occupations'), ['occupation_code', 'occupation_name'], $occupations);

        $grades = array_map(static fn (string $grade): array => [$grade], array_values($this->gradeLevels()));
        $this->fillSheet($spreadsheet->createSheet()->setTitle('grade_levels'), ['grade_level'], $grades);

        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'pos-template');
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /** The largest file this importer will read, in rows. */
    public function maxRows(): int
    {
        return self::MAX_ROWS;
    }

    /**
     * One starter row for the organization itself (positions placed in no
     * unit) and one per active unit. A row may be copied to add more
     * positions to the same unit.
     *
     * With `$only`, the rows are that unit and the active units below it, and
     * the row for the organization itself is left out: whoever asks for one
     * directorate's template is not placing positions above it.
     *
     * @return array<int, array<string, string>>
     */
    private function templateRows(Organization $organization, string $locale, ?OrganizationUnit $only = null): array
    {
        $amharic = $locale === 'am';
        $name = static fn (?string $en, ?string $am): string => (string) ($amharic ? ($am ?: $en) : ($en ?: $am));

        $units = OrganizationUnit::query()
            ->where('organization_id', $organization->id)
            ->active()
            ->when($only !== null, fn ($query) => $query->whereIn('id', app(OrganizationUnitTreeService::class)->activeSubtreeIds($only)))
            ->orderBy('code')
            ->limit(self::MAX_ROWS - 1)
            ->get(['id', 'code', 'name_en', 'name_am']);

        $rows = [];

        foreach ($only === null ? [null, ...$units->all()] : $units->all() as $unit) {
            $row = array_fill_keys(self::COLUMNS, '');
            $row['organization_code'] = (string) $organization->code;
            $row['organization_name'] = $name($organization->name_en, $organization->name_am);
            $row['organization_unit_code'] = (string) ($unit?->code ?? '');
            $row['organization_unit_name'] = $unit === null ? '' : $name($unit->name_en, $unit->name_am);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Check one row. Returns the problems, each naming its column and how to
     * fix it; on success `$resolved` carries the records the row points at.
     *
     * @param  array<string, string>  $row
     * @param  array<int, string>|null  $allowedOrganizationIds  null means unrestricted
     * @param  array<int, string>  $claimedHeads
     * @param  array<string, mixed>|null  $resolved
     * @return array<int, string>
     */
    private function checkRow(array $row, ?array $allowedOrganizationIds, array $claimedHeads, ?array &$resolved): array
    {
        $resolved = null;
        $errors = [];

        foreach (self::REQUIRED_COLUMNS as $required) {
            if (trim((string) ($row[$required] ?? '')) === '') {
                $errors[] = __('positions.import.errors.required', ['field' => $required]);
            }
        }

        foreach (self::MAX_LENGTHS as $column => $max) {
            if (mb_strlen(trim((string) ($row[$column] ?? ''))) > $max) {
                $errors[] = __('positions.import.errors.tooLong', ['field' => $column, 'max' => $max]);
            }
        }

        $occupationCode = trim((string) ($row['occupation_code'] ?? ''));
        $occupation = $this->findOccupation($row);

        if ($occupationCode !== '' && $occupation === null) {
            $errors[] = __('positions.import.errors.occupationNotFound', ['code' => $occupationCode]);
        }

        $gradeText = trim((string) ($row['grade_level'] ?? ''));
        $grade = $gradeText === '' ? null : ($this->gradeLevels()[mb_strtolower($gradeText)] ?? null);

        if ($gradeText !== '' && $grade === null) {
            $errors[] = __('positions.import.errors.gradeLevelNotFound', ['value' => $gradeText]);
        }

        $isHead = $this->flag($row['is_unit_head'] ?? '');

        if ($isHead === null) {
            $errors[] = __('positions.import.errors.unitHead', ['value' => $row['is_unit_head']]);
        }

        $organizationCode = trim((string) ($row['organization_code'] ?? ''));
        $organization = $this->findOrganization($row);

        if ($organization === null) {
            if ($organizationCode !== '') {
                $errors[] = __('positions.import.errors.organizationNotFound', ['code' => $organizationCode]);
            }

            // Everything below depends on the organization.
            return $errors;
        }

        // A merged, dissolved or archived organization takes no new positions; the template is offered for active ones only.
        if ($this->statusValue($organization->status) !== 'active') {
            $errors[] = __('positions.import.errors.organizationNotActive', ['code' => $organization->code]);

            return $errors;
        }

        // The scope gate: a row outside the importer's organizations is refused whatever the file says.
        if ($allowedOrganizationIds !== null && ! in_array($organization->id, $allowedOrganizationIds, true)) {
            $errors[] = __('positions.import.errors.outsideScope', ['code' => $organization->code]);

            return $errors;
        }

        $unitCode = trim((string) ($row['organization_unit_code'] ?? ''));
        $unit = $this->findUnit($row, $organization);

        if ($unitCode !== '' && $unit === null) {
            $errors[] = __('positions.import.errors.unitNotInOrganization', ['code' => $unitCode]);

            return $errors;
        }

        $headKey = $unit?->id ?? 'organization:'.$organization->id;

        if ($isHead === true) {
            if (in_array($headKey, $claimedHeads, true)) {
                $errors[] = __('positions.import.errors.headDuplicatedInFile');
            } else {
                $current = Position::query()
                    ->where('is_unit_head', true)
                    ->when(
                        $unit !== null,
                        fn ($query) => $query->where('organization_unit_id', $unit->id),
                        fn ($query) => $query->where('organization_id', $organization->id)->whereNull('organization_unit_id'),
                    )
                    ->first(['id', 'title_en', 'title_am', 'job_position_code']);

                if ($current !== null) {
                    $errors[] = __($unit !== null ? 'positions.unit_head_taken' : 'positions.organization_head_taken', [
                        'position' => EnsureSingleUnitHead::describe($current),
                    ]);
                }
            }
        }

        $codeProblem = $this->codeProblem($organization, $unit);

        if ($codeProblem !== null) {
            $errors[] = $codeProblem;
        }

        if ($errors === []) {
            $resolved = [
                'organization' => $organization,
                'unit' => $unit,
                'occupation' => $occupation,
                'grade_level' => $grade,
                'is_unit_head' => $isHead === true,
                'head_key' => $headKey,
            ];
        }

        return $errors;
    }

    /**
     * Why a position code cannot be generated for this placement, or null
     * when it can. Asked once per organization and unit.
     */
    private function codeProblem(Organization $organization, ?OrganizationUnit $unit): ?string
    {
        $key = $organization->id.'|'.($unit?->id ?? '');

        if (array_key_exists($key, $this->codeProblems)) {
            return $this->codeProblems[$key];
        }

        try {
            $owners = $this->codeContextResolver->validateForGeneration((string) $organization->id, $unit?->id);
        } catch (ValidationException $e) {
            return $this->codeProblems[$key] = (string) collect($e->errors())->flatten()->first();
        }

        $rule = $this->codeRuleResolver->resolve(CodeRuleEntityType::Position, [
            'organization_id' => $organization->id,
            'organization_unit_id' => $unit?->id,
            'owner_organization_id' => $owners['owner_organization_id'],
            'host_organization_id' => $owners['host_organization_id'],
        ]);

        return $this->codeProblems[$key] = $rule === null ? __('code-rules.no_active_rule') : null;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function positionAttributes(array $row, array $resolved): array
    {
        $text = static fn (string $column): ?string => trim((string) ($row[$column] ?? '')) ?: null;

        return [
            // Left empty so the configured code rule numbers the position.
            'job_position_code' => null,
            'title_en' => $text('title_en'),
            'title_am' => $text('title_am'),
            'organization_id' => $resolved['organization']->id,
            'organization_unit_id' => $resolved['unit']?->id,
            'occupation_id' => $resolved['occupation']->id,
            'grade_level' => $resolved['grade_level'],
            'job_family' => $text('job_family'),
            'old_code' => $text('old_code'),
            'bpr_name' => $text('bpr_name'),
            'is_active' => true,
            'is_unit_head' => $resolved['is_unit_head'],
        ];
    }

    /** Yes, no, or null when the cell says something else. An empty cell is no. */
    private function flag(mixed $value): ?bool
    {
        return match (mb_strtolower(trim((string) $value))) {
            '', 'no', 'n', 'false', '0', 'አይ', 'አይደለም' => false,
            'yes', 'y', 'true', '1', 'አዎ' => true,
            default => null,
        };
    }

    /** True when no position cell of the row was typed into. */
    private function isUnfilled(array $row): bool
    {
        foreach (self::POSITION_COLUMNS as $column) {
            if (trim((string) ($row[$column] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string>|null null means unrestricted */
    private function allowedOrganizationIds(User $actor): ?array
    {
        return $this->scope->isUnrestricted($actor)
            ? null
            : $this->scope->accessibleOrganizationIds($actor)->all();
    }

    private function forgetLookups(): void
    {
        $this->organizationsByCode = [];
        $this->unitsByCode = [];
        $this->occupationsByCode = [];
        $this->gradeLevels = null;
        $this->codeProblems = [];
        $this->existingTitles = [];
    }

    /** @param array<string, string> $row */
    private function findOrganization(array $row): ?Organization
    {
        $code = $this->code($row['organization_code'] ?? '');

        if ($code === '') {
            return null;
        }

        return $this->organizationsByCode[$code] ??= Organization::query()->where('code', $code)->first();
    }

    /** @param array<string, string> $row */
    private function findUnit(array $row, Organization $organization): ?OrganizationUnit
    {
        $code = $this->code($row['organization_unit_code'] ?? '');

        if ($code === '') {
            return null;
        }

        return $this->unitsByCode[$organization->id.'|'.$code] ??= OrganizationUnit::query()
            ->where('organization_id', $organization->id)
            ->where('code', $code)
            ->active()
            ->first();
    }

    /**
     * The occupation a row names, by its ISCO code or its own code. A code
     * typed into a number cell loses its leading zero, so a short number is
     * tried again padded to four digits.
     *
     * @param  array<string, string>  $row
     */
    private function findOccupation(array $row): ?Occupation
    {
        $code = $this->code($row['occupation_code'] ?? '');

        if ($code === '') {
            return null;
        }

        if (array_key_exists($code, $this->occupationsByCode)) {
            return $this->occupationsByCode[$code];
        }

        $candidates = [$code];

        if (ctype_digit($code) && strlen($code) < 4) {
            $candidates[] = str_pad($code, 4, '0', STR_PAD_LEFT);
        }

        return $this->occupationsByCode[$code] = Occupation::query()
            ->where(fn ($query) => $query->whereIn('isco_code', $candidates)->orWhereIn('code', $candidates))
            ->orderByRaw('CASE WHEN isco_code = ? THEN 0 ELSE 1 END', [$code])
            ->first();
    }

    /** A status column, whether the model casts it to an enum or leaves it a string. */
    private function statusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    /** A code cell, without the apostrophe a spreadsheet-safe export may have put in front. */
    private function code(mixed $value): string
    {
        return ltrim(trim((string) $value), "'");
    }

    /** @return array<string, string> lower-case name => stored name */
    private function gradeLevels(): array
    {
        if ($this->gradeLevels !== null) {
            return $this->gradeLevels;
        }

        $this->gradeLevels = [];

        foreach (GradeLevel::query()->orderBy('name')->pluck('name') as $grade) {
            $this->gradeLevels[mb_strtolower((string) $grade)] = (string) $grade;
        }

        return $this->gradeLevels;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $rows
     */
    private function csv(array $columns, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        // A BOM lets spreadsheet software open Amharic text as UTF-8.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $columns, ',', '"', '');

        foreach ($rows as $row) {
            // A cell that would run as a formula in a spreadsheet is written as text.
            fputcsv($stream, array_map(
                static fn ($value): string => preg_match('/^\s*[=+@-]/u', (string) $value) ? "'".$value : (string) $value,
                $row,
            ), ',', '"', '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $rows
     */
    private function fillSheet(Worksheet $sheet, array $columns, array $rows): void
    {
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
        // Text format on the whole used range, so Excel keeps codes as typed.
        $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (range(1, count($columns)) as $index) {
            $sheet->getColumnDimensionByColumn($index)->setWidth(24);
        }
    }

    /**
     * Read the file into an array of associative rows.
     *
     * @return array<int, array<string, string>>
     *
     * @throws PositionImportFileException when the file as a whole is unusable
     */
    private function readRows(UploadedFile $file): array
    {
        $this->skippedRowCount = 0;

        $lines = $this->isSpreadsheet($file) ? $this->readSpreadsheetLines($file) : $this->readCsvLines($file);

        $header = array_map(
            static fn ($value): string => Str::of((string) $value)->trim()->lower()->replace(' ', '_')->value(),
            array_shift($lines) ?? [],
        );

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $header));

        if ($missing !== []) {
            throw new PositionImportFileException(
                __('positions.import.errors.missingColumns', ['columns' => implode(', ', $missing)]),
            );
        }

        $positions = [];

        foreach (self::COLUMNS as $column) {
            $positions[$column] = array_search($column, $header, true);
        }

        $rows = [];

        foreach ($lines as $line) {
            // Skip blank lines that spreadsheets append or leave between rows.
            if (trim(implode('', $line)) === '') {
                continue;
            }

            // Past the cap, keep counting so the importer can be told how many rows were left out.
            if (count($rows) >= self::MAX_ROWS) {
                $this->skippedRowCount++;

                continue;
            }

            $row = [];

            foreach ($positions as $column => $position) {
                $cell = $position === false ? '' : trim((string) ($line[$position] ?? ''));
                // The rows-to-fix file guards a cell that starts like a formula with an apostrophe; take it off again.
                $row[$column] = preg_match('/^\'\s*[=+@-]/u', $cell) === 1 ? substr($cell, 1) : $cell;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw new PositionImportFileException(__('positions.import.errors.emptyFile'));
        }

        return $rows;
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

    /** @return array<int, array<int, string>> */
    private function readCsvLines(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new PositionImportFileException(__('positions.import.errors.unreadable'));
        }

        // Excel writes a UTF-8 BOM; left in place it becomes part of the first header name.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        // A CSV saved in a legacy code page arrives with the Amharic letters already damaged.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new PositionImportFileException(__('positions.import.errors.notUtf8'));
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
                    // Columns A to Z are far more than the template uses.
                    return $row <= $this->lastRow && strlen($columnAddress) === 1;
                }
            });

            $spreadsheet = $reader->load($file->getRealPath());
            $cells = $spreadsheet->getSheet(0)->toArray(null, false, false, false);
            $spreadsheet->disconnectWorksheets();
        } catch (Throwable $e) {
            report($e);

            throw new PositionImportFileException(__('positions.import.errors.unreadable'));
        }

        return array_map(static fn (array $line): array => array_map(static function ($cell): string {
            if ($cell === null) {
                return '';
            }

            // 2411.0 is how a code typed into a number cell is stored.
            if (is_float($cell) && floor($cell) === $cell && abs($cell) < 1e15) {
                return (string) (int) $cell;
            }

            return is_bool($cell) ? ($cell ? 'true' : 'false') : (string) $cell;
        }, $line), $cells);
    }
}
