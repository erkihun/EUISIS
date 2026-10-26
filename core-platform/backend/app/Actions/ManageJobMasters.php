<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\EmploymentType;
use App\Models\JobGrade;
use App\Models\JobLevel;
use App\Models\Occupation;
use App\Models\PositionCategory;
use App\Models\Profession;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The job master data (ADR 0010): grades, levels, occupations, professions,
 * employment types and position categories. A city-wide holder of
 * positions.master.manage adds and edits values; a value is deactivated, never
 * deleted, so historical positions keep their meaning. Every change is audited.
 */
final readonly class ManageJobMasters
{
    /** kind => [model, the position column that references it] */
    public const KINDS = [
        'grades' => [JobGrade::class, 'job_grade_id'],
        'levels' => [JobLevel::class, 'job_level_id'],
        'occupations' => [Occupation::class, 'occupation_id'],
        'professions' => [Profession::class, 'profession_id'],
        'employment_types' => [EmploymentType::class, 'employment_type_id'],
        'categories' => [PositionCategory::class, 'position_category_id'],
    ];

    public function __construct(private AuditWriter $audit) {}

    /** @param array<string, mixed> $input */
    public function save(string $kind, ?string $id, array $input, User $actor): Model
    {
        abort_unless($actor->is_active && $actor->can('positions.master.manage'), 403);
        [$class] = self::KINDS[$kind] ?? abort(404);
        $existing = $id === null ? null : $class::query()->findOrFail($id);
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique((new $class)->getTable(), 'code')->ignore($existing?->getKey())],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($class, $existing, $data, $kind, $actor): Model {
            $old = $existing?->only(array_keys($data));
            $model = $existing ?? new $class;
            $model->fill($data + ['sort_order' => $data['sort_order'] ?? 0])->forceFill(['needs_review' => false])->save();
            $this->audit->writeFor($existing === null ? 'job_master.created' : 'job_master.updated', $actor, 'JobMaster:'.$kind, (string) $model->getKey(), null, $old, $model->only(array_keys($data)));

            return $model;
        });
    }
}
