<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AssignmentStatus;
use App\Enums\CodeRuleEntityType;
use App\Enums\EmployeeStatus;
use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\GradeLevel;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Services\CodeGeneration\PositionCodeContextResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Validates a parsed workbook against the domain rules and the current
 * database, producing a {@see StructureImportPlan}.
 *
 * Everything is checked in one pass and reported together — the wizard's whole
 * point is to show the user *all* the problems before anything is written, so
 * no rule short-circuits the rest.
 *
 * References may point either at rows in the same file or at existing database
 * records (e.g. a unit's parent may be a unit defined two rows above it, or one
 * that already exists in the organization). Both are resolved here.
 */
class OrganizationStructureImportValidator
{
    private const MAX_RANDOM_CODE_ATTEMPTS = 20;

    /** Bound on the parent-chain walk when looking for cycles. */
    private const MAX_UNIT_DEPTH = 50;

    public function __construct(
        private readonly OrganizationScopeService $organizationScopeService,
        private readonly CodeProjectorFactory $codeProjectorFactory,
    ) {}

    /**
     * @param  bool  $autoGenerateCodes  when false, a blank code column is a row
     *                                   error instead of a generation request
     * @param  array<string, string>  $lockedRandomCodes
     */
    public function validate(
        StructureWorkbook $workbook,
        User $actor,
        bool $autoGenerateCodes = true,
        array $lockedRandomCodes = [],
    ): StructureImportPlan {
        $issues = $workbook->structuralIssues();

        // A workbook that is missing a required sheet or column cannot be
        // meaningfully row-validated — the columns the rules read may not exist.
        if ($issues !== []) {
            return new StructureImportPlan($issues);
        }

        $organizationRow = $workbook->organizationRow();

        if ($organizationRow === null) {
            $issues[] = ImportIssue::error(
                StructureSheet::Organization,
                null,
                __('organization-structure-import.errors.organization_sheet_empty'),
            );

            return new StructureImportPlan($issues);
        }

        $organizationTypeIds = $this->organizationTypeIds();
        $unitTypeIds = $this->unitTypeIds();
        $gradeLevelNames = $this->gradeLevelNames();
        $occupationIds = $this->occupationIds();

        // Codes are projected, never reserved: this asks the Code Rule engine
        // what each blank cell *would* get, without consuming a sequence.
        $projector = $this->codeProjectorFactory->make($lockedRandomCodes);

        // ── Organization sheet ────────────────────────────────────────────
        $organizationCode = $this->str($organizationRow['organization_code'] ?? null);
        $existingOrganization = $organizationCode !== null
            ? Organization::query()->where('code', $organizationCode)->first()
            : null;

        [$organizationIssues, $organizationCodeAssignment] = $this->validateOrganization(
            $organizationRow,
            $existingOrganization,
            $organizationTypeIds,
            $actor,
            $projector,
            $autoGenerateCodes,
        );

        $issues = [...$issues, ...$organizationIssues];

        // The code the organization will actually carry — generated or provided.
        // Every downstream reference (unit host/owner, position owner) resolves
        // against this, so a generated organization code is usable immediately.
        $effectiveOrganizationCode = $organizationCodeAssignment?->code ?? $organizationCode;

        // Organizations referenced by code from the Units sheet (host /
        // functional owner) — resolved up-front so each row check is a lookup.
        $organizationIdsByCode = $this->referencedOrganizationIds(
            $workbook,
            $effectiveOrganizationCode,
            $existingOrganization,
        );

        // ── Organization Units sheet ──────────────────────────────────────
        $unitRows = $workbook->rows(StructureSheet::OrganizationUnits)->all();

        [$unitIssues, $unitCodeAssignments, $unitRows] = $this->validateUnits(
            $unitRows,
            $existingOrganization,
            $unitTypeIds,
            $organizationIdsByCode,
            $projector,
            $autoGenerateCodes,
            $actor,
        );
        $issues = [...$issues, ...$unitIssues];

        // ── Positions sheet ───────────────────────────────────────────────
        $positionRows = $workbook->rows(StructureSheet::Positions)->all();

        [$positionIssues, $positionCodeAssignments, $positionRows] = $this->validatePositions(
            $positionRows,
            $unitRows,
            $existingOrganization,
            $effectiveOrganizationCode,
            $gradeLevelNames,
            $occupationIds,
            $projector,
            $autoGenerateCodes,
            $actor,
        );
        $issues = [...$issues, ...$positionIssues];

        // ── Employees sheet (optional) ────────────────────────────────────
        $employeeRows = $workbook->hasSheet(StructureSheet::Employees)
            ? $workbook->rows(StructureSheet::Employees)->all()
            : [];

        [$employeeIssues, $employeeCodeAssignments, $employeeRows] = $this->validateEmployees(
            $employeeRows,
            $positionRows,
            $existingOrganization,
            $projector,
            $autoGenerateCodes,
            $actor,
        );
        $issues = [...$issues, ...$employeeIssues];

        return new StructureImportPlan(
            issues: $issues,
            organizationRow: $organizationRow,
            existingOrganization: $existingOrganization,
            organizationTypeIds: $organizationTypeIds,
            unitTypeIds: $unitTypeIds,
            gradeLevelNames: $gradeLevelNames,
            occupationIds: $occupationIds,
            organizationIdsByCode: $organizationIdsByCode,
            unitRows: $this->sortUnitsParentFirst($unitRows),
            positionRows: $positionRows,
            employeeRows: $employeeRows,
            organizationCode: $organizationCodeAssignment,
            unitCodes: $unitCodeAssignments,
            positionCodes: $positionCodeAssignments,
            employeeCodes: $employeeCodeAssignments,
            autoGenerateCodes: $autoGenerateCodes,
        );
    }

