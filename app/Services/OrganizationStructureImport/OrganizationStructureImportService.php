<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Exceptions\StructureImportCodeConflictException;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmploymentStatusHistory;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use App\Models\Position;
use App\Models\User;
use App\Services\CodeGeneration\PositionCodeContextResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Drives the three steps of the Organization Structure Import wizard.
 *
 * `preview()` reads and validates without writing anything. `confirm()` re-reads
 * and re-validates the *same file* from scratch before writing — the preview's
 * verdict is never trusted as a token, so a file that has gone stale (a unit
 * code taken, a position filled, since the preview) is caught rather than
 * imported over.
 *
 * Every write happens inside one transaction: a failure anywhere rolls the
 * whole structure back, so a half-imported organization is not possible.
 */
class OrganizationStructureImportService
{
    public function __construct(
        private readonly StructureWorkbookReader $reader,
        private readonly OrganizationStructureImportValidator $validator,
        private readonly GenerateCodeAction $generateCodeAction,
        private readonly PositionCodeContextResolver $positionCodeContextResolver,
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    /**
     * Validate an uploaded workbook and describe what an import would do.
     * Writes nothing except the audit trail entry for the preview itself.
     */
    public function preview(UploadedFile $file, User $actor, bool $autoGenerateCodes = true): array
    {
        $plan = $this->validator->validate($this->reader->read($file), $actor, $autoGenerateCodes);

        $this->writeAuditLogAction->execute(
            AuditEventType::OrganizationStructureImportPreviewed,
            $actor,
            $plan->existingOrganization,
            $plan->existingOrganization?->id,
            newValues: [
                'file_name' => $file->getClientOriginalName(),
                'auto_generate_codes' => $autoGenerateCodes,
                'error_count' => count($plan->errors()),
                'warning_count' => count($plan->warnings()),
                'unit_count' => count($plan->unitRows),
                'position_count' => count($plan->positionRows),
                'employee_count' => count($plan->employeeRows),
                'generated_code_count' => count(array_filter(
                    $plan->codeAssignments(),
                    static fn (CodeAssignment $a): bool => $a->isGenerated(),
                )),
            ],
        );

        return $this->buildPreviewPayload($plan, $file);
    }

    /**
     * Identify a workbook by its parsed business data instead of XLSX archive
     * metadata, which may differ when the same sheet is uploaded again.
     */
    public function fingerprint(UploadedFile $file): string
    {
        $workbook = $this->reader->read($file);
        $content = [];

        foreach (StructureSheet::cases() as $sheet) {
            $content[$sheet->value] = [
                'present' => $workbook->hasSheet($sheet),
                'columns' => $workbook->columns($sheet),
                'rows' => $workbook->rows($sheet)->values()->all(),
            ];
        }

        return hash('sha256', json_encode(
            $content,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * Re-validate and import. Returns the same preview payload shape, plus a
     * `result` block with the counts actually written.
     *
     * @throws RuntimeException when the file no longer validates cleanly
     */
    /** @param array<string, string> $lockedRandomCodes */
    public function confirm(
        UploadedFile $file,
        User $actor,
        bool $importEmployees = true,
        bool $autoGenerateCodes = true,
        array $lockedRandomCodes = [],
    ): array {
        $plan = $this->validator->validate(
            $this->reader->read($file),
            $actor,
            $autoGenerateCodes,
            $lockedRandomCodes,
        );

        if ($plan->hasErrors()) {
            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationStructureImportFailed,
                $actor,
                $plan->existingOrganization,
                $plan->existingOrganization?->id,
                newValues: [
                    'file_name' => $file->getClientOriginalName(),
                    'reason' => 'validation_failed',
                    'errors' => array_map(
                        static fn (ImportIssue $issue): array => $issue->toArray(),
                        $plan->errors(),
                    ),
                ],
            );

            return $this->buildPreviewPayload($plan, $file);
        }

        try {
            $result = DB::transaction(fn (): array => $this->write($plan, $actor, $importEmployees));
        } catch (Throwable $exception) {
            // The transaction has already rolled back; record why and re-throw
            // so the controller can surface a failure rather than a success.
            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationStructureImportFailed,
                $actor,
                $plan->existingOrganization,
                $plan->existingOrganization?->id,
                newValues: [
                    'file_name' => $file->getClientOriginalName(),
                    'reason' => 'write_failed',
                    'exception' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }

        $organization = Organization::query()->find($result['organization_id']);

        $this->writeAuditLogAction->execute(
            AuditEventType::OrganizationStructureImported,
            $actor,
            $organization,
            $result['organization_id'],
            newValues: [
                'file_name' => $file->getClientOriginalName(),
                'uploaded_by' => $actor->getKey(),
                'mode' => $result['mode'],
                'organizations_created' => $result['organizations_created'],
                'organizations_updated' => $result['organizations_updated'],
                'units_created' => $result['units_created'],
                'positions_created' => $result['positions_created'],
                'employees_created' => $result['employees_created'],
                'assignments_created' => $result['assignments_created'],
                'error_count' => 0,
                'warning_count' => count($plan->warnings()),
                'imported_at' => now()->toIso8601String(),
            ],
        );

        return $this->buildPreviewPayload($plan, $file) + ['result' => $result];
    }

    // ── Write ────────────────────────────────────────────────────────────────

    /**
     * Runs entirely inside the caller's transaction.
     *
     * @return array<string, mixed>
     */
    private function write(StructureImportPlan $plan, User $actor, bool $importEmployees): array
    {
        $organization = $this->upsertOrganization($plan, $actor);

        $unitIdsByCode = $this->createUnits($plan, $organization, $actor);
        $positionsByCode = $this->createPositions($plan, $organization, $unitIdsByCode, $actor);

        $employees = $importEmployees
            ? $this->createEmployees($plan, $organization, $positionsByCode, $actor)
            : ['employees' => 0, 'assignments' => 0];

        return [
            'organization_id' => $organization->id,
            'organization_code' => $organization->code,
            'mode' => $plan->isUpdate() ? 'update' : 'create',
            'organizations_created' => $plan->isUpdate() ? 0 : 1,
            'organizations_updated' => $plan->isUpdate() ? 1 : 0,
            'units_created' => count($unitIdsByCode),
            'positions_created' => count($positionsByCode),
            'employees_created' => $employees['employees'],
            'assignments_created' => $employees['assignments'],
        ];
    }

    /**
     * Create the organization, or update the existing one. Update mode only
     * touches the columns the sheet carries — it never blanks a field the
     * workbook has no column for.
     */
    private function upsertOrganization(StructureImportPlan $plan, User $actor): Organization
    {
        $row = $plan->organizationRow ?? [];

        $typeCode = mb_strtoupper((string) $this->value($row, 'organization_type_code'), 'UTF-8');
        $status = $this->value($row, 'status');

        $attributes = array_filter([
            'organization_type_id' => $plan->organizationTypeIds[$typeCode] ?? null,
            'name_en' => $this->value($row, 'organization_name_en'),
            'name_am' => $this->value($row, 'organization_name_am'),
            'status' => $status !== null
                ? OrganizationStatus::from(mb_strtolower($status))->value
                : OrganizationStatus::Active->value,
        ], static fn (mixed $value): bool => $value !== null);

        if ($plan->existingOrganization !== null) {
            $organization = $plan->existingOrganization;
            $before = $organization->toArray();

            $organization->fill($attributes)->save();

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationUpdated,
                $actor,
                $organization,
                $organization->id,
                oldValues: $before,
                newValues: $organization->toArray(),
            );

            return $organization;
        }

        // Generated for real through the Organization code rule (advancing its
        // sequence), then checked against the code the preview promised.
        $code = $this->generateCodeAction->executeUsingPreviewedCode(
            CodeRuleEntityType::Organization,
            ['organization_type_id' => $attributes['organization_type_id'] ?? null],
            $actor,
            $this->value($row, 'organization_code'),
            'code',
            expectedGeneratedCode: $plan->organizationCode?->code,
        );

        $this->assertMatchesPreview(
            StructureSheet::Organization,
            (int) ($row['__row'] ?? 2),
            $plan->organizationCode?->code,
            $code,
        );

        $organization = Organization::query()->create($attributes + [
            'code' => $code,
            'effective_from' => now()->toDateString(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::OrganizationCreated,
            $actor,
            $organization,
            $organization->id,
            newValues: $organization->toArray(),
        );

        return $organization;
    }

    /**
     * Create the units. `unitRows` arrives parent-before-child, so a parent's id
     * is always already known by the time its children are written.
     *
     * @return array<string, string> lowercased unit code => unit id
     */
    private function createUnits(StructureImportPlan $plan, Organization $organization, User $actor): array
    {
        // Units already in the organization can be referenced as parents.
        $idsByCode = OrganizationUnit::query()
            ->where('organization_id', $organization->id)
            ->pluck('id', 'code')
            ->mapWithKeys(static fn (string $id, string $code): array => [mb_strtolower($code) => $id])
            ->all();

        $created = [];

        foreach ($plan->unitRows as $row) {
            $providedCode = $this->value($row, 'unit_code');
            $previewedCode = $this->value($row, '__code');

            $typeCode = mb_strtoupper((string) $this->value($row, 'unit_type_code'), 'UTF-8');
            $unitTypeId = $plan->unitTypeIds[$typeCode] ?? null;
            $parentCode = $this->value($row, 'parent_unit_code');
            $status = $this->value($row, 'status');
            $hostCode = $this->value($row, 'host_organization_code');
            $ownerCode = $this->value($row, 'functional_owner_organization_code');

            // The unit physically lives in its HOST organization when one is
            // named (that is how a hosted office is modelled today); otherwise
            // it belongs to the organization being imported.
            $hostOrganizationId = $hostCode !== null
                ? $this->resolveOrganizationId($plan, $organization, $hostCode)
                : $organization->id;

            // Generated for real through the code rule (advancing its sequence),
            // then checked against what the preview promised.
            $code = $this->generateCodeAction->executeUsingPreviewedCode(
                CodeRuleEntityType::OrganizationUnit,
                [
                    'organization_id' => $hostOrganizationId,
                    'organization_unit_type_id' => $unitTypeId,
                ],
                $actor,
                $providedCode,
                'code',
                expectedGeneratedCode: $previewedCode,
            );

            $this->assertMatchesPreview(
                StructureSheet::OrganizationUnits,
                (int) $row['__row'],
                $previewedCode,
                $code,
            );

            $key = mb_strtolower($code);

            $unit = OrganizationUnit::query()->create([
                'organization_id' => $hostOrganizationId,
                'parent_unit_id' => $parentCode !== null
                    ? ($idsByCode[mb_strtolower($parentCode)] ?? null)
                    : null,
                'organization_unit_type_id' => $unitTypeId,
                'unit_type' => mb_strtolower($typeCode),
                'code' => $code,
                'name_en' => $this->value($row, 'unit_name_en'),
                'name_am' => $this->value($row, 'unit_name_am'),
                'status' => $status !== null
                    ? OrganizationUnitStatus::from(mb_strtolower($status))->value
                    : OrganizationUnitStatus::Active->value,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $idsByCode[$key] = $unit->id;
            $created[$key] = $unit->id;

            // A functional owner different from the host is recorded as an
            // active functional_reporting relationship — the existing model the
            // position-code resolver already reads for owner/host codes.
            if ($ownerCode !== null) {
                $ownerId = $this->resolveOrganizationId($plan, $organization, $ownerCode);

                if ($ownerId !== null && $ownerId !== $hostOrganizationId) {
                    OrganizationUnitRelationship::query()->create([
                        'source_unit_id' => $unit->id,
                        'target_type' => RelationshipTargetType::Organization->value,
                        'target_id' => $ownerId,
                        'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
                        'is_primary' => true,
                        'effective_from' => now()->toDateString(),
                        'status' => RelationshipStatus::Active->value,
                        'created_by' => $actor->getKey(),
                        'updated_by' => $actor->getKey(),
                    ]);
                }
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationUnitCreated,
                $actor,
                $unit,
                $unit->organization_id,
                newValues: $unit->toArray(),
            );
        }

        return $created;
    }

    /**
     * Create the positions. A blank position_code is generated through the
     * existing Code Rules pipeline, exactly as CreatePositionAction does — the
     * import never invents its own code format.
     *
     * @param  array<string, string>  $unitIdsByCode
     * @return array<string, Position> lowercased position code => position
     */
    private function createPositions(
        StructureImportPlan $plan,
        Organization $organization,
        array $unitIdsByCode,
        User $actor,
    ): array {
        // Units that already existed are valid targets too.
        $allUnitIds = OrganizationUnit::query()
            ->where('organization_id', $organization->id)
            ->pluck('id', 'code')
            ->mapWithKeys(static fn (string $id, string $code): array => [mb_strtolower($code) => $id])
            ->all() + $unitIdsByCode;

        $positions = [];

        foreach ($plan->positionRows as $row) {
            $manualCode = $this->value($row, 'position_code');
            $unitCode = $this->value($row, 'unit_code');
            $gradeCode = $this->value($row, 'job_grade_code');
            $professionCode = $this->value($row, 'profession_code');
            $status = $this->value($row, 'position_status');

            $organizationUnitId = $unitCode !== null
                ? ($allUnitIds[mb_strtolower($unitCode)] ?? null)
                : null;

            $codeContext = [
                'organization_id' => $organization->id,
                'organization_unit_id' => $organizationUnitId,
            ];

            if ($manualCode === null) {
                // Now that the organization and its units exist, the normal
                // owner/host resolver can do its job against real rows.
                $resolved = $this->positionCodeContextResolver->validateForGeneration(
                    $organization->id,
                    $organizationUnitId,
                );

                $codeContext['owner_organization_id'] = $resolved['owner_organization_id'];
                $codeContext['host_organization_id'] = $resolved['host_organization_id'];
            }

            // A blank cell is generated for real here — under the code rule's
            // own lock, which advances the sequence so the next import gets the
            // next number. The projection the preview showed is *checked*
            // against the result, not substituted for it: substituting would
            // leave the sequence un-advanced and hand the same code out twice.
            $previewedCode = $this->value($row, '__code');

            $jobPositionCode = $this->generateCodeAction->executeUsingPreviewedCode(
                CodeRuleEntityType::Position,
                $codeContext,
                $actor,
                $manualCode,
                'job_position_code',
                expectedGeneratedCode: $previewedCode,
            );

            // Another import may have consumed the sequence between preview and
            // confirm. Fail the whole transaction rather than quietly writing a
            // different code than the one the user approved.
            $this->assertMatchesPreview(
                StructureSheet::Positions,
                (int) $row['__row'],
                $previewedCode,
                $jobPositionCode,
            );

            $position = Position::query()->create([
                'organization_id' => $organization->id,
                'organization_unit_id' => $organizationUnitId,
                'occupation_id' => $professionCode !== null
                    ? ($plan->occupationIds[mb_strtoupper($professionCode, 'UTF-8')] ?? null)
                    : null,
                'job_position_code' => $jobPositionCode,
                'old_code' => $this->value($row, 'old_code'),
                'title_en' => $this->value($row, 'standard_name'),
                'bpr_name' => $this->value($row, 'bpr_name'),
                'grade_level' => $gradeCode !== null
                    ? ($plan->gradeLevelNames[mb_strtolower($gradeCode)] ?? null)
                    : null,
                'is_active' => $status === null || mb_strtolower($status) === 'active',
                'effective_from' => now()->toDateString(),
                'metadata' => ['slots' => max(1, (int) ($this->value($row, 'slots') ?? 1))],
            ]);

            $positions[mb_strtolower($jobPositionCode)] = $position;

            // Keep the sheet's own code as a key too, so an employee row that
            // referenced a code the file supplied still resolves.
            if ($manualCode !== null) {
                $positions[mb_strtolower($manualCode)] = $position;
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::PositionCreated,
                $actor,
                $position,
                $position->organization_id,
                newValues: $position->toArray(),
            );
        }

        return $positions;
    }

    /**
     * Create employees and their active assignments.
     *
     * @param  array<string, Position>  $positionsByCode
     * @return array{employees: int, assignments: int}
     */
    private function createEmployees(
        StructureImportPlan $plan,
        Organization $organization,
        array $positionsByCode,
        User $actor,
    ): array {
        $employees = 0;
        $assignments = 0;

        foreach ($plan->employeeRows as $row) {
            $positionCode = $this->value($row, 'position_code');
            $startDate = $this->value($row, 'assignment_start_date');

            // An employee row may carry no assignment at all (both cells blank);
            // the validator allows that, so the person is created unassigned.
            $wantsAssignment = $positionCode !== null && $startDate !== null;

            $position = null;

            if ($wantsAssignment) {
                $position = $positionsByCode[mb_strtolower($positionCode)] ?? null;

                if ($position === null) {
                    // Not created by this file — must be an existing position, as
                    // the validator already confirmed.
                    $position = Position::query()
                        ->where('organization_id', $organization->id)
                        ->where('job_position_code', $positionCode)
                        ->first();
                }

                if ($position === null) {
                    throw new RuntimeException("Position [{$positionCode}] could not be resolved during import.");
                }
            }

            $firstName = (string) $this->value($row, 'first_name');
            $fatherName = (string) $this->value($row, 'father_name');
            $grandfatherName = $this->value($row, 'grandfather_name');
            $status = $this->value($row, 'employment_status');

            // No organization in the context on purpose: an employee number must
            // survive a transfer between organizations, so it comes from the
            // global employee sequence.
            $previewedNumber = $this->value($row, '__code');

            $employeeNumber = $this->generateCodeAction->executeUsingPreviewedCode(
                CodeRuleEntityType::Employee,
                [],
                $actor,
                $this->value($row, 'employee_number'),
                'employee_number',
                expectedGeneratedCode: $previewedNumber,
            );

            $this->assertMatchesPreview(
                StructureSheet::Employees,
                (int) $row['__row'],
                $previewedNumber,
                $employeeNumber,
            );

            $employee = Employee::query()->create([
                'employee_number' => $employeeNumber,
                'first_name' => $firstName,
                'middle_name' => $fatherName,
                'last_name' => $grandfatherName ?? $fatherName,
                'full_name' => trim(implode(' ', array_filter([$firstName, $fatherName, $grandfatherName]))),
                'gender' => $this->value($row, 'gender'),
                'phone' => $this->value($row, 'phone'),
                'email' => $this->value($row, 'email'),
                'status' => $status !== null
                    ? EmployeeStatus::from(mb_strtolower($status))
                    : EmployeeStatus::Active,
            ]);

            if ($wantsAssignment) {
                $assignment = EmployeeAssignment::query()->create([
                    'employee_id' => $employee->id,
                    'organization_id' => $organization->id,
                    'organization_unit_id' => $position->organization_unit_id,
                    'position_id' => $position->id,
                    'assignment_status' => AssignmentStatus::Active,
                    'effective_from' => Carbon::parse((string) $startDate)->toDateString(),
                    'is_current' => true,
                ]);

                $employee->update(['current_assignment_id' => $assignment->id]);

                EmploymentStatusHistory::query()->create([
                    'employee_id' => $employee->id,
                    'status' => $employee->status,
                    'effective_from' => $assignment->effective_from,
                ]);

                $assignments++;
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::EmployeeCreated,
                $actor,
                $employee,
                $organization->id,
                newValues: $employee->toArray(),
            );

            $employees++;
        }

        return ['employees' => $employees, 'assignments' => $assignments];
    }

    /**
     * Guard that a code generated at confirm time is the one the preview showed.
     *
     * A mismatch means the sequence moved under us — another import (or a normal
     * create) took the number between preview and confirm. The safe response is
     * to abort the transaction, not to write a code nobody approved.
     *
     * @throws StructureImportCodeConflictException
     */
    private function assertMatchesPreview(
        StructureSheet $sheet,
        int $row,
        ?string $previewedCode,
        string $actualCode,
    ): void {
        if ($previewedCode === null || $previewedCode === $actualCode) {
            return;
        }

        throw new StructureImportCodeConflictException(__(
            'organization-structure-import.errors.code_conflict',
            [
                'sheet' => $sheet->value,
                'row' => (string) $row,
                'expected' => $previewedCode,
                'actual' => $actualCode,
            ],
        ));
    }

    // ── Preview payload ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildPreviewPayload(StructureImportPlan $plan, UploadedFile $file): array
    {
        return [
            'file_name' => $file->getClientOriginalName(),
            'can_import' => ! $plan->hasErrors(),
            'mode' => $plan->isUpdate() ? 'update' : 'create',
            'auto_generate_codes' => $plan->autoGenerateCodes,
            'organization' => $this->organizationSummary($plan),
            'unit_tree' => $this->unitTree($plan),
            // Provided vs generated code, per row — the wizard's code table.
            'codes' => array_map(
                static fn (CodeAssignment $assignment): array => $assignment->toArray(),
                $plan->codeAssignments(),
            ),
            'counts' => [
                'units' => count($plan->unitRows),
                'positions' => count($plan->positionRows),
                'employees' => count($plan->employeeRows),
            ],
            'errors' => $this->groupIssues($plan->errors()),
            'warnings' => $this->groupIssues($plan->warnings()),
            'error_count' => count($plan->errors()),
            'warning_count' => count($plan->warnings()),
        ];
    }

    /** @return array<string, mixed>|null */
    private function organizationSummary(StructureImportPlan $plan): ?array
    {
        $row = $plan->organizationRow;

        if ($row === null) {
            return null;
        }

        return [
            // The settled code — generated when the cell was left blank.
            'code' => $plan->organizationCode?->code ?? $this->value($row, 'organization_code'),
            'name_en' => $this->value($row, 'organization_name_en'),
            'name_am' => $this->value($row, 'organization_name_am'),
            'type_code' => $this->value($row, 'organization_type_code'),
            'parent_code' => $this->value($row, 'parent_organization_code'),
            'status' => $this->value($row, 'status') ?? OrganizationStatus::Active->value,
            'exists' => $plan->isUpdate(),
        ];
    }

    /**
     * Nested preview of the units the file declares, so the wizard can render
     * the structure the user is about to create.
     *
     * @return list<array<string, mixed>>
     */
    private function unitTree(StructureImportPlan $plan): array
    {
        $nodes = [];

        foreach ($plan->unitRows as $row) {
            // The settled code, so the tree shows generated codes too.
            $code = $this->value($row, '__code');

            if ($code === null) {
                continue;
            }

            $nodes[mb_strtolower($code)] = [
                'code' => $code,
                'name_en' => $this->value($row, 'unit_name_en'),
                'name_am' => $this->value($row, 'unit_name_am'),
                'type_code' => $this->value($row, 'unit_type_code'),
                'status' => $this->value($row, 'status') ?? OrganizationUnitStatus::Active->value,
                'parent_code' => $this->value($row, 'parent_unit_code'),
                'children' => [],
            ];
        }

        $roots = [];

        // Build the tree by reference so children attach to their parent node.
        foreach ($nodes as $key => $node) {
            $parentKey = $node['parent_code'] !== null ? mb_strtolower($node['parent_code']) : null;

            if ($parentKey !== null && isset($nodes[$parentKey])) {
                continue;
            }

            $roots[] = $key;
        }

        $build = function (string $key) use (&$build, $nodes): array {
            $node = $nodes[$key];

            foreach ($nodes as $childKey => $child) {
                $parentCode = $child['parent_code'];

                if ($parentCode !== null && mb_strtolower($parentCode) === $key) {
                    $node['children'][] = $build($childKey);
                }
            }

            return $node;
        };

        return array_map($build, $roots);
    }

    /**
     * Group issues by sheet, preserving row order, so the preview can render
     * one error table per sheet.
     *
     * @param  list<ImportIssue>  $issues
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupIssues(array $issues): array
    {
        $grouped = [];

        foreach ($issues as $issue) {
            $grouped[$issue->sheet->value][] = $issue->toArray();
        }

        return $grouped;
    }

    /**
     * The organization the code refers to. The subject organization may not
     * have existed at validation time (a create), so it is resolved against the
     * freshly created record here.
     */
    private function resolveOrganizationId(StructureImportPlan $plan, Organization $organization, string $code): ?string
    {
        $key = mb_strtolower($code);

        if ($organization->code !== null && mb_strtolower($organization->code) === $key) {
            return $organization->id;
        }

        $id = $plan->organizationIdsByCode[$key] ?? null;

        return $id !== '' ? $id : $organization->id;
    }

    private function value(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
