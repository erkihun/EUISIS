<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganizationRelationshipType;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use Illuminate\Validation\Rules\Enum;

trait RelationshipPayload
{
    protected function relationshipRules(): array
    {
        return [
            'target_type' => ['required', new Enum(RelationshipTargetType::class)],
            'target_id' => ['required', 'uuid'],
            'relationship_type' => ['required', new Enum(OrganizationRelationshipType::class)],
            'is_primary' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', new Enum(RelationshipStatus::class)],
            'notes_en' => ['nullable', 'string', 'max:2000'],
            'notes_am' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function normalizedRelationshipPayload(array $payload): array
    {
        $payload['is_primary'] = (bool) ($payload['is_primary'] ?? false);
        $payload['status'] = $payload['status'] ?? RelationshipStatus::Active->value;

        return $payload;
    }
}
