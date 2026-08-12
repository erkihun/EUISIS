<?php

declare(strict_types=1);

namespace App\Services\CodeGeneration;

use App\Exceptions\MissingTokenContextException;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use Illuminate\Support\Carbon;

class CodeFormatTokenResolver
{
    public function __construct(
        private readonly PositionCodeContextResolver $positionCodeContextResolver,
    ) {}

    /**
     * Resolve all tokens found in the rule format string.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    public function resolveAll(CodeRule $rule, array $context, Carbon $now): array
    {
        $resolved = [];

        foreach ($this->tokenKeys() as $tokenKey) {
            try {
                $resolved["{{$tokenKey}}"] = $this->resolveToken($tokenKey, $rule, $context, $now);
            } catch (MissingTokenContextException) {
                // Leave missing context tokens unresolved (blank); callers may re-throw
                $resolved["{{$tokenKey}}"] = '';
            }
        }

        return $resolved;
    }

    /**
     * Resolve a single token value.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws MissingTokenContextException
     */
    public function resolveToken(string $token, CodeRule $rule, array $context, Carbon $now): string
    {
        $sequenceNumber = (int) ($context['_sequence_number'] ?? max(1, $rule->next_number));

        return match ($token) {
            // ── CORE ──────────────────────────────────────────────────────────
            'PREFIX' => $rule->prefix ?? '',
            'SUFFIX' => $rule->suffix ?? '',
            'SEPARATOR' => $rule->separator ?? '',
            'SEQUENCE' => str_pad((string) $sequenceNumber, (int) ($rule->sequence_length ?? 4), '0', STR_PAD_LEFT),
            'SEQUENCE_PADDED' => str_pad((string) $sequenceNumber, (int) ($rule->sequence_length ?? 4), '0', STR_PAD_LEFT),

            // ── DATE / TIME ───────────────────────────────────────────────────
            'YEAR' => $now->format('Y'),
            'YEAR_SHORT' => $now->format('y'),
            'MONTH' => $now->format('m'),
            'MONTH_NAME' => strtoupper($now->format('M')),
            'DAY' => $now->format('d'),
            'DATE' => $now->format('Ymd'),
            'TIME' => $now->format('His'),
            'TIMESTAMP' => $now->format('YmdHis'),
            // FISCAL_YEAR is approximated as the Gregorian year.
            // TODO: replace with proper Ethiopian fiscal year (starts Meskerem 1 ≈ September 11).
            'FISCAL_YEAR' => $now->format('Y'),

            // ── ORGANIZATION ──────────────────────────────────────────────────
            'ORG_CODE' => $this->resolveOrgCode($context),
            'ORG_PREFIX' => $this->resolveOrgPrefix($context),
            'ORG_NAME' => $this->resolveOrgName($context),
            'ORG_TYPE_CODE' => $this->resolveOrgTypeCode($context),
            'ORG_TYPE_PREFIX' => $this->resolveOrgTypePrefix($context),
            'ORG_TYPE_NAME' => $this->resolveOrgTypeName($context),
            'PARENT_ORG_CODE' => $this->resolveParentOrgCode($context),
            'PARENT_ORG_PREFIX' => $this->resolveParentOrgPrefix($context),
            'OWNER_ORG_CODE' => $this->resolveOwnerOrgCode($context),
            'HOST_ORG_CODE' => $this->resolveHostOrgCode($context),
            'UNIT_CODE' => $this->resolveUnitCode($context),
            'UNIT_TYPE_CODE' => $this->resolveUnitTypeCode($context),
            'UNIT_PREFIX' => $this->resolveUnitPrefix($context),
            'UNIT_TYPE_PREFIX' => $this->resolveUnitTypePrefix($context),

            // ── EMPLOYEE ──────────────────────────────────────────────────────
            'EMPLOYEE_NUMBER' => $this->resolveEmployeeNumber($context),
            'EMPLOYEE_INITIALS' => $this->resolveEmployeeInitials($context),
            'EMPLOYEE_STATUS' => $this->resolveEmployeeStatus($context),

            // ── POSITION ──────────────────────────────────────────────────────
            'POSITION_CODE', 'JOB_POSITION_CODE' => $this->resolvePositionCode($context),
            'POSITION_PREFIX' => $this->resolvePositionPrefix($context),
            'POSITION_TITLE' => $this->resolvePositionTitle($context),

            // ── SERVICE ───────────────────────────────────────────────────────
            'SERVICE_TYPE_CODE' => $this->resolveServiceTypeCode($context),
            'SERVICE_TYPE_PREFIX' => $this->resolveServiceTypePrefix($context),
            'PROVIDER_CODE' => $this->resolveProviderCode($context),
            'PROVIDER_PREFIX' => $this->resolveProviderPrefix($context),

            // ── LOCATION ──────────────────────────────────────────────────────
            'CITY_CODE' => $this->sanitize((string) ($context['city_code'] ?? '')),
            'SUB_CITY_CODE' => $this->sanitize((string) ($context['sub_city_code'] ?? '')),
            'WOREDA_CODE' => $this->sanitize((string) ($context['woreda_code'] ?? '')),

            // ── WORKFLOW ──────────────────────────────────────────────────────
            'REQUEST_TYPE' => $this->sanitize((string) ($context['request_type'] ?? '')),
            'WORKFLOW_CODE' => $this->sanitize((string) ($context['workflow_code'] ?? '')),
            'APPROVAL_STEP_CODE' => $this->sanitize((string) ($context['approval_step_code'] ?? '')),
            'DOCUMENT_TYPE_CODE' => $this->sanitize((string) ($context['document_type_code'] ?? '')),

            // ── CUSTOM ────────────────────────────────────────────────────────
            'CUSTOM' => $this->sanitize((string) ($context['custom'] ?? '')),
            'CUSTOM_1' => $this->sanitize((string) ($context['custom_1'] ?? '')),
            'CUSTOM_2' => $this->sanitize((string) ($context['custom_2'] ?? '')),
            'CUSTOM_3' => $this->sanitize((string) ($context['custom_3'] ?? '')),

            default => '',
        };
    }

