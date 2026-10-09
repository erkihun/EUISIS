<?php

declare(strict_types=1);

namespace App\Actions\GradeLevels;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\GradeLevel;
use App\Models\User;

readonly class CreateGradeLevelAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(array $attributes, User $actor): GradeLevel
    {
        $activeAttributes = $this->activeAttributes($attributes);

        $gradeLevel = new GradeLevel($activeAttributes);

        $gradeLevel->forceFill([
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::GradeLevelCreated,
            $actor,
            $gradeLevel,
            null,
            newValues: $activeAttributes,
        );

        return $gradeLevel;
    }

    /** @return array{name: string} */
    private function activeAttributes(array $attributes): array
    {
        return [
            'name' => trim((string) $attributes['name']),
        ];
    }
}
