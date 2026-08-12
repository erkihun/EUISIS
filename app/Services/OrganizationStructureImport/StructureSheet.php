<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

/**
 * The four sheets of the Organization Structure workbook, together with the
 * columns each one requires and the columns it merely accepts.
 *
 * Sheet names are matched case-insensitively and ignoring spaces/underscores,
 * so "Organization Units", "organization_units" and "ORGANIZATIONUNITS" all
 * resolve to the same sheet. Column headers are normalised the same way.
 */
enum StructureSheet: string
{
    case Organization = 'Organization';
    case OrganizationUnits = 'Organization Units';
    case Positions = 'Positions';
    case Employees = 'Employees';

    /** Sheets that must be present in every workbook. */
    public function isRequired(): bool
    {
        return $this !== self::Employees;
    }

    /**
     * Columns whose *values* the business requires on every row.
     *
     * Code columns are deliberately absent: an empty code is not a mistake, it
     * is the request to generate one from the Code Rules (see
     * {@see CodeProjector}). Only the business fields that no rule can invent
     * are required here.
     *
     * `assignment_start_date` is likewise not listed — an employee row may omit
     * it (and position_code) to import a person without an assignment; the
     * validator requires the pair together, not individually.
     */
    public function requiredColumns(): array
    {
        return match ($this) {
            self::Organization => [
                'organization_name_en',
                'organization_type_code',
            ],
            self::OrganizationUnits => [
                'unit_name_en',
                'unit_type_code',
            ],
            self::Positions => [
                'standard_name',
            ],
            self::Employees => [
                'first_name',
                'father_name',
            ],
        };
    }

    /**
     * The code column of each sheet — nullable, and generated from the Code
     * Rules when left blank.
     */
    public function codeColumn(): string
    {
        return match ($this) {
            self::Organization => 'organization_code',
            self::OrganizationUnits => 'unit_code',
            self::Positions => 'position_code',
            self::Employees => 'employee_number',
        };
    }

    /** Every column the sheet understands; anything else is ignored. */
    public function knownColumns(): array
    {
        return match ($this) {
            self::Organization => [
                'organization_code',
                'organization_name_en',
                'organization_name_am',
                'organization_type_code',
                'parent_organization_code',
                'status',
            ],
            self::OrganizationUnits => [
                'unit_code',
                'unit_name_en',
                'unit_name_am',
                'unit_type_code',
                'parent_unit_code',
                'host_organization_code',
                'functional_owner_organization_code',
                'status',
            ],
            self::Positions => [
                'position_code',
                'old_code',
                'standard_name',
                'bpr_name',
                'unit_code',
                'job_grade_code',
                'position_status',
                'profession_code',
                'slots',
            ],
            self::Employees => [
                'employee_number',
                'first_name',
                'father_name',
                'grandfather_name',
                'gender',
                'phone',
                'email',
                'position_code',
                'assignment_start_date',
                'employment_status',
            ],
        };
    }

    /**
     * Normalise a sheet name or column header for comparison: lowercase, with
     * spaces, hyphens and underscores stripped.
     */
    public static function normalize(string $value): string
    {
        return preg_replace('/[\s_\-]+/u', '', mb_strtolower(trim($value), 'UTF-8')) ?? '';
    }

    /** Resolve a raw worksheet title to one of the known sheets, if any. */
    public static function fromTitle(string $title): ?self
    {
        $needle = self::normalize($title);

        foreach (self::cases() as $sheet) {
            if (self::normalize($sheet->value) === $needle) {
                return $sheet;
            }
        }

        return null;
    }
}
