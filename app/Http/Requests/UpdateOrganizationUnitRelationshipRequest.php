<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationUnitRelationshipRequest extends FormRequest
{
    use RelationshipPayload;

    public function authorize(): bool
    {
        $relationship = $this->route('relationship');

        return $relationship !== null && ($this->user()?->can('update', $relationship) ?? false);
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
