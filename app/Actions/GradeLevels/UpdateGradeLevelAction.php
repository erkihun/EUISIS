<?php

declare(strict_types=1);

namespace App\Actions\GradeLevels;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\GradeLevel;
use App\Models\User;

readonly class UpdateGradeLevelAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(GradeLevel $gradeLevel, array $attributes, User $actor): GradeLevel
    {
        $oldValues = $this->activeAttributes($gradeLevel->only(['name']));
        $activeAttributes = $this->activeAttributes($attributes);

        $gradeLevel->fill($activeAttributes);
        $gradeLevel->forceFill(['updated_by' => $actor->getKey()])->save();

        $fresh = $gradeLevel->fresh();

        $this->writeAuditLogAction->execute(
            AuditEventType::GradeLevelUpdated,
            $actor,
            $fresh,
            null,
            oldValues: $oldValues,
            newValues: $activeAttributes,
        );

        return $fresh;
    }

    /** @return array{name: string} */
    private function activeAttributes(array $attributes): array
    {
        return [
            'name' => trim((string) $attributes['name']),
        ];
    }
}
