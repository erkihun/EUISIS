<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\CompetencyLevel;
use App\Models\CompetencyType;
use App\Models\EducationLevel;
use App\Models\Profession;
use App\Models\Specialization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One request for the small city-level catalogs of phase 4: professions,
 * specializations, competency types, competency levels and education
 * levels. The route tells which; the record on the route (if any) makes
 * the request an update, where every field is optional.
 */
class CatalogEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->record();
        $permission = in_array($this->kind(), ['profession', 'specialization'], true) ? 'professions.manage' : 'competency-catalog.manage';

        return ($this->user()?->can($permission) ?? false) && ($record === null || $record->exists);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $record = $this->record();
        $required = $record ? 'sometimes' : 'required';
        $kind = $this->kind();
        $base = [
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
        $codeRule = fn (string $table, ?callable $where = null) => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/',
            tap(Rule::unique($table, 'code')->ignore($record), fn ($rule) => $where ? $where($rule) : null)];

        return match ($kind) {
            'profession' => [
                ...$base,
                'occupation_id' => [$required, 'uuid', 'exists:occupations,id'],
                'code' => $codeRule('professions'),
                'description_en' => ['nullable', 'string', 'max:2000'],
                'description_am' => ['nullable', 'string', 'max:2000'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ],
            'specialization' => [
                ...$base,
                'code' => $codeRule('specializations', fn ($rule) => $rule->where('profession_id', $record instanceof Specialization ? $record->profession_id : $this->route('profession')?->getKey())),
                'description_en' => ['nullable', 'string', 'max:2000'],
                'description_am' => ['nullable', 'string', 'max:2000'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ],
            'competency_type' => [
                ...$base,
                'code' => $codeRule('competency_types'),
                'description_en' => ['nullable', 'string', 'max:2000'],
                'description_am' => ['nullable', 'string', 'max:2000'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ],
            'competency_level' => [
                ...$base,
                'code' => $codeRule('competency_levels'),
                'rank' => [$required, 'integer', 'min:1', 'max:'.CompetencyLevel::MAX_RANK, Rule::unique('competency_levels', 'rank')->ignore($record)],
                'description_en' => ['nullable', 'string', 'max:2000'],
                'description_am' => ['nullable', 'string', 'max:2000'],
            ],
            'education_level' => [
                ...$base,
                'code' => $codeRule('education_levels'),
                'rank' => [$required, 'integer', 'min:1', 'max:20', Rule::unique('education_levels', 'rank')->ignore($record)],
            ],
            default => abort(404),
        };
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => __('organization-design.fields.code'),
            'name_en' => __('organization-design.fields.name_en'),
            'rank' => __('organization-design.fields.rank'),
            'occupation_id' => __('organization-design.fields.occupation'),
        ];
    }

    public function kind(): string
    {
        $name = (string) $this->route()?->getName();

        return match (true) {
            str_contains($name, 'specializations') => 'specialization',
            str_contains($name, 'professions') => 'profession',
            str_contains($name, 'competency-types') => 'competency_type',
            str_contains($name, 'competency-levels') => 'competency_level',
            str_contains($name, 'education-levels') => 'education_level',
            default => 'unknown',
        };
    }

    public function record(): ?Model
    {
        foreach (['profession', 'specialization', 'type', 'level', 'educationLevel'] as $param) {
            $value = $this->route($param);
            if ($value instanceof Model && $this->isUpdate()) {
                return $value;
            }
        }

        return null;
    }

    private function isUpdate(): bool
    {
        return in_array($this->method(), ['PUT', 'PATCH'], true);
    }
}
