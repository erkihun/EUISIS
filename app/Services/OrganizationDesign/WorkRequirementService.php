<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Models\Competency;
use App\Models\CompetencyLevel;
use App\Models\EducationLevel;
use App\Models\Occupation;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Profession;
use App\Models\Specialization;
use App\Models\User;
use App\Models\WorkCompetencyRequirement;
use App\Models\WorkOccupationRequirement;
use App\Models\WorkQualificationRequirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The occupation, competency and qualification requirements of an activity
 * or task (brief §29–34). Replaced as sets while the process is a draft;
 * frozen with the published process; copied into a new version.
 */
class WorkRequirementService
{
    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    /**
     * Replace whichever requirement sets are present in $data
     * (occupations / competencies / qualifications).
     *
     * @param  array<string, mixed>  $data
     */
    public function sync(User $actor, ProcessActivity|ProcessTask $subject, array $data): void
    {
        $process = $subject->process;
        if (! $process->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.process_not_draft')]);
        }
        $column = $subject instanceof ProcessTask ? 'task_id' : 'activity_id';

        $occupations = array_key_exists('occupations', $data) ? $this->validOccupations($data['occupations']) : null;
        $competencies = array_key_exists('competencies', $data) ? $this->validCompetencies($data['competencies']) : null;
        $qualifications = array_key_exists('qualifications', $data) ? $this->validQualifications($data['qualifications']) : null;

        DB::transaction(function () use ($actor, $subject, $process, $column, $occupations, $competencies, $qualifications): void {
            $base = ['organization_id' => $process->organization_id, $column => $subject->getKey()];
            if ($occupations !== null) {
                WorkOccupationRequirement::query()->where($column, $subject->getKey())->delete();
                foreach ($occupations as $i => $row) {
                    WorkOccupationRequirement::query()->create([...$base, ...$row, 'sequence' => $i]);
                }
            }
            if ($competencies !== null) {
                WorkCompetencyRequirement::query()->where($column, $subject->getKey())->delete();
                foreach ($competencies as $i => $row) {
                    WorkCompetencyRequirement::query()->create([...$base, ...$row, 'sequence' => $i]);
                }
            }
            if ($qualifications !== null) {
                WorkQualificationRequirement::query()->where($column, $subject->getKey())->delete();
                foreach ($qualifications as $i => $row) {
                    WorkQualificationRequirement::query()->create([...$base, ...$row, 'sequence' => $i]);
                }
            }
            $this->audit->record(AuditEventType::WorkRequirementsSynced, $actor, $subject, [
                'occupations' => $occupations === null ? null : count($occupations),
                'competencies' => $competencies === null ? null : count($competencies),
                'qualifications' => $qualifications === null ? null : count($qualifications),
            ]);
        });
    }

    /**
     * Copy every requirement of the old tree onto the new one.
     *
     * @param  array<string, string>  $map  old activity/task id → new id
     */
    public function copy(array $map): void
    {
        foreach ([WorkOccupationRequirement::class, WorkCompetencyRequirement::class, WorkQualificationRequirement::class] as $model) {
            $rows = $model::query()->where(fn ($q) => $q->whereIn('activity_id', array_keys($map))->orWhereIn('task_id', array_keys($map)))->get();
            foreach ($rows as $row) {
                $attributes = $row->getAttributes();
                unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
                $attributes['activity_id'] = $row->activity_id === null ? null : ($map[$row->activity_id] ?? null);
                $attributes['task_id'] = $row->task_id === null ? null : ($map[$row->task_id] ?? null);
                if ($attributes['activity_id'] === null && $attributes['task_id'] === null) {
                    continue;
                }
                $model::query()->create($attributes);
            }
        }
    }