    // ── Organization ─────────────────────────────────────────────────────────

    /**
     * @return array{0: list<ImportIssue>, 1: CodeAssignment|null}
     */
    private function validateOrganization(
        array $row,
        ?Organization $existing,
        array $organizationTypeIds,
        User $actor,
        CodeProjector $projector,
        bool $autoGenerateCodes,
    ): array {
        $issues = [];
        $sheet = StructureSheet::Organization;
        $rowNumber = (int) $row['__row'];

        $code = $this->str($row['organization_code'] ?? null);
        $nameEn = $this->str($row['organization_name_en'] ?? null);
        $typeCode = $this->upper($row['organization_type_code'] ?? null);
        $parentCode = $this->str($row['parent_organization_code'] ?? null);
        $status = $this->lower($row['status'] ?? null);

        if ($nameEn === null) {
            $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.organization_name_required'), 'organization_name_en');
        }

        $organizationTypeId = null;

        if ($typeCode === null) {
            $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.organization_type_required'), 'organization_type_code');
        } elseif (! array_key_exists($typeCode, $organizationTypeIds)) {
            $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.organization_type_not_found', ['code' => $typeCode]), 'organization_type_code');
        } else {
            $organizationTypeId = $organizationTypeIds[$typeCode];
        }

        if ($status !== null && OrganizationStatus::tryFrom($status) === null) {
            $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.invalid_status', ['value' => $status]), 'status');
        }

        if ($parentCode !== null && ! Organization::query()->where('code', $parentCode)->exists()) {
            $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.parent_organization_not_found', ['code' => $parentCode]), 'parent_organization_code');
        }

        // ── Code: provided → validate; blank → generate ───────────────────
        // The code rule needs the organization type to resolve {ORG_TYPE_PREFIX}
        // and to scope the sequence, so generation is only attempted once the
        // type has resolved.
        [$codeIssues, $assignment] = $this->resolveCode(
            $sheet,
            $rowNumber,
            (string) ($nameEn ?? ''),
            $code,
            CodeRuleEntityType::Organization,
            ['organization_type_id' => $organizationTypeId],
            $projector,
            $autoGenerateCodes,
            canGenerate: $organizationTypeId !== null,
            isDuplicate: fn (string $candidate): bool => $existing === null
                && Organization::query()->where('code', $candidate)->exists(),
            actor: $actor,
        );

        $issues = [...$issues, ...$codeIssues];

        // Scoped users may only import into organizations they can reach. For a
        // brand-new organization there is nothing to scope-check yet — creation
        // is governed by the `organizations.import` permission alone.
        if ($existing !== null && ! $this->organizationScopeService->canAccessOrganization($actor, $existing->id)) {
            $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.organization_out_of_scope', ['code' => (string) $code]), 'organization_code');
        }

        if ($existing !== null) {
            $issues[] = ImportIssue::warning($sheet, $rowNumber, __('organization-structure-import.warnings.organization_will_be_updated', ['code' => (string) $code]), 'organization_code');
        }

        return [$issues, $assignment];
    }

    /**
     * Owner/host organization code for each unit in the file.
     *
     * Mirrors {@see PositionCodeContextResolver}:
     * a unit lives inside its HOST organization while its mandate may be owned
     * by another (the functional owner). A position in a plain unit is
     * OWNER/SEQUENCE; one in a hosted unit is OWNER/HOST/SEQUENCE.
     *
     * Children inherit from their ancestors, because the hosting relationship is
     * recorded on the top office unit only. Existing (non-file) units fall back
     * to the importing organization — the DB resolver handles those at write
     * time.
     *
     * @return array<string, array{owner: string|null, host: string|null}> lowercased unit code => pair
     */
    private function unitOwnerHostCodes(array $unitRows, ?string $organizationCode): array
    {
        $byCode = [];
        $parentOf = [];

        foreach ($unitRows as $row) {
            $code = $this->str($row['__code'] ?? null);

            if ($code === null) {
                continue;
            }

            $key = mb_strtolower($code);
            $byCode[$key] = $row;

            $parent = $this->str($row['parent_unit_code'] ?? null);
            $parentOf[$key] = $parent !== null ? mb_strtolower($parent) : null;
        }

        $resolved = [];

        foreach (array_keys($byCode) as $key) {
            $current = $key;
            $depth = 0;
            $owner = null;
            $host = null;

            // Walk up until a unit declares a host/owner, or the chain ends.
            while ($current !== null && isset($byCode[$current]) && $depth++ < self::MAX_UNIT_DEPTH) {
                $row = $byCode[$current];

                $rowHost = $this->str($row['host_organization_code'] ?? null);
                $rowOwner = $this->str($row['functional_owner_organization_code'] ?? null);

                if ($rowHost !== null || $rowOwner !== null) {
                    // The unit physically sits in its host (or in the importing
                    // organization when no host is named); the mandate belongs
                    // to the functional owner when one is named.
                    $host = $rowHost ?? $organizationCode;
                    $owner = $rowOwner ?? $host;
                    break;
                }

                $current = $parentOf[$current] ?? null;
            }

            // No hosting anywhere up the chain: a plain internal unit. Owner is
            // the importing organization and there is no host segment.
            if ($owner === null) {
                $resolved[$key] = ['owner' => $organizationCode, 'host' => null];

                continue;
            }

            // Owner == host means the unit is not really hosted elsewhere, so
            // the code collapses back to OWNER/SEQUENCE.
            $resolved[$key] = [
                'owner' => $owner,
                'host' => ($host !== null && mb_strtolower($host) !== mb_strtolower($owner)) ? $host : null,
            ];
        }

        return $resolved;
    }

