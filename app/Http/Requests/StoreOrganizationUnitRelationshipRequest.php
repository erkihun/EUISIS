<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OrganizationUnitRelationship;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationUnitRelationshipRequest extends FormRequest
{
    use RelationshipPayload;

    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationUnitRelationship::class) ?? false;
    }

    public function rules(): array
    {
        return $this->relationshipRules();
    }

    public function payload(): array
    {
        return $this->normalizedRelationshipPayload($this->validated());
    }
}