    /**
     * Requirements of every activity and task of a process, keyed by subject id.
     *
     * @param  list<string>  $activityIds
     * @param  list<string>  $taskIds
     * @return array<string, array{occupations: list<array<string, mixed>>, competencies: list<array<string, mixed>>, qualifications: list<array<string, mixed>>}>
     */
    public function forSubjects(array $activityIds, array $taskIds): array
    {
        $out = [];
        $key = fn ($row) => $row->task_id ?? $row->activity_id;
        $ensure = function (string $id) use (&$out): void {
            $out[$id] ??= ['occupations' => [], 'competencies' => [], 'qualifications' => []];
        };
        $subject = fn ($q) => $q->whereIn('activity_id', $activityIds)->orWhereIn('task_id', $taskIds);

        WorkOccupationRequirement::query()->where($subject)->with(['occupation:id,code,isco_code,name_en,name_am', 'profession:id,code,name_en,name_am', 'specialization:id,code,name_en,name_am'])
            ->orderBy('sequence')->get()->each(function (WorkOccupationRequirement $r) use (&$out, $key, $ensure): void {
                $ensure($key($r));
                $out[$key($r)]['occupations'][] = [
                    'id' => $r->id, 'occupation_id' => $r->occupation_id, 'profession_id' => $r->profession_id, 'specialization_id' => $r->specialization_id, 'is_primary' => $r->is_primary,
                    'occupation' => $r->occupation?->only(['id', 'code', 'isco_code', 'name_en', 'name_am']),
                    'profession' => $r->profession?->only(['id', 'code', 'name_en', 'name_am']),
                    'specialization' => $r->specialization?->only(['id', 'code', 'name_en', 'name_am']),
                ];
            });
        WorkCompetencyRequirement::query()->where($subject)->with(['competency:id,code,name_en,name_am,competency_type_id', 'level:id,code,name_en,name_am,rank'])
            ->orderBy('sequence')->get()->each(function (WorkCompetencyRequirement $r) use (&$out, $key, $ensure): void {
                $ensure($key($r));
                $out[$key($r)]['competencies'][] = [
                    'id' => $r->id, 'competency_id' => $r->competency_id, 'minimum_level_id' => $r->minimum_level_id, 'is_mandatory' => $r->is_mandatory,
                    'competency' => $r->competency?->only(['id', 'code', 'name_en', 'name_am']),
                    'minimum_level' => $r->level?->only(['id', 'code', 'name_en', 'name_am', 'rank']),
                ];
            });
        WorkQualificationRequirement::query()->where($subject)->with('educationLevel:id,code,name_en,name_am,rank')
            ->orderBy('sequence')->get()->each(function (WorkQualificationRequirement $r) use (&$out, $key, $ensure): void {
                $ensure($key($r));
                $out[$key($r)]['qualifications'][] = [
                    'id' => $r->id, 'education_level_id' => $r->education_level_id, 'field_of_study' => $r->field_of_study, 'certification' => $r->certification,
                    'license' => $r->license, 'experience_years' => $r->experience_years, 'is_mandatory' => $r->is_mandatory,
                    'education_level' => $r->educationLevel?->only(['id', 'code', 'name_en', 'name_am', 'rank']),
                ];
            });

        return $out;
    }

    public static function empty(): array
    {
        return ['occupations' => [], 'competencies' => [], 'qualifications' => []];
    }

    // ── Validation beyond the form request: catalog consistency ─────────

    /** @return list<array<string, mixed>> */
    private function validOccupations(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $occupation = Occupation::query()->whereKey($row['occupation_id'] ?? null)->where('is_active', true)->first();
            if ($occupation === null) {
                throw ValidationException::withMessages(["occupations.{$i}.occupation_id" => __('organization-design.requirement_occupation_invalid')]);
            }
            $profession = null;
            if (! empty($row['profession_id'])) {
                $profession = Profession::query()->whereKey($row['profession_id'])->where('occupation_id', $occupation->getKey())->where('is_active', true)->first();
                if ($profession === null) {
                    throw ValidationException::withMessages(["occupations.{$i}.profession_id" => __('organization-design.requirement_profession_invalid')]);
                }
            }
            $specialization = null;
            if (! empty($row['specialization_id'])) {
                $specialization = $profession === null ? null : Specialization::query()->whereKey($row['specialization_id'])->where('profession_id', $profession->getKey())->where('is_active', true)->first();
                if ($specialization === null) {
                    throw ValidationException::withMessages(["occupations.{$i}.specialization_id" => __('organization-design.requirement_specialization_invalid')]);
                }
            }
            $out[] = ['occupation_id' => $occupation->getKey(), 'profession_id' => $profession?->getKey(), 'specialization_id' => $specialization?->getKey(), 'is_primary' => (bool) ($row['is_primary'] ?? false)];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function validCompetencies(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach (array_values($rows) as $i => $row) {
            $competency = Competency::query()->whereKey($row['competency_id'] ?? null)->where('is_active', true)->first();
            if ($competency === null) {
                throw ValidationException::withMessages(["competencies.{$i}.competency_id" => __('organization-design.requirement_competency_invalid')]);
            }
            if (isset($seen[$competency->getKey()])) {
                throw ValidationException::withMessages(["competencies.{$i}.competency_id" => __('organization-design.requirement_competency_duplicate')]);
            }
            $seen[$competency->getKey()] = true;
            $level = CompetencyLevel::query()->whereKey($row['minimum_level_id'] ?? null)->where('is_active', true)->first();
            if ($level === null) {
                throw ValidationException::withMessages(["competencies.{$i}.minimum_level_id" => __('organization-design.requirement_level_invalid')]);
            }
            $out[] = ['competency_id' => $competency->getKey(), 'minimum_level_id' => $level->getKey(), 'is_mandatory' => (bool) ($row['is_mandatory'] ?? true)];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function validQualifications(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $level = EducationLevel::query()->whereKey($row['education_level_id'] ?? null)->where('is_active', true)->first();
            if ($level === null) {
                throw ValidationException::withMessages(["qualifications.{$i}.education_level_id" => __('organization-design.requirement_education_invalid')]);
            }
            $out[] = [
                'education_level_id' => $level->getKey(),
                'field_of_study' => $row['field_of_study'] ?? null,
                'certification' => $row['certification'] ?? null,
                'license' => $row['license'] ?? null,
                'experience_years' => (int) ($row['experience_years'] ?? 0),
                'is_mandatory' => (bool) ($row['is_mandatory'] ?? true),
            ];
        }

        return $out;
    }
}