    // ── Code resolution (shared by all four sheets) ──────────────────────────

    /**
     * The one place that decides what a row's code column becomes.
     *
     * Provided  → keep it, validate it is not a duplicate.
     * Blank     → project the next code from the entity's Code Rule.
     *
     * Generation never happens here in any final sense: {@see CodeProjector}
     * only *projects* what the rule would produce, so a preview burns no
     * sequence numbers. The confirm step regenerates under a lock and compares.
     *
     * @param  callable(string): bool  $isDuplicate  does this code already exist in the DB?
     * @param  bool  $canGenerate  false when the context the rule needs is itself invalid
     * @return array{0: list<ImportIssue>, 1: CodeAssignment|null}
     */
    private function resolveCode(
        StructureSheet $sheet,
        int $rowNumber,
        string $name,
        ?string $providedCode,
        CodeRuleEntityType $entityType,
        array $context,
        CodeProjector $projector,
        bool $autoGenerateCodes,
        bool $canGenerate,
        callable $isDuplicate,
        ?User $actor = null,
    ): array {
        $column = $sheet->codeColumn();

        // ── Provided ──────────────────────────────────────────────────────
        if ($providedCode !== null) {
            if ($isDuplicate($providedCode)) {
                return [[ImportIssue::error(
                    $sheet,
                    $rowNumber,
                    __('organization-structure-import.errors.duplicate_provided_code', ['code' => $providedCode]),
                    $column,
                )], null];
            }

            // A typed-in code IS a manual override, and the Code Rule decides
            // whether one is allowed. Check that here rather than letting the
            // import blow up half-way through the transaction — GenerateCodeAction
            // throws on exactly these two conditions at write time.
            $rule = $projector->rule($entityType, $context);

            if ($rule !== null && ! $this->actorMayOverride($rule, $actor)) {
                return [[ImportIssue::error(
                    $sheet,
                    $rowNumber,
                    __('organization-structure-import.errors.manual_override_not_allowed', ['code' => $providedCode]),
                    $column,
                )], null];
            }

            return [[], CodeAssignment::provided($sheet, $rowNumber, $name, $providedCode)];
        }

        // ── Blank, and auto-generation is switched off ────────────────────
        if (! $autoGenerateCodes) {
            return [[ImportIssue::error(
                $sheet,
                $rowNumber,
                __('organization-structure-import.errors.code_required_without_autogenerate'),
                $column,
            )], null];
        }

        // ── Blank → generate ──────────────────────────────────────────────
        if ($projector->rule($entityType, $context) === null) {
            return [[ImportIssue::error(
                $sheet,
                $rowNumber,
                __('organization-structure-import.errors.code_rule_not_configured'),
                $column,
            )], null];
        }

        // The rule exists but its context does not (e.g. an unknown organization
        // type means {ORG_TYPE_PREFIX} cannot resolve). The row already carries
        // an error for the bad reference; adding a second "could not generate"
        // here would be noise, so no code is projected and nothing is reported.
        if (! $canGenerate) {
            return [[], null];
        }

        $rule = $projector->rule($entityType, $context);
        $usesRandomToken = str_contains((string) $rule?->format, '{RAND_6}');
        $maximumAttempts = $usesRandomToken ? self::MAX_RANDOM_CODE_ATTEMPTS : 1;
        $generated = null;

        for ($attempt = 0; $attempt < $maximumAttempts; $attempt++) {
            try {
                $generated = $projector->project($entityType, $context, $sheet->value.':'.$rowNumber);
            } catch (Throwable) {
                $generated = null;
            }

            if ($generated === null || ! $isDuplicate($generated)) {
                break;
            }
        }

        if ($generated === null) {
            return [[ImportIssue::error(
                $sheet,
                $rowNumber,
                __('organization-structure-import.errors.code_could_not_be_generated'),
                $column,
            )], null];
        }

        if ($isDuplicate($generated)) {
            $message = $usesRandomToken
                ? __('code-rules.random_code_duplicate')
                : __('organization-structure-import.errors.duplicate_generated_code', ['code' => $generated]);

            return [[ImportIssue::error(
                $sheet,
                $rowNumber,
                $message,
                $column,
            )], null];
        }

        return [[], CodeAssignment::generated(
            $sheet,
            $rowNumber,
            $name,
            $generated,
            $rule?->name_en,
            $usesRandomToken,
        )];
    }

    /**
     * Whether this actor may supply a code by hand for this rule.
     *
     * Mirrors the two gates {@see GenerateCodeAction}
     * enforces at write time, so a workbook that would be rejected mid-import is
     * rejected in the preview instead.
     */
    private function actorMayOverride(CodeRule $rule, ?User $actor): bool
    {
        if (! $rule->allow_manual_override) {
            return false;
        }

        if ($rule->require_approval_for_override) {
            return $actor?->can('code-rules.manageOverrides') ?? false;
        }

        return true;
    }

    // ── Organization Units ───────────────────────────────────────────────────

