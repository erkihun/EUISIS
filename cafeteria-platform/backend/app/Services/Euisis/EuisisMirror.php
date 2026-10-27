<?php

declare(strict_types=1);

namespace App\Services\Euisis;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The platform's copy of EUISIS master data: organizations, employees, their
 * current placement and their ID cards, keyed by the EUISIS identifiers so
 * cafeteria records (transactions, exclusions, policies) name the same
 * employee and organization in both systems. EUISIS is the writer; nothing
 * here is edited on the platform. A mirrored card is keyed by its public
 * reference (the UUID the printed QR code carries).
 */
class EuisisMirror
{
    public const PLACEHOLDER_TYPE = 'EUISIS';

    /** @param  array<string, mixed>  $data  one organization of the EUISIS directory */
    public function organization(array $data): Organization
    {
        $typeData = is_array($data['organization_type'] ?? null) ? $data['organization_type'] : null;
        $type = $this->organizationType($typeData['code'] ?? self::PLACEHOLDER_TYPE, $typeData['name_en'] ?? 'EUISIS organization', $typeData['name_am'] ?? null);

        /** @var Organization $organization */
        $organization = Organization::query()->withTrashed()->firstOrNew(['id' => (string) $data['id']]);
        $organization->exists || $organization->id = (string) $data['id']; // the key is guarded against mass assignment
        $organization->forceFill([
            'organization_type_id' => $type->id,
            'code' => (string) ($data['code'] ?? $organization->code ?? Str::upper(Str::random(8))),
            'name_en' => (string) ($data['name_en'] ?? $organization->name_en ?? $data['code'] ?? 'Organization'),
            'name_am' => $data['name_am'] ?? $organization->name_am,
            'status' => (string) ($data['status'] ?? Organization::ACTIVE),
        ])->save();

        return $organization;
    }

    /** A placeholder organization for a placement whose organization the directory has not delivered yet. */
    public function organizationStub(string $organizationId, ?array $named = null): Organization
    {
        $existing = Organization::query()->withTrashed()->find($organizationId);
        if ($existing !== null) {
            return $existing;
        }

        return $this->organization(['id' => $organizationId, 'code' => $named['code'] ?? 'ORG-'.Str::upper(Str::substr($organizationId, 0, 8)),
            'name_en' => $named['name_en'] ?? 'Organization '.Str::substr($organizationId, 0, 8), 'name_am' => $named['name_am'] ?? null, 'status' => Organization::ACTIVE]);
    }

    /** @param  array<string, mixed>  $data  one unit of an organization */
    public function unit(string $organizationId, array $data): OrganizationUnit
    {
        /** @var OrganizationUnit $unit */
        $unit = OrganizationUnit::query()->withTrashed()->firstOrNew(['id' => (string) $data['id']]);
        $unit->exists || $unit->id = (string) $data['id'];
        $unit->forceFill([
            'organization_id' => $organizationId,
            'parent_unit_id' => isset($data['parent_unit_id']) && OrganizationUnit::query()->whereKey($data['parent_unit_id'])->exists() ? $data['parent_unit_id'] : null,
            'code' => (string) ($data['code'] ?? $unit->code ?? Str::upper(Str::random(8))),
            'name_en' => (string) ($data['name_en'] ?? $unit->name_en ?? 'Unit'),
            'name_am' => $data['name_am'] ?? $unit->name_am,
            'unit_type' => (string) ($data['unit_type'] ?? $unit->unit_type ?? 'unit'),
            'status' => (string) ($data['status'] ?? 'active'),
        ])->save();

        return $unit;
    }

