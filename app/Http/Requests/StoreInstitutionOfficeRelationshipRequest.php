<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InstitutionOfficeRelationship;
use Illuminate\Foundation\Http\FormRequest;

class StoreInstitutionOfficeRelationshipRequest extends FormRequest
{
    use RelationshipPayload;

    public function authorize(): bool
    {
        return $this->user()?->can('create', InstitutionOfficeRelationship::class) ?? false;
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
