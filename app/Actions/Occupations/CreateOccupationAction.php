<?php

declare(strict_types=1);

namespace App\Actions\Occupations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Occupation;
use App\Models\User;

readonly class CreateOccupationAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(array $attributes, User $actor): Occupation
    {
        $activeAttributes = $this->activeAttributes($attributes);

        $occupation = new Occupation($activeAttributes);

        $occupation->forceFill([
            'code' => $activeAttributes['isco_code'],
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::OccupationCreated,
            $actor,
            $occupation,
            null,
            newValues: $activeAttributes,
        );

        return $occupation;
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