    /**
     * One employee of the EUISIS directory, with their active placement.
     *
     * @param  array<string, mixed>  $data
     */
    public function employee(array $data): Employee
    {
        return DB::transaction(function () use ($data): Employee {
            /** @var Employee $employee */
            $employee = Employee::query()->firstOrNew(['id' => (string) $data['id']]);
            $employee->exists || $employee->id = (string) $data['id'];
            $fullName = (string) ($data['full_name'] ?? $employee->full_name ?? '');
            $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
            $employee->forceFill([
                'employee_number' => (string) ($data['employee_number'] ?? $employee->employee_number),
                'full_name' => $fullName,
                'name_en' => $data['full_name_en'] ?? $data['name_en'] ?? $employee->name_en,
                'first_name' => $employee->first_name ?: ($parts[0] ?? $fullName),
                'last_name' => $employee->last_name ?: (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : ($parts[0] ?? $fullName)),
                'status' => (string) ($data['employment_status'] ?? $data['status'] ?? $employee->status ?? Employee::ACTIVE),
                'is_demo' => false,
            ]);
            if (! $employee->exists) {
                $employee->forceFill(['approved_at' => now(), 'metadata' => ['source' => 'euisis']]);
            }
            $employee->save();

            $assignment = is_array($data['active_assignment'] ?? null) ? $data['active_assignment'] : null;
            $organizationId = $assignment['organization_id'] ?? $data['organization_id'] ?? null;
            if ($organizationId !== null) {
                $this->placement($employee, (string) $organizationId, $assignment ?? [
                    'organization_unit_id' => $data['organization_unit_id'] ?? null,
                    'position_id' => $data['position_id'] ?? null,
                ], $data['organization'] ?? null);
            } elseif ($employee->current_assignment_id !== null) {
                $this->endPlacement($employee);
            }

            return $employee->refresh();
        });
    }

    /**
     * The employee's current placement: the EUISIS assignment when its id is
     * known, else one placement per employee and organization.
     *
     * @param  array<string, mixed>  $assignment
     */
    public function placement(Employee $employee, string $organizationId, array $assignment = [], ?array $organizationNamed = null): EmployeeAssignment
    {
        $this->organizationStub($organizationId, $organizationNamed);
        $status = (string) ($assignment['assignment_status'] ?? EmployeeAssignment::ACTIVE);
        $current = EmployeeAssignment::query()->where('employee_id', $employee->id)->where('is_current', true)->first();
        $id = isset($assignment['id']) && Str::isUuid((string) $assignment['id']) ? (string) $assignment['id'] : null;

        $row = $id !== null ? EmployeeAssignment::query()->find($id) : null;
        if ($row === null && $current !== null && $current->organization_id === $organizationId && ($id === null || $current->id === $id)) {
            $row = $current;
        }
        if ($row === null) {
            if ($current !== null) {
                $current->forceFill(['is_current' => false, 'assignment_status' => EmployeeAssignment::CLOSED, 'effective_to' => $current->effective_to ?? now()->subDay()->toDateString()])->save();
            }
            $row = new EmployeeAssignment;
            $row->id = $id ?? (string) Str::uuid();
        } elseif ($current !== null && $current->id !== $row->id) {
            $current->forceFill(['is_current' => false])->save();
        }

        $row->forceFill([
            'employee_id' => $employee->id,
            'organization_id' => $organizationId,
            'organization_unit_id' => isset($assignment['organization_unit_id']) && OrganizationUnit::query()->whereKey($assignment['organization_unit_id'])->exists() ? $assignment['organization_unit_id'] : null,
            'position_id' => isset($assignment['position_id']) && Position::query()->whereKey($assignment['position_id'])->exists() ? $assignment['position_id'] : null,
            'assignment_status' => $status,
            'effective_from' => $assignment['effective_from'] ?? $row->effective_from ?? now()->toDateString(),
            'effective_to' => $assignment['effective_to'] ?? null,
            'is_current' => true,
            'source_system' => 'euisis',
        ])->save();

        $employee->forceFill(['current_assignment_id' => $row->id])->saveQuietly();

        return $row;
    }

    public function endPlacement(Employee $employee): void
    {
        EmployeeAssignment::query()->where('employee_id', $employee->id)->where('is_current', true)
            ->update(['is_current' => false, 'assignment_status' => EmployeeAssignment::CLOSED, 'effective_to' => now()->toDateString()]);
        $employee->forceFill(['current_assignment_id' => null])->saveQuietly();
    }