    /**
     * Units are validated in two passes because a row may be referenced as a
     * parent *by the code it is about to be given*. Pass 1 settles every row's
     * code (provided or generated); pass 2 validates the references against the
     * settled codes. Without the split, a child could not point at a
     * blank-coded parent defined above it.
     *
     * Each returned row carries `__code` — the code the importer must use, so
     * confirm writes exactly what preview displayed.
     *
     * @return array{0: list<ImportIssue>, 1: list<CodeAssignment>, 2: list<array<string, mixed>>}
     */
    private function validateUnits(
        array $rows,
        ?Organization $organization,
        array $unitTypeIds,
        array $organizationIdsByCode,
        CodeProjector $projector,
        bool $autoGenerateCodes,
        ?User $actor = null,
    ): array {
        $issues = [];
        $assignments = [];
        $sheet = StructureSheet::OrganizationUnits;

        $existingCodes = $organization !== null
            ? OrganizationUnit::query()
                ->where('organization_id', $organization->id)
                ->pluck('code')
                ->map(static fn (string $code): string => mb_strtolower($code))
                ->all()
            : [];

        // ── Pass 1: settle each row's code ────────────────────────────────
        $seen = [];
        $resolved = [];

        foreach ($rows as $index => $row) {
            $rowNumber = (int) $row['__row'];

            $code = $this->str($row['unit_code'] ?? null);
            $nameEn = $this->str($row['unit_name_en'] ?? null);
            $typeCode = $this->upper($row['unit_type_code'] ?? null);

            if ($nameEn === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.unit_name_required'), 'unit_name_en');
            }

            $unitTypeId = null;

            if ($typeCode === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.unit_type_required'), 'unit_type_code');
            } elseif (! array_key_exists($typeCode, $unitTypeIds)) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.unit_type_not_found', ['code' => $typeCode]), 'unit_type_code');
            } else {
                $unitTypeId = $unitTypeIds[$typeCode];
            }

            [$codeIssues, $assignment] = $this->resolveCode(
                $sheet,
                $rowNumber,
                (string) ($nameEn ?? ''),
                $code,
                CodeRuleEntityType::OrganizationUnit,
                [
                    'organization_id' => $organization?->id,
                    'organization_unit_type_id' => $unitTypeId,
                ],
                $projector,
                $autoGenerateCodes,
                canGenerate: $unitTypeId !== null,
                isDuplicate: fn (string $candidate): bool => in_array(mb_strtolower($candidate), $existingCodes, true),
                actor: $actor,
            );

            $issues = [...$issues, ...$codeIssues];

            $effectiveCode = $assignment?->code;

            if ($assignment !== null) {
                $assignments[] = $assignment;
            }

            // Duplicate check spans provided AND generated codes alike, so two
            // rows can never collide regardless of where their codes came from.
            if ($effectiveCode !== null) {
                $key = mb_strtolower($effectiveCode);

                if (isset($seen[$key])) {
                    $issues[] = ImportIssue::error(
                        $sheet,
                        $rowNumber,
                        $assignment->isGenerated()
                            ? __('organization-structure-import.errors.duplicate_generated_code', ['code' => $effectiveCode])
                            : __('organization-structure-import.errors.duplicate_unit_code', ['code' => $effectiveCode, 'row' => $seen[$key]]),
                        'unit_code',
                    );
                } else {
                    $seen[$key] = $rowNumber;
                }
            }

            $rows[$index]['__code'] = $effectiveCode;
            $resolved[$rowNumber] = $effectiveCode;
        }

        // Codes now settled — references resolve against the final values.
        $fileCodes = $this->codeRowMap($rows, '__code');
        $codeByRow = $this->codeByRow($rows);

        // ── Pass 2: validate references ───────────────────────────────────
        foreach ($rows as $index => $row) {
            $rowNumber = (int) $row['__row'];

            $code = $row['__code'] ?? null;

            // A parent may be named by code, or by `#N` pointing at another row
            // of this sheet — the only way to reference a unit whose code the
            // Code Rules are about to generate.
            $rawParent = $this->str($row['parent_unit_code'] ?? null);
            [$parentCode, $wasRowRef] = $this->resolveRowReference($rawParent, $codeByRow);

            // Rewrite the row so cycle detection, parent-first sorting and the
            // importer all see a plain code and need no knowledge of `#N`.
            $rows[$index]['parent_unit_code'] = $parentCode;

            $hostCode = $this->str($row['host_organization_code'] ?? null);
            $ownerCode = $this->str($row['functional_owner_organization_code'] ?? null);
            $status = $this->lower($row['status'] ?? null);

            if ($status !== null && OrganizationUnitStatus::tryFrom($status) === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.invalid_status', ['value' => $status]), 'status');
            }

            if ($wasRowRef && $parentCode === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.row_reference_not_found', ['ref' => (string) $rawParent]), 'parent_unit_code');
            } elseif ($parentCode !== null && ! $this->unitCodeResolvable($parentCode, $fileCodes, $existingCodes)) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.parent_unit_not_found', ['code' => $parentCode]), 'parent_unit_code');
            }

