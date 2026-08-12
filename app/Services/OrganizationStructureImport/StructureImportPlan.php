<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

use App\Models\Organization;

/**
 * The outcome of validating a workbook: every issue found, plus the resolved
 * lookups the importer needs so it does not have to re-query what the
 * validator already resolved.
 *
 * The importer refuses to run when {@see hasErrors()} — that is the mechanism
 * behind "no import without a clean preview".
 */
final readonly class StructureImportPlan
{
    /**
     * @param  list<ImportIssue>  $issues
     * @param  array<string, string>  $organizationTypeIds  type code => id
     * @param  array<string, string>  $unitTypeIds  unit type code => id
     * @param  array<string, string>  $gradeLevelNames  job grade code => grade level name
     * @param  array<string, string>  $occupationIds  profession code => occupation id
     * @param  array<string, string>  $organizationIdsByCode  org code => id (existing orgs referenced by the file)
     * @param  list<array<string, mixed>>  $unitRows  unit rows in safe parent-before-child order
     * @param  list<array<string, mixed>>  $positionRows
     * @param  list<array<string, mixed>>  $employeeRows
     */
    public function __construct(
        public array $issues,
        public ?array $organizationRow = null,
        public ?Organization $existingOrganization = null,
        public array $organizationTypeIds = [],
        public array $unitTypeIds = [],
        public array $gradeLevelNames = [],
        public array $occupationIds = [],
        public array $organizationIdsByCode = [],
        public array $unitRows = [],
        public array $positionRows = [],
        public array $employeeRows = [],
        public ?CodeAssignment $organizationCode = null,
        /** @var list<CodeAssignment> */
        public array $unitCodes = [],
        /** @var list<CodeAssignment> */
        public array $positionCodes = [],
        /** @var list<CodeAssignment> */
        public array $employeeCodes = [],
        public bool $autoGenerateCodes = true,
    ) {}

    /**
     * Every code the file settles, in sheet order — what the preview's
     * "Provided Code / Generated Code" table renders, and what the confirm step
     * checks its regenerated codes against.
     *
     * @return list<CodeAssignment>
     */
    public function codeAssignments(): array
    {
        return [
            ...($this->organizationCode !== null ? [$this->organizationCode] : []),
            ...$this->unitCodes,
            ...$this->positionCodes,
            ...$this->employeeCodes,
        ];
    }

    /** @return list<ImportIssue> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $i): bool => ! $i->isWarning));
    }

    /** @return list<ImportIssue> */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $i): bool => $i->isWarning));
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    /** True when the organization already exists and would be updated rather than created. */
    public function isUpdate(): bool
    {
        return $this->existingOrganization !== null;
    }
}
