<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes an organization type or an organization unit type (the
 * EUISIS Create/Update…TypeAction pair). Codes are unique; types are retired by
 * switching them off, never deleted, because organizations and units name them.
 */
final readonly class SaveStructureType
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * @param  class-string<OrganizationType|OrganizationUnitType>  $model
     * @param  array<string, mixed>  $data  code, name_en, name_am, description_en, is_active, sort_order
     */
    public function execute(string $model, ?string $id, array $data, User $actor): OrganizationType|OrganizationUnitType
    {
        return DB::transaction(function () use ($model, $id, $data, $actor) {
            $type = $id === null ? new $model : $model::query()->lockForUpdate()->findOrFail($id);
            $taken = $model::query()->where('code', $data['code'])->when($id !== null, fn ($q) => $q->whereKeyNot($id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['code' => 'This code is already used.']);
            }

            $old = $type->exists ? $type->only(array_keys($data)) : null;
            $type->fill($data)->save();
            $kind = $model === OrganizationType::class ? 'organization_type' : 'unit_type';
            $this->audit->write($kind.($old === null ? '.created' : '.updated'), $actor, $type, null, $old, $type->only(array_keys($data)));

            return $type;
        });
    }
}