            if ($parentCode !== null && $code !== null && mb_strtolower($parentCode) === mb_strtolower($code)) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.unit_is_own_parent', ['code' => $code]), 'parent_unit_code');
            }

            foreach ([['host_organization_code', $hostCode], ['functional_owner_organization_code', $ownerCode]] as [$column, $value]) {
                if ($value !== null && ! array_key_exists(mb_strtolower($value), $organizationIdsByCode)) {
                    $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.organization_not_found', ['code' => $value]), $column);
                }
            }
        }

        return [[...$issues, ...$this->detectUnitCycles($rows)], $assignments, array_values($rows)];
    }

    /**
     * Reject any parent chain inside the file that loops back on itself. Only
     * in-file edges can form a new cycle: an existing database parent is
     * already acyclic, and rows may not re-parent existing units.
     *
     * @return list<ImportIssue>
     */
    private function detectUnitCycles(array $rows): array
    {
        $parentOf = [];
        $rowOf = [];

        foreach ($rows as $row) {
            // The settled code (generated or provided) — cycles are a property
            // of the final codes, not of what the user happened to type.
            $code = $this->str($row['__code'] ?? null);
            $parent = $this->str($row['parent_unit_code'] ?? null);

            if ($code === null) {
                continue;
            }

            $key = mb_strtolower($code);
            $parentOf[$key] = $parent !== null ? mb_strtolower($parent) : null;
            $rowOf[$key] = (int) $row['__row'];
        }

        $issues = [];
        $reported = [];

        foreach (array_keys($parentOf) as $start) {
            $seen = [];
            $current = $start;
            $depth = 0;

            while ($current !== null && $depth++ < self::MAX_UNIT_DEPTH) {
                if (isset($seen[$current])) {
                    // Report against the row that starts the walk; guard so a
                    // 3-unit loop yields one issue per member, not per path.
                    if (! isset($reported[$start])) {
                        $reported[$start] = true;
                        $issues[] = ImportIssue::error(
                            StructureSheet::OrganizationUnits,
                            $rowOf[$start],
                            __('organization-structure-import.errors.circular_parent_unit', ['code' => $start]),
                            'parent_unit_code',
                        );
                    }
                    break;
                }

                $seen[$current] = true;

                // A parent that is not itself defined in the file terminates
                // the walk — it resolves to an existing (acyclic) unit.
                $current = $parentOf[$current] ?? null;
            }
        }

        return $issues;
    }

    // ── Positions ────────────────────────────────────────────────────────────

    /**
     * @param  string|null  $organizationCode  the code the organization will carry (provided or generated)
     * @return array{0: list<ImportIssue>, 1: list<CodeAssignment>, 2: list<array<string, mixed>>}
     */
    private function validatePositions(
        array $rows,
        array $unitRows,
        ?Organization $organization,
        ?string $organizationCode,
        array $gradeLevelNames,
        array $occupationIds,
        CodeProjector $projector,
        bool $autoGenerateCodes,
        ?User $actor = null,
    ): array {
        $issues = [];
        $assignments = [];
        $sheet = StructureSheet::Positions;

        // Unit rows are keyed by their settled code, so a position may sit in a
        // unit whose own code was generated moments ago.
        $fileUnitCodes = $this->codeRowMap($unitRows, '__code');

        // …and by Excel row number, so `unit_code = #3` can name that unit.
        $unitCodeByRow = $this->codeByRow($unitRows);
        $existingUnitCodes = $organization !== null
            ? OrganizationUnit::query()
                ->where('organization_id', $organization->id)
                ->pluck('code')
                ->map(static fn (string $code): string => mb_strtolower($code))
                ->all()
            : [];

        // Units that exist but are not active cannot take on new positions.
        $inactiveUnitCodes = $organization !== null
            ? OrganizationUnit::query()
                ->where('organization_id', $organization->id)
                ->where('status', '!=', OrganizationUnitStatus::Active->value)
                ->pluck('code')
                ->map(static fn (string $code): string => mb_strtolower($code))
                ->all()
            : [];

        // Owner/host organization code per unit row, so a position inside a
        // hosted unit projects as OWNER/HOST/SEQ while a plain one is OWNER/SEQ.
        $unitOwnerHost = $this->unitOwnerHostCodes($unitRows, $organizationCode);

        $inactiveFileUnitCodes = [];
        foreach ($unitRows as $unitRow) {
            $unitCode = $this->str($unitRow['__code'] ?? null);
            $unitStatus = $this->lower($unitRow['status'] ?? null);

            if ($unitCode !== null && $unitStatus !== null && $unitStatus !== OrganizationUnitStatus::Active->value) {
                $inactiveFileUnitCodes[] = mb_strtolower($unitCode);
            }
        }

        $seen = [];

        foreach ($rows as $index => $row) {
            $rowNumber = (int) $row['__row'];

            $code = $this->str($row['position_code'] ?? null);
            $standardName = $this->str($row['standard_name'] ?? null);

            // The unit may be named by code, or by `#N` pointing at a row of the
            // Organization Units sheet (the only way to name a unit whose code
            // is about to be generated).
            $rawUnit = $this->str($row['unit_code'] ?? null);
            [$unitCode, $unitWasRowRef] = $this->resolveRowReference($rawUnit, $unitCodeByRow);
            $rows[$index]['unit_code'] = $unitCode;

            $gradeCode = $this->str($row['job_grade_code'] ?? null);
            $professionCode = $this->upper($row['profession_code'] ?? null);
            $status = $this->lower($row['position_status'] ?? null);
            $slots = $row['slots'] ?? null;

            if ($standardName === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.standard_name_required'), 'standard_name');
            }

            if ($unitWasRowRef && $unitCode === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.row_reference_not_found', ['ref' => (string) $rawUnit]), 'unit_code');
            }

            $unitResolvable = $unitCode === null
                || $this->unitCodeResolvable($unitCode, $fileUnitCodes, $existingUnitCodes);

            if (! $unitResolvable) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.position_unit_not_found', ['code' => (string) $unitCode]), 'unit_code');
            }

            if ($unitCode !== null) {
                $key = mb_strtolower($unitCode);

                if (in_array($key, $inactiveUnitCodes, true) || in_array($key, $inactiveFileUnitCodes, true)) {
                    $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.unit_inactive', ['code' => $unitCode]), 'unit_code');
                }
            }

            if ($status !== null && ! in_array($status, ['active', 'inactive'], true)) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.invalid_position_status', ['value' => $status]), 'position_status');
            }

            if ($gradeCode !== null && ! array_key_exists(mb_strtolower($gradeCode), $gradeLevelNames)) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.job_grade_not_found', ['code' => $gradeCode]), 'job_grade_code');
            }

            if ($professionCode !== null && ! array_key_exists($professionCode, $occupationIds)) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.profession_not_found', ['code' => $professionCode]), 'profession_code');
            }

            if ($slots !== null && (! is_numeric($slots) || (int) $slots < 1)) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.invalid_slots', ['value' => (string) $slots]), 'slots');
            }

            // ── Code: provided → validate; blank → generate ───────────────
            // The owner/host pair is what makes the code come out as
            // OWNER/SEQUENCE or OWNER/HOST/SEQUENCE — the same rule the normal
            // position create page uses, fed with codes instead of ids because
            // the organization may not exist yet.
            $ownerHost = $unitCode !== null
                ? ($unitOwnerHost[mb_strtolower($unitCode)] ?? ['owner' => $organizationCode, 'host' => null])
                : ['owner' => $organizationCode, 'host' => null];

            [$codeIssues, $assignment] = $this->resolveCode(
                $sheet,
                $rowNumber,
                (string) ($standardName ?? ''),
                $code,
                CodeRuleEntityType::Position,
                [
                    'organization_id' => $organization?->id,
                    'owner_organization_code' => $ownerHost['owner'],
                    'host_organization_code' => $ownerHost['host'],
                ],
                $projector,
                $autoGenerateCodes,
                canGenerate: $unitResolvable && $ownerHost['owner'] !== null,
                isDuplicate: fn (string $candidate): bool => Position::query()
                    ->where('job_position_code', $candidate)
                    ->exists(),
                actor: $actor,
            );

            $issues = [...$issues, ...$codeIssues];

            $effectiveCode = $assignment?->code;

            if ($assignment !== null) {
                $assignments[] = $assignment;
            }

            if ($effectiveCode !== null) {
                $key = mb_strtolower($effectiveCode);

                if (isset($seen[$key])) {
                    $issues[] = ImportIssue::error(
                        $sheet,
                        $rowNumber,
                        $assignment->isGenerated()
                            ? __('organization-structure-import.errors.duplicate_generated_code', ['code' => $effectiveCode])
                            : __('organization-structure-import.errors.duplicate_position_code', ['code' => $effectiveCode, 'row' => $seen[$key]]),
                        'position_code',
                    );
                } else {
                    $seen[$key] = $rowNumber;
                }
            }

            $rows[$index]['__code'] = $effectiveCode;
        }

        return [$issues, $assignments, array_values($rows)];
    }

    // ── Employees ────────────────────────────────────────────────────────────

    /**
     * @return array{0: list<ImportIssue>, 1: list<CodeAssignment>, 2: list<array<string, mixed>>}
     */
    private function validateEmployees(
        array $rows,
        array $positionRows,
        ?Organization $organization,
        CodeProjector $projector,
        bool $autoGenerateCodes,
        ?User $actor = null,
    ): array {
        $issues = [];
        $assignments = [];
        $sheet = StructureSheet::Employees;

        if ($rows === []) {
            return [[], [], []];
        }

        // Positions this file will create, keyed by their settled code — so an
        // employee may be assigned to a position whose code was generated.
        $filePositionSlots = [];
        foreach ($positionRows as $positionRow) {
            $code = $this->str($positionRow['__code'] ?? null);

            if ($code === null) {
                continue;
            }

            $filePositionSlots[mb_strtolower($code)] = max(1, (int) ($positionRow['slots'] ?? 1));
        }

        // Positions by Excel row number, so `position_code = #4` can name a
        // position whose code the Code Rules are about to generate.
        $positionCodeByRow = $this->codeByRow($positionRows);

        $seenNumbers = [];
        $claimedByCode = [];

        foreach ($rows as $index => $row) {
            $rowNumber = (int) $row['__row'];

            $employeeNumber = $this->str($row['employee_number'] ?? null);
            $firstName = $this->str($row['first_name'] ?? null);
            $fatherName = $this->str($row['father_name'] ?? null);
            $email = $this->str($row['email'] ?? null);

            $rawPosition = $this->str($row['position_code'] ?? null);
            [$positionCode, $positionWasRowRef] = $this->resolveRowReference($rawPosition, $positionCodeByRow);
            $rows[$index]['position_code'] = $positionCode;

            $startDate = $this->str($row['assignment_start_date'] ?? null);
            $employmentStatus = $this->lower($row['employment_status'] ?? null);

            if ($positionWasRowRef && $positionCode === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.row_reference_not_found', ['ref' => (string) $rawPosition]), 'position_code');
            }

            if ($firstName === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.first_name_required'), 'first_name');
            }

            if ($fatherName === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.father_name_required'), 'father_name');
            }

            if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.invalid_email', ['value' => $email]), 'email');
            }

            if ($employmentStatus !== null && EmployeeStatus::tryFrom($employmentStatus) === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.invalid_employment_status', ['value' => $employmentStatus]), 'employment_status');
            }

            // ── Employee number: provided → validate; blank → generate ────
            // Deliberately no organization in the context: an employee number
            // must survive a transfer, so it is drawn from the global employee
            // sequence rather than an organization-scoped one.
            [$codeIssues, $assignment] = $this->resolveCode(
                $sheet,
                $rowNumber,
                trim((string) $firstName.' '.(string) $fatherName),
                $employeeNumber,
                CodeRuleEntityType::Employee,
                [],
                $projector,
                $autoGenerateCodes,
                canGenerate: true,
                isDuplicate: fn (string $candidate): bool => Employee::query()
                    ->where('employee_number', $candidate)
                    ->exists(),
                actor: $actor,
            );

            $issues = [...$issues, ...$codeIssues];

            $effectiveNumber = $assignment?->code;

            if ($assignment !== null) {
                $assignments[] = $assignment;
            }

            if ($effectiveNumber !== null) {
                $numberKey = mb_strtolower($effectiveNumber);

                if (isset($seenNumbers[$numberKey])) {
                    $issues[] = ImportIssue::error(
                        $sheet,
                        $rowNumber,
                        $assignment->isGenerated()
                            ? __('organization-structure-import.errors.duplicate_generated_code', ['code' => $effectiveNumber])
                            : __('organization-structure-import.errors.duplicate_employee_number', ['number' => $effectiveNumber, 'row' => $seenNumbers[$numberKey]]),
                        'employee_number',
                    );
                } else {
                    $seenNumbers[$numberKey] = $rowNumber;
                }
            }

            $rows[$index]['__code'] = $effectiveNumber;

            // ── Assignment ────────────────────────────────────────────────
            // position_code and assignment_start_date are required *together*:
            // both blank imports the person with no assignment, which is legal.
            // One without the other is an incomplete assignment.
            if ($positionCode === null && $startDate === null) {
                continue;
            }

            if ($positionCode === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.employee_position_required'), 'position_code');

                continue;
            }

            if ($startDate === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.assignment_start_date_required'), 'assignment_start_date');

                continue;
            }

            if ($this->parseDate($startDate) === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.invalid_date', ['value' => $startDate]), 'assignment_start_date');
            }

            $key = mb_strtolower($positionCode);
            $inFile = array_key_exists($key, $filePositionSlots);

            $existingPosition = $organization !== null
                ? Position::query()
                    ->where('organization_id', $organization->id)
                    ->where('job_position_code', $positionCode)
                    ->first()
                : null;

            if (! $inFile && $existingPosition === null) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.employee_position_not_found', ['code' => $positionCode]), 'position_code');

                continue;
            }

            // Capacity: slots declared in the file (for new positions) or
            // establishment slots (for existing ones), minus assignments this
            // file already claims and minus assignments already in the database.
            $claimedByCode[$key] = ($claimedByCode[$key] ?? 0) + 1;

            $capacity = $inFile
                ? $filePositionSlots[$key]
                : $this->existingPositionCapacity($existingPosition);

            $occupied = $existingPosition !== null
                ? EmployeeAssignment::query()
                    ->where('position_id', $existingPosition->id)
                    ->where('assignment_status', AssignmentStatus::Active->value)
                    ->where('is_current', true)
                    ->count()
                : 0;

            if ($capacity < $claimedByCode[$key] + $occupied) {
                $issues[] = ImportIssue::error(
                    $sheet,
                    $rowNumber,
                    __('organization-structure-import.errors.position_occupied', [
                        'code' => $positionCode,
                        'slots' => (string) $capacity,
                    ]),
                    'position_code',
                );
            }

            // An inactive existing position cannot receive a new assignment.
            if ($existingPosition !== null && ! $existingPosition->is_active) {
                $issues[] = ImportIssue::error($sheet, $rowNumber, __('organization-structure-import.errors.position_inactive', ['code' => $positionCode]), 'position_code');
            }
        }

        // Assigning into an inactive organization is never allowed.
        if ($organization !== null && $organization->status !== OrganizationStatus::Active) {
            $issues[] = ImportIssue::error(
                $sheet,
                null,
                __('organization-structure-import.errors.organization_inactive_for_assignment', ['code' => (string) $organization->code]),
            );
        }

        return [$issues, $assignments, array_values($rows)];
    }

    /**
     * How many people an already-existing position may hold. Prefers the
     * approved establishment slots when one exists; otherwise a position is a
     * single seat.
     */
    private function existingPositionCapacity(?Position $position): int
    {
        if ($position === null) {
            return 1;
        }

        $approved = (int) PositionEstablishment::query()
            ->where('position_id', $position->id)
            ->where('status', EstablishmentStatus::Approved->value)
            ->sum('approved_slots');

        return $approved > 0 ? $approved : 1;
    }

    // ── Row references ───────────────────────────────────────────────────────

    /**
     * Resolve a `#N` row reference to the code that row was assigned.
     *
     * A code that the Code Rules generate does not exist until the import runs,
     * so it cannot be typed into another cell to wire up a hierarchy. `#N` closes
     * that gap: it names a row of the *referenced sheet* (the Excel row number,
     * header included, exactly as shown in the preview's Row column) and resolves
     * to whatever code that row ends up with — provided or generated.
     *
     * Anything that is not of the form `#<digits>` is returned untouched and
     * treated as a literal code, so plain-code files behave exactly as before.
     *
     * @param  array<int, string|null>  $codeByRow  Excel row number => settled code
     * @return array{0: string|null, 1: bool} the resolved value, and whether it was a row reference
     */
    private function resolveRowReference(?string $value, array $codeByRow): array
    {
        if ($value === null || ! preg_match('/^#(\d+)$/', $value, $matches)) {
            return [$value, false];
        }

        return [$codeByRow[(int) $matches[1]] ?? null, true];
    }

    /**
     * Settled code per Excel row number, for a set of already-validated rows.
     *
     * @return array<int, string|null>
     */
    private function codeByRow(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['__row']] = $this->str($row['__code'] ?? null);
        }

        return $map;
    }

    // ── Lookups & helpers ────────────────────────────────────────────────────

    /**
     * Every organization code the workbook refers to (the subject organization
     * plus any host / functional-owner reference), mapped to its id. Codes that
     * do not exist are simply absent, which is what the row checks look for.
     *
     * @return array<string, string> lowercased code => organization id
     */
    private function referencedOrganizationIds(
        StructureWorkbook $workbook,
        ?string $organizationCode,
        ?Organization $existing,
    ): array {
        $codes = collect();

        if ($organizationCode !== null) {
            $codes->push($organizationCode);
        }

        foreach ($workbook->rows(StructureSheet::OrganizationUnits) as $row) {
            foreach (['host_organization_code', 'functional_owner_organization_code'] as $column) {
                $value = $this->str($row[$column] ?? null);

                if ($value !== null) {
                    $codes->push($value);
                }
            }
        }

        $codes = $codes->unique()->values();

        if ($codes->isEmpty()) {
            return [];
        }

        $found = Organization::query()
            ->whereIn('code', $codes->all())
            ->pluck('id', 'code')
            ->mapWithKeys(static fn (string $id, string $code): array => [mb_strtolower($code) => $id])
            ->all();

        // The subject organization may be new — in that case its own code is
        // still a valid host/owner reference, because it will exist by the time
        // units are written. Represent it with a null id the importer fills in.
        if ($existing === null && $organizationCode !== null) {
            $found[mb_strtolower($organizationCode)] ??= '';
        }

        return $found;
    }

    /** @return array<string, string> uppercased code => id */
    private function organizationTypeIds(): array
    {
        return OrganizationType::query()
            ->pluck('id', 'code')
            ->mapWithKeys(static fn (string $id, string $code): array => [mb_strtoupper($code) => $id])
            ->all();
    }

    /** @return array<string, string> uppercased code => id */
    private function unitTypeIds(): array
    {
        return OrganizationUnitType::query()
            ->pluck('id', 'code')
            ->mapWithKeys(static fn (string $id, string $code): array => [mb_strtoupper($code) => $id])
            ->all();
    }

    /**
     * Grade levels are identified by `name` (the table has no code column), so
     * the workbook's job_grade_code is matched against the name.
     *
     * @return array<string, string> lowercased name => canonical name
     */
    private function gradeLevelNames(): array
    {
        return GradeLevel::query()
            ->pluck('name')
            ->mapWithKeys(static fn (string $name): array => [mb_strtolower($name) => $name])
            ->all();
    }

    /** @return array<string, string> uppercased code => id */
    private function occupationIds(): array
    {
        return Occupation::query()
            ->whereNotNull('code')
            ->pluck('id', 'code')
            ->mapWithKeys(static fn (string $id, string $code): array => [mb_strtoupper($code) => $id])
            ->all();
    }

    /**
     * Whether a unit code resolves to a row in this file or to a unit that
     * already exists in the organization.
     */
    private function unitCodeResolvable(string $code, array $fileCodes, array $existingCodes): bool
    {
        $key = mb_strtolower($code);

        return array_key_exists($key, $fileCodes) || in_array($key, $existingCodes, true);
    }

    /**
     * @return array<string, int> lowercased code => Excel row number
     */
    private function codeRowMap(array $rows, string $column): array
    {
        $map = [];

        foreach ($rows as $row) {
            $code = $this->str($row[$column] ?? null);

            if ($code !== null) {
                $map[mb_strtolower($code)] ??= (int) $row['__row'];
            }
        }

        return $map;
    }

    /**
     * Order units so that a parent always precedes its children, letting the
     * importer create them in a single pass. Rows whose parent is not in the
     * file (root units, or children of pre-existing units) come first. A row
     * caught in a cycle is appended last — validation has already errored on it,
     * so the importer will never run.
     *
     * @return list<array<string, mixed>>
     */
    private function sortUnitsParentFirst(array $rows): array
    {
        $byCode = [];

        foreach ($rows as $row) {
            // Ordering keys off the settled code so a generated-code parent
            // still precedes its children.
            $code = $this->str($row['__code'] ?? null);

            if ($code !== null) {
                $byCode[mb_strtolower($code)] = $row;
            }
        }

        $sorted = [];
        $placed = [];

        $place = function (array $row) use (&$place, &$sorted, &$placed, $byCode): void {
            $code = $this->str($row['__code'] ?? null);
            $key = $code !== null ? mb_strtolower($code) : null;

            if ($key !== null && isset($placed[$key])) {
                return;
            }

            if ($key !== null) {
                $placed[$key] = true; // Set before recursing so a cycle terminates.
            }

            $parent = $this->str($row['parent_unit_code'] ?? null);
            $parentKey = $parent !== null ? mb_strtolower($parent) : null;

            if ($parentKey !== null && isset($byCode[$parentKey])) {
                $place($byCode[$parentKey]);
            }

            $sorted[] = $row;
        };

        foreach ($rows as $row) {
            $place($row);
        }

        return $sorted;
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function upper(mixed $value): ?string
    {
        $string = $this->str($value);

        return $string === null ? null : mb_strtoupper($string, 'UTF-8');
    }

    private function lower(mixed $value): ?string
    {
        $string = $this->str($value);

        return $string === null ? null : mb_strtolower($string, 'UTF-8');
    }
}
