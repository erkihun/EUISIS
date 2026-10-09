<?php

declare(strict_types=1);

namespace App\Actions\Occupations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Occupation;
use App\Models\User;

readonly class UpdateOccupationAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Occupation $occupation, array $attributes, User $actor): Occupation
    {
        $oldValues = $this->activeAttributes($occupation->only([
            'isco_code',
            'name_en',
            'name_am',
            'skill_specialization',
            'description',
        ]));

        $activeAttributes = $this->activeAttributes($attributes);

        $occupation->fill($activeAttributes);
        $occupation->forceFill([
            'code' => $activeAttributes['isco_code'],
            'updated_by' => $actor->getKey(),
        ])->save();

        $freshOccupation = $occupation->fresh();

        $this->writeAuditLogAction->execute(
            AuditEventType::OccupationUpdated,
            $actor,
            $freshOccupation,
            null,
            oldValues: $oldValues,
            newValues: $activeAttributes,
        );

        return $freshOccupation;
    }

    /**
     * @return array{isco_code: string, name_en?: string|null, name_am?: string|null, skill_specialization?: string|null, description?: string|null}
     */
    private function activeAttributes(array $attributes): array
    {
        return [
            'isco_code' => trim((string) $attributes['isco_code']),
            'name_en' => $attributes['name_en'] ?? null,
            'name_am' => $attributes['name_am'] ?? null,
            'skill_specialization' => $attributes['skill_specialization'] ?? null,
            'description' => $attributes['description'] ?? null,
        ];
    }
}
