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
            'skill_specialization_am',
            'skill_level',
            'description',
            'description_en',
            'description_am',
        ]));

        $activeAttributes = $this->activeAttributes($attributes);

        $occupation->fill($activeAttributes);
        $occupation->forceFill([
            'code' => $activeAttributes['isco_code'],
            ...Occupation::iscoAttributesFor($activeAttributes['isco_code']),
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
     * @return array{isco_code: string, name_en?: string|null, name_am?: string|null, skill_specialization?: string|null, skill_specialization_am?: string|null, skill_level?: string|null, description?: string|null, description_en?: string|null, description_am?: string|null}
     */
    private function activeAttributes(array $attributes): array
    {
        return [
            'isco_code' => trim((string) $attributes['isco_code']),
            'name_en' => $attributes['name_en'] ?? null,
            'name_am' => $attributes['name_am'] ?? null,
            'skill_specialization' => $attributes['skill_specialization'] ?? null,
            'skill_specialization_am' => $attributes['skill_specialization_am'] ?? null,
            'skill_level' => ($attributes['skill_level'] ?? null) ?: Occupation::iscoSkillLevel(trim((string) $attributes['isco_code'])),
            'description' => $attributes['description'] ?? null,
            'description_en' => $attributes['description_en'] ?? null,
            'description_am' => $attributes['description_am'] ?? null,
        ];
    }
}
