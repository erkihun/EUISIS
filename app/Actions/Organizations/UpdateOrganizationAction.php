<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Organization;
use App\Models\OrganizationNameHistory;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

readonly class UpdateOrganizationAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $attributes, Organization $organization, User $actor): Organization
    {
        return DB::transaction(function () use ($attributes, $organization, $actor): Organization {
            $oldValues = $organization->only([
                'organization_type_id', 'code', 'name_en', 'name_am',
                'legal_basis_ref', 'status', 'effective_from', 'effective_to',
                'logo_path', 'branding_primary_color', 'branding_secondary_color',
            ]);

            $nameChanged = $this->attributeChanged($attributes, 'name_en', $organization->name_en)
                || $this->attributeChanged($attributes, 'name_am', $organization->name_am);

            // Handle logo upload.
            $logoFile = ($attributes['logo'] ?? null) instanceof UploadedFile ? $attributes['logo'] : null;
            $removeLogo = (bool) ($attributes['remove_logo'] ?? false);
            unset($attributes['logo'], $attributes['remove_logo']);

            if ($logoFile !== null) {
                if ($organization->logo_path) {
                    Storage::disk('public')->delete($organization->logo_path);
                }
                $ext = $logoFile->getClientOriginalExtension();
                $path = $logoFile->storeAs(
                    "organizations/logos/{$organization->id}",
                    Str::uuid().'.'.$ext,
                    'public',
                );
                $attributes['logo_path'] = $path;
            } elseif ($removeLogo && $organization->logo_path) {
                Storage::disk('public')->delete($organization->logo_path);
                $attributes['logo_path'] = null;
            }

            // Normalize hex colors to uppercase.
            foreach (['branding_primary_color', 'branding_secondary_color'] as $field) {
                if (! empty($attributes[$field])) {
                    $attributes[$field] = strtoupper($attributes[$field]);
                } elseif (array_key_exists($field, $attributes) && empty($attributes[$field])) {
                    $attributes[$field] = null;
                }
            }

            $effectiveDateChanged = $this->attributeChanged($attributes, 'effective_from', $oldValues['effective_from'])
                || $this->attributeChanged($attributes, 'effective_to', $oldValues['effective_to']);

            $organization->update($attributes);

            if ($nameChanged) {
                OrganizationNameHistory::query()->create([
                    'organization_id' => $organization->id,
                    'name_en' => $organization->name_en,
                    'name_am' => $organization->name_am,
                    'effective_from' => $organization->effective_from ?? now()->toDateString(),
                    'effective_to' => $organization->effective_to,
                ]);
            } elseif ($effectiveDateChanged) {
                // Keep the current name history record in sync when only dates change.
                $organization->nameHistories()->first()?->update([
                    'effective_from' => $organization->effective_from ?? now()->toDateString(),
                    'effective_to' => $organization->effective_to,
                ]);
            }

            // Separate branding audit event when branding fields changed.
            $brandingChanged = $logoFile !== null || $removeLogo
                || ($organization->branding_primary_color !== $oldValues['branding_primary_color'])
                || ($organization->branding_secondary_color !== $oldValues['branding_secondary_color']);

            if ($brandingChanged) {
                if ($logoFile !== null || $removeLogo) {
                    $this->writeAuditLogAction->execute(
                        AuditEventType::OrganizationLogoUploaded,
                        $actor,
                        $organization,
                        $organization->id,
                        oldValues: ['logo_path' => $oldValues['logo_path']],
                        newValues: ['logo_path' => $organization->logo_path],
                    );
                }

                $this->writeAuditLogAction->execute(
                    AuditEventType::OrganizationBrandingUpdated,
                    $actor,
                    $organization,
                    $organization->id,
                    oldValues: [
                        'branding_primary_color' => $oldValues['branding_primary_color'],
                        'branding_secondary_color' => $oldValues['branding_secondary_color'],
                    ],
                    newValues: [
                        'branding_primary_color' => $organization->branding_primary_color,
                        'branding_secondary_color' => $organization->branding_secondary_color,
                    ],
                );
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationUpdated,
                $actor,
                $organization,
                $organization->id,
                oldValues: $oldValues,
                newValues: $organization->fresh()->toArray(),
            );

            return $organization->fresh();
        });
    }

    private function attributeChanged(array $attributes, string $key, mixed $currentValue): bool
    {
        if (! array_key_exists($key, $attributes)) {
            return false;
        }

        if ($currentValue instanceof DateTimeInterface) {
            return (string) $attributes[$key] !== $currentValue->format('Y-m-d');
        }

        return (string) ($attributes[$key] ?? '') !== (string) ($currentValue ?? '');
    }
}