    /**
     * The card (and holder) an EUISIS verification answered with.
     *
     * @param  array<string, mixed>  $verified  the `/api/v1/id-cards/verify/{uuid}` body
     */
    public function cardFromVerification(array $verified): ?IdCard
    {
        $cardData = (array) ($verified['card'] ?? []);
        $publicUuid = strtolower((string) ($cardData['card_uuid'] ?? $verified['card_uuid'] ?? ''));
        $employeeData = (array) ($verified['employee'] ?? []);
        $employeeUuid = (string) ($employeeData['employee_uuid'] ?? $verified['employee_uuid'] ?? '');
        if (! Str::isUuid($publicUuid) || ! Str::isUuid($employeeUuid)) {
            return null;
        }

        return DB::transaction(function () use ($verified, $cardData, $publicUuid, $employeeData, $employeeUuid): IdCard {
            $organization = (array) ($verified['organization'] ?? []);
            $employee = $this->employee([
                'id' => $employeeUuid,
                'employee_number' => $employeeData['employee_number'] ?? null,
                'full_name' => $employeeData['full_name'] ?? null,
                'employment_status' => $employeeData['status'] ?? null,
                'organization_id' => $verified['organization_uuid'] ?? ($organization['organization_uuid'] ?? null),
                'organization' => $organization === [] ? null : ['code' => $organization['code'] ?? null, 'name_en' => $organization['name_en'] ?? null, 'name_am' => $organization['name_am'] ?? null],
                'active_assignment' => isset($verified['organization_uuid']) ? ['organization_id' => $verified['organization_uuid'], 'assignment_status' => $verified['assignment_status'] ?? EmployeeAssignment::ACTIVE] : null,
            ]);
            if (! empty($employeeData['photo_url'])) {
                $employee->forceFill(['photo_path' => (string) $employeeData['photo_url']])->saveQuietly();
            }

            $status = (string) ($verified['status'] ?? ($verified['valid'] ?? false ? IdCard::ACTIVE : IdCard::REVOKED));
            $status = in_array($status, [IdCard::ACTIVE, IdCard::EXPIRED, IdCard::REVOKED, IdCard::REPLACED, IdCard::SUSPENDED, IdCard::LOST, IdCard::DAMAGED, IdCard::ISSUED, IdCard::PRINTED, IdCard::PENDING_PRINT], true)
                ? $status : ($verified['valid'] ?? false ? IdCard::ACTIVE : IdCard::REVOKED);

            // One current card per employee: the newly verified one.
            IdCard::query()->where('employee_id', $employeeUuid)->where('public_card_uuid', '!=', $publicUuid)->where('is_current', true)
                ->update(['is_current' => false, 'status' => IdCard::REPLACED]);

            /** @var IdCard $card */
            $card = IdCard::query()->firstOrNew(['id' => $publicUuid]);
            $card->exists || $card->id = $publicUuid;
            $card->forceFill([
                'employee_id' => $employeeUuid,
                'public_card_uuid' => $publicUuid,
                'card_number' => (string) ($cardData['card_number'] ?? $card->card_number ?? $publicUuid),
                'status' => $status,
                'is_current' => (bool) ($cardData['is_current'] ?? true),
                'issued_at' => isset($cardData['issued_at']) ? Carbon::parse((string) $cardData['issued_at']) : $card->issued_at,
                'activated_at' => $card->activated_at ?? now(),
                'expires_at' => isset($cardData['expires_at']) ? Carbon::parse((string) $cardData['expires_at'])->endOfDay() : $card->expires_at,
                'qr_status' => $status === IdCard::ACTIVE ? 'active' : 'revoked',
                'revoked_at' => $status === IdCard::REVOKED ? ($card->revoked_at ?? now()) : null,
                'token_version' => 1,
                'display_snapshot' => ['verified_at' => now()->toIso8601String(), 'source' => 'euisis'],
            ])->save();

            return $card;
        });
    }

    /** A mirrored card verified longer ago than Settings allow is asked about again. */
    public function isStale(IdCard $card): bool
    {
        $verifiedAt = $card->display_snapshot['verified_at'] ?? null;

        return $verifiedAt === null || Carbon::parse((string) $verifiedAt)->addMinutes(max(0, (int) config('euisis.card_fresh_minutes')))->isPast();
    }

    public function withinOfflineGrace(IdCard $card): bool
    {
        $verifiedAt = $card->display_snapshot['verified_at'] ?? null;

        return $verifiedAt !== null && Carbon::parse((string) $verifiedAt)->addHours(max(0, (int) config('euisis.offline_grace_hours')))->isFuture();
    }

    private function organizationType(string $code, string $nameEn, ?string $nameAm): OrganizationType
    {
        return OrganizationType::query()->firstOrCreate(['code' => $code], ['name_en' => $nameEn, 'name_am' => $nameAm]);
    }
}