    /**
     * Strip unsafe characters, uppercase, and truncate to 50 chars.
     * Allowed: Unicode letters/numbers, dash, underscore, dot, slash.
     */
    public function sanitize(string $value): string
    {
        $value = mb_strtoupper(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\-_.\/]/u', '', $value) ?? '';

        return substr($value, 0, 50);
    }

    private function sanitizeWithFallback(?string $primary, ?string $fallback): string
    {
        $sanitized = $this->sanitize((string) ($primary ?? ''));

        return $sanitized !== ''
            ? $sanitized
            : $this->sanitize((string) ($fallback ?? ''));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers — Organization
    // ──────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    private function resolveOrgCode(array $context): string
    {
        // Allow a direct code override in context (e.g. from existing code generation)
        if (isset($context['organization_code']) && $context['organization_code'] !== '') {
            return $this->sanitize((string) $context['organization_code']);
        }

        $orgId = $context['organization_id'] ?? null;

        if (empty($orgId)) {
            throw new MissingTokenContextException(
                'The token {ORG_CODE} requires missing context: organization_id'
            );
        }

        $code = Organization::query()->whereKey($orgId)->value('code');

        return $this->sanitize((string) ($code ?? ''));
    }

    /** @param array<string, mixed> $context */
    private function resolveOrgPrefix(array $context): string
    {
        $orgId = $context['organization_id'] ?? null;

        if (empty($orgId)) {
            throw new MissingTokenContextException(
                'The token {ORG_PREFIX} requires missing context: organization_id'
            );
        }

        /** @var Organization|null $org */
        $org = Organization::query()->with('type')->whereKey($orgId)->first(['id', 'code', 'organization_type_id']);

        if ($org === null) {
            return '';
        }

        // Prefer org prefix if the org model had one (no prefix column — use type prefix)
        if ($org->type !== null && $org->type->prefix !== null) {
            return $this->sanitize($org->type->prefix);
        }

        // Fall back: first 3 uppercase letters of the org code
        return $this->sanitize(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($org->code ?? '')), 0, 3));
    }

    /** @param array<string, mixed> $context */
    private function resolveOrgName(array $context): string
    {
        $orgId = $context['organization_id'] ?? null;

        if (empty($orgId)) {
            throw new MissingTokenContextException(
                'The token {ORG_NAME} requires missing context: organization_id'
            );
        }

        $name = Organization::query()->whereKey($orgId)->value('name_en');

        return $this->sanitize((string) ($name ?? ''));
    }

    /** @param array<string, mixed> $context */
    private function resolveOrgTypeCode(array $context): string
    {
        // Allow a direct code override
        if (isset($context['organization_type_code']) && $context['organization_type_code'] !== '') {
            return $this->sanitize((string) $context['organization_type_code']);
        }

        $orgTypeId = $this->resolveOrgTypeId($context);

        if ($orgTypeId === null) {
            throw new MissingTokenContextException(
                'The token {ORG_TYPE_CODE} requires missing context: organization_id or organization_type_id'
            );
        }

        $code = OrganizationType::query()->whereKey($orgTypeId)->value('code');

        return $this->sanitize((string) ($code ?? ''));
    }

    /** @param array<string, mixed> $context */
    private function resolveOrgTypePrefix(array $context): string
    {
        // Allow a direct prefix override
        if (isset($context['organization_type_prefix']) && $context['organization_type_prefix'] !== '') {
            return $this->sanitize((string) $context['organization_type_prefix']);
        }

        $orgTypeId = $this->resolveOrgTypeId($context);

        if ($orgTypeId === null) {
            throw new MissingTokenContextException(
                'The token {ORG_TYPE_PREFIX} requires missing context: organization_id or organization_type_id'
            );
        }

        $orgType = OrganizationType::query()->whereKey($orgTypeId)->first(['code', 'prefix']);

        if ($orgType === null) {
            return '';
        }

        return $this->sanitize($orgType->prefix ?? $orgType->code ?? '');
    }

    /** @param array<string, mixed> $context */
    private function resolveOrgTypeName(array $context): string
    {
        $orgTypeId = $this->resolveOrgTypeId($context);

        if ($orgTypeId === null) {
            throw new MissingTokenContextException(
                'The token {ORG_TYPE_NAME} requires missing context: organization_id or organization_type_id'
            );
        }

        $name = OrganizationType::query()->whereKey($orgTypeId)->value('name_en');

        return $this->sanitize((string) ($name ?? ''));
    }

    /**
     * Resolve the organization_type_id from context — either directly or via organization_id.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveOrgTypeId(array $context): mixed
    {
        if (! empty($context['organization_type_id'])) {
            return $context['organization_type_id'];
        }

        if (! empty($context['organization_id'])) {
            return Organization::query()->whereKey($context['organization_id'])->value('organization_type_id');
        }

        return null;
    }

    /** @param array<string, mixed> $context */
    private function resolveParentOrgCode(array $context): string
    {
        $parentId = $context['parent_organization_id'] ?? null;

        if (empty($parentId)) {
            throw new MissingTokenContextException(
                'The token {PARENT_ORG_CODE} requires missing context: parent_organization_id'
            );
        }

        $code = Organization::query()->whereKey($parentId)->value('code');

        return $this->sanitize((string) ($code ?? ''));
    }

    /** @param array<string, mixed> $context */
    private function resolveParentOrgPrefix(array $context): string
    {
        $parentId = $context['parent_organization_id'] ?? null;

        if (empty($parentId)) {
            throw new MissingTokenContextException(
                'The token {PARENT_ORG_PREFIX} requires missing context: parent_organization_id'
            );
        }

        /** @var Organization|null $parent */
        $parent = Organization::query()->with('type')->whereKey($parentId)->first(['id', 'code', 'organization_type_id']);

        if ($parent === null) {
            return '';
        }

        if ($parent->type !== null && $parent->type->prefix !== null) {
            return $this->sanitize($parent->type->prefix);
        }

        return $this->sanitize(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($parent->code ?? '')), 0, 3));
    }

    /**
     * Code of the organization that owns the unit's function/mandate.
     * Falls back to the plain organization code when the unit is not hosted.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveOwnerOrgCode(array $context): string
    {
        // An explicit code short-circuits the id → DB lookup. This is what lets
        // a code be generated for an organization that does not exist yet (the
        // structure importer previewing a brand-new organization); everywhere
        // else the id path below is used exactly as before.
        if (! empty($context['owner_organization_code'])) {
            return $this->sanitize((string) $context['owner_organization_code']);
        }

        $ownerId = $context['owner_organization_id'] ?? null;

        if (empty($ownerId)) {
            $resolved = $this->positionCodeContextResolver->resolve(
                isset($context['organization_id']) ? (string) $context['organization_id'] : null,
                isset($context['organization_unit_id']) ? (string) $context['organization_unit_id'] : null,
            );
            $ownerId = $resolved['owner_organization_id'];
        }

        if (empty($ownerId)) {
            throw new MissingTokenContextException(
                'The token {OWNER_ORG_CODE} requires missing context: organization_id or organization_unit_id'
            );
        }

        $code = Organization::query()->whereKey($ownerId)->value('code');

        return $this->sanitize((string) ($code ?? ''));
    }

    /**
     * Code of the organization the unit operates inside, or empty when the
     * unit is not hosted by another organization. Never throws — the host
     * segment is optional by design.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveHostOrgCode(array $context): string
    {
        // See resolveOwnerOrgCode(): an explicit code lets the importer project
        // a position code before the organization row exists.
        if (! empty($context['host_organization_code'])) {
            return $this->sanitize((string) $context['host_organization_code']);
        }

        $hostId = $context['host_organization_id'] ?? null;

        if (empty($hostId)) {
            $resolved = $this->positionCodeContextResolver->resolve(
                isset($context['organization_id']) ? (string) $context['organization_id'] : null,
                isset($context['organization_unit_id']) ? (string) $context['organization_unit_id'] : null,
            );
            $hostId = $resolved['host_organization_id'];
        }

        if (empty($hostId)) {
            return '';
        }

        $code = Organization::query()->whereKey($hostId)->value('code');

        return $this->sanitize((string) ($code ?? ''));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers — Organization Unit
    // ──────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    private function resolveUnitCode(array $context): string
    {
        $unitId = $context['organization_unit_id'] ?? null;

        if (empty($unitId)) {
            throw new MissingTokenContextException(
                'The token {UNIT_CODE} requires missing context: organization_unit_id'
            );
        }

        $code = OrganizationUnit::query()->whereKey($unitId)->value('code');

        return $this->sanitize((string) ($code ?? ''));
    }

    /** @param array<string, mixed> $context */
    private function resolveUnitTypeCode(array $context): string
    {
        $unitTypeId = $context['organization_unit_type_id'] ?? null;

        if (! empty($unitTypeId)) {
            $code = OrganizationUnitType::query()->whereKey($unitTypeId)->value('code');

            return $this->sanitize((string) ($code ?? ''));
        }

        $unitId = $context['organization_unit_id'] ?? null;

        if (empty($unitId)) {
            throw new MissingTokenContextException(
                'The token {UNIT_TYPE_CODE} requires missing context: organization_unit_type_id or organization_unit_id'
            );
        }

        /** @var OrganizationUnit|null $unit */
        $unit = OrganizationUnit::query()->whereKey($unitId)->first(['id', 'organization_unit_type_id', 'unit_type']);

        if ($unit === null) {
            return '';
        }

        // Prefer the typed relation if present
        if ($unit->organization_unit_type_id !== null) {
            $code = OrganizationUnitType::query()->whereKey($unit->organization_unit_type_id)->value('code');

            return $this->sanitize((string) ($code ?? ''));
        }

        // Fall back to the plain unit_type string column
        return $this->sanitize((string) ($unit->unit_type ?? ''));
    }

    /** @param array<string, mixed> $context */
    private function resolveUnitPrefix(array $context): string
    {
        $unitTypeId = $context['organization_unit_type_id'] ?? null;

        if (! empty($unitTypeId)) {
            $type = OrganizationUnitType::query()->whereKey($unitTypeId)->first(['prefix', 'code']);

            if ($type !== null) {
                return $this->sanitizeWithFallback($type->prefix, $type->code);
            }
        }

        $unitId = $context['organization_unit_id'] ?? null;

        if (empty($unitId)) {
            throw new MissingTokenContextException(
                'The token {UNIT_PREFIX} requires missing context: organization_unit_type_id or organization_unit_id'
            );
        }

        /** @var OrganizationUnit|null $unit */
        $unit = OrganizationUnit::query()->whereKey($unitId)->first(['id', 'code', 'organization_unit_type_id']);

        if ($unit === null) {
            return '';
        }

        if ($unit->organization_unit_type_id !== null) {
            $type = OrganizationUnitType::query()->whereKey($unit->organization_unit_type_id)->first(['prefix', 'code']);

            if ($type !== null) {
                if ($type->prefix !== null && $type->prefix !== '') {
                    return $this->sanitize($type->prefix);
                }

                if ($type->code !== null && $type->code !== '') {
                    return $this->sanitize(substr(strtoupper($type->code), 0, 5));
                }
            }
        }

        // Fall back: first letters of unit code
        return $this->sanitize(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($unit->code ?? '')), 0, 5));
    }

    /** @param array<string, mixed> $context */
    private function resolveUnitTypePrefix(array $context): string
    {
        $unitTypeId = $context['organization_unit_type_id'] ?? null;

        if (! empty($unitTypeId)) {
            $type = OrganizationUnitType::query()->whereKey($unitTypeId)->first(['prefix', 'code']);

            if ($type === null) {
                return '';
            }

            return $this->sanitizeWithFallback($type->prefix, $type->code);
        }

        $unitId = $context['organization_unit_id'] ?? null;

        if (empty($unitId)) {
            throw new MissingTokenContextException(
                'The token {UNIT_TYPE_PREFIX} requires missing context: organization_unit_type_id or organization_unit_id'
            );
        }

        /** @var OrganizationUnit|null $unit */
        $unit = OrganizationUnit::query()->whereKey($unitId)->first(['id', 'organization_unit_type_id']);

        if ($unit === null || $unit->organization_unit_type_id === null) {
            return '';
        }

        $type = OrganizationUnitType::query()->whereKey($unit->organization_unit_type_id)->first(['prefix', 'code']);

        if ($type === null) {
            return '';
        }

        return $this->sanitizeWithFallback($type->prefix, $type->code);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers — Employee
    // ──────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    private function resolveEmployeeNumber(array $context): string
    {
        $employeeId = $context['employee_id'] ?? null;

        if (empty($employeeId)) {
            throw new MissingTokenContextException(
                'The token {EMPLOYEE_NUMBER} requires missing context: employee_id'
            );
        }

        $number = Employee::query()->whereKey($employeeId)->value('employee_number');

        return $this->sanitize((string) ($number ?? ''));
    }

    /** @param array<string, mixed> $context */
    private function resolveEmployeeInitials(array $context): string
    {
        $employeeId = $context['employee_id'] ?? null;

        if (empty($employeeId)) {
            throw new MissingTokenContextException(
                'The token {EMPLOYEE_INITIALS} requires missing context: employee_id'
            );
        }

        /** @var Employee|null $employee */
        $employee = Employee::query()->whereKey($employeeId)->first(['id', 'first_name', 'middle_name', 'last_name', 'full_name']);

        if ($employee === null) {
            return '';
        }

        $names = array_filter([
            $employee->first_name,
            $employee->last_name,
        ]);

        $initials = implode('', array_map(
            static fn (string $name): string => strtoupper(mb_substr(trim($name), 0, 1)),
            $names,
        ));

        return $this->sanitize($initials);
    }

    /** @param array<string, mixed> $context */
    private function resolveEmployeeStatus(array $context): string
    {
        $employeeId = $context['employee_id'] ?? null;

        if (empty($employeeId)) {
            throw new MissingTokenContextException(
                'The token {EMPLOYEE_STATUS} requires missing context: employee_id'
            );
        }

        /** @var Employee|null $employee */
        $employee = Employee::query()->whereKey($employeeId)->first(['id', 'status']);

        if ($employee === null) {
            return '';
        }

        $status = $employee->status instanceof \BackedEnum
            ? $employee->status->value
            : (string) $employee->status;

        return $this->sanitize($status);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers — Position
    // ──────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    private function resolvePositionCode(array $context): string
    {
        $positionId = $context['position_id'] ?? null;

        if (empty($positionId)) {
            throw new MissingTokenContextException(
                'The token {POSITION_CODE} requires missing context: position_id'
            );
        }

        /** @var Position|null $position */
        $position = Position::query()->whereKey($positionId)->first(['id', 'job_position_code', 'code']);

        if ($position === null) {
            return '';
        }

        return $this->sanitize($position->job_position_code ?? $position->code ?? '');
    }

    /** @param array<string, mixed> $context */
    private function resolvePositionPrefix(array $context): string
    {
        $positionId = $context['position_id'] ?? null;

        if (empty($positionId)) {
            throw new MissingTokenContextException(
                'The token {POSITION_PREFIX} requires missing context: position_id'
            );
        }

        /** @var Position|null $position */
        $position = Position::query()->whereKey($positionId)->first(['id', 'job_position_code', 'code']);

        if ($position === null) {
            return '';
        }

        $code = $position->job_position_code ?? $position->code ?? '';

        // Extract letters-only prefix (segment before first dash/number)
        if (preg_match('/^([A-Za-z]+)/', $code, $matches)) {
            return $this->sanitize($matches[1]);
        }

        return $this->sanitize(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($code)), 0, 5));
    }

    /** @param array<string, mixed> $context */
    private function resolvePositionTitle(array $context): string
    {
        $positionId = $context['position_id'] ?? null;

        if (empty($positionId)) {
            throw new MissingTokenContextException(
                'The token {POSITION_TITLE} requires missing context: position_id'
            );
        }

        $title = Position::query()->whereKey($positionId)->value('title_en');

        return $this->sanitize((string) ($title ?? ''));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers — Service
    // ──────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    private function resolveServiceTypeCode(array $context): string
    {
        if (isset($context['service_type_code']) && $context['service_type_code'] !== '') {
            return $this->sanitize((string) $context['service_type_code']);
        }

        $serviceTypeId = $context['service_type_id'] ?? null;

        if (empty($serviceTypeId)) {
            throw new MissingTokenContextException(
                'The token {SERVICE_TYPE_CODE} requires missing context: service_type_id'
            );
        }

        $code = ServiceType::query()->whereKey($serviceTypeId)->value('code');

        return $this->sanitize((string) ($code ?? ''));
    }

    /** @param array<string, mixed> $context */
    private function resolveServiceTypePrefix(array $context): string
    {
        $serviceTypeId = $context['service_type_id'] ?? null;

        if (empty($serviceTypeId)) {
            throw new MissingTokenContextException(
                'The token {SERVICE_TYPE_PREFIX} requires missing context: service_type_id'
            );
        }

        /** @var ServiceType|null $serviceType */
        $serviceType = ServiceType::query()->whereKey($serviceTypeId)->first(['id', 'code']);

        if ($serviceType === null) {
            return '';
        }

        // ServiceType does not have a 'prefix' column — derive from code
        $code = strtoupper($serviceType->code ?? '');

        if (preg_match('/^([A-Z]+)/', $code, $matches)) {
            return $this->sanitize($matches[1]);
        }

        return $this->sanitize(substr(preg_replace('/[^A-Z0-9]/', '', $code), 0, 5));
    }

    /** @param array<string, mixed> $context */
    private function resolveProviderCode(array $context): string
    {
        $providerId = $context['service_provider_id'] ?? null;

        if (empty($providerId)) {
            throw new MissingTokenContextException(
                'The token {PROVIDER_CODE} requires missing context: service_provider_id'
            );
        }

        $code = ServiceProvider::query()->whereKey($providerId)->value('code');

        return $this->sanitize((string) ($code ?? ''));
    }

    /** @param array<string, mixed> $context */
    private function resolveProviderPrefix(array $context): string
    {
        $providerId = $context['service_provider_id'] ?? null;

        if (empty($providerId)) {
            throw new MissingTokenContextException(
                'The token {PROVIDER_PREFIX} requires missing context: service_provider_id'
            );
        }

        /** @var ServiceProvider|null $provider */
        $provider = ServiceProvider::query()->whereKey($providerId)->first(['id', 'code']);

        if ($provider === null) {
            return '';
        }

        $code = strtoupper($provider->code ?? '');

        if (preg_match('/^([A-Z]+)/', $code, $matches)) {
            return $this->sanitize($matches[1]);
        }

        return $this->sanitize(substr(preg_replace('/[^A-Z0-9]/', '', $code), 0, 5));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * All token keys this resolver can handle.
     *
     * @return list<string>
     */
    private function tokenKeys(): array
    {
        return [
            'PREFIX', 'SUFFIX', 'SEPARATOR', 'SEQUENCE', 'SEQUENCE_PADDED',
            'YEAR', 'YEAR_SHORT', 'MONTH', 'MONTH_NAME', 'DAY', 'DATE', 'TIME', 'TIMESTAMP', 'FISCAL_YEAR',
            'ORG_CODE', 'ORG_PREFIX', 'ORG_NAME', 'ORG_TYPE_CODE', 'ORG_TYPE_PREFIX', 'ORG_TYPE_NAME',
            'PARENT_ORG_CODE', 'PARENT_ORG_PREFIX',
            'OWNER_ORG_CODE', 'HOST_ORG_CODE',
            'UNIT_CODE', 'UNIT_TYPE_CODE', 'UNIT_PREFIX', 'UNIT_TYPE_PREFIX',
            'EMPLOYEE_NUMBER', 'EMPLOYEE_INITIALS', 'EMPLOYEE_STATUS',
            'POSITION_CODE', 'JOB_POSITION_CODE', 'POSITION_PREFIX', 'POSITION_TITLE',
            'SERVICE_TYPE_CODE', 'SERVICE_TYPE_PREFIX', 'PROVIDER_CODE', 'PROVIDER_PREFIX',
            'CITY_CODE', 'SUB_CITY_CODE', 'WOREDA_CODE',
            'REQUEST_TYPE', 'WORKFLOW_CODE', 'APPROVAL_STEP_CODE', 'DOCUMENT_TYPE_CODE',
            'CUSTOM', 'CUSTOM_1', 'CUSTOM_2', 'CUSTOM_3',
        ];
    }
}
