<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Models\Competency;
use App\Models\CompetencyType;
use App\Models\IscoGroup;
use App\Models\Occupation;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionServiceTask;
use App\Models\PositionWorkLink;
use App\Models\ProcessTask;
use App\Models\UnitResponsibility;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The mapping workspace (brief §116–118, D-11): records that pre-date the
 * design and are not linked to it are listed as UNMAPPED_EXISTING_RECORD
 * with suggestions; a suggestion is applied only by a person, and only
 * when it is unambiguous. Anything with several candidates, or a candidate
 * matched by name alone, is NEEDS_DATA_REVIEW and is never mapped by the
 * system. Existing records are never altered beyond the link itself.
 */
class MappingWorkspaceService
{
    public const UNMAPPED = 'UNMAPPED_EXISTING_RECORD';

    public const REVIEW = 'NEEDS_DATA_REVIEW';

    public function __construct(private readonly OrganizationDesignAudit $audit, private readonly ProcessArchitectureService $processes) {}

    /**
     * Unmapped records of one organization.
     *
     * @return array{position_tasks: list<array<string, mixed>>, positions: list<array<string, mixed>>, units: list<array<string, mixed>>}
     */
    public function unmapped(string $organizationId): array
    {
        $masters = $this->masterIndex($organizationId);
        $positionTasks = PositionServiceTask::query()->where('organization_id', $organizationId)->whereNull('master_task_id')->where('is_active', true)
            ->with(['positionService:id,position_id,service_no,name_en,name_am', 'positionService.position:id,job_position_code,title_en,title_am'])
            ->orderBy('code')->limit(2000)->get()
            ->map(function (PositionServiceTask $task) use ($masters): array {
                $suggestions = $this->suggestionsFor($task, $masters);

                return [
                    'id' => $task->id, 'code' => $task->code, 'name_en' => $task->name_en, 'name_am' => $task->name_am,
                    'service' => $task->positionService ? ['id' => $task->positionService->id, 'service_no' => $task->positionService->service_no, 'name_en' => $task->positionService->name_en, 'name_am' => $task->positionService->name_am] : null,
                    'position' => $task->positionService?->position ? ['id' => $task->positionService->position->id, 'code' => $task->positionService->position->job_position_code, 'title_en' => $task->positionService->position->title_en, 'title_am' => $task->positionService->position->title_am] : null,
                    'suggestions' => $suggestions,
                    'needs_review' => $this->needsReview($suggestions),
                    'flag' => $this->needsReview($suggestions) ? self::REVIEW : self::UNMAPPED,
                ];
            })->values()->all();

        $linked = PositionWorkLink::query()->where('organization_id', $organizationId)->distinct()->pluck('position_id')->flip();
        $positions = Position::query()->where('organization_id', $organizationId)->where('is_active', true)->with('organizationUnit:id,code,name_en,name_am')->orderBy('job_position_code')->limit(2000)->get()
            ->reject(fn (Position $p) => $linked->has($p->id))
            ->map(fn (Position $p) => ['id' => $p->id, 'code' => $p->job_position_code, 'title_en' => $p->title_en, 'title_am' => $p->title_am, 'unit' => $p->organizationUnit?->only(['id', 'code', 'name_en', 'name_am']), 'flag' => self::UNMAPPED])->values()->all();

        $responsible = UnitResponsibility::query()->where('organization_id', $organizationId)->whereNotNull('organization_unit_id')->distinct()->pluck('organization_unit_id')->flip();
        $units = OrganizationUnit::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('code')->limit(2000)->get(['id', 'code', 'name_en', 'name_am'])
            ->reject(fn (OrganizationUnit $u) => $responsible->has($u->id))
            ->map(fn (OrganizationUnit $u) => ['id' => $u->id, 'code' => $u->code, 'name_en' => $u->name_en, 'name_am' => $u->name_am, 'flag' => self::UNMAPPED])->values()->all();

        return ['position_tasks' => $positionTasks, 'positions' => $positions, 'units' => $units];
    }

    /**
     * City-level catalog records without their reference link.
     *
     * @return array{occupations: list<array<string, mixed>>, competencies: list<array<string, mixed>>}
     */
    public function unmappedCatalog(): array
    {
        $groups = IscoGroup::query()->where('is_active', true)->orderBy('revision')->get(['id', 'revision', 'code', 'name_en', 'name_am'])->groupBy('code');
        $occupations = Occupation::query()->whereNull('isco_group_id')->where('is_active', true)->orderBy('code')->limit(2000)->get(['id', 'code', 'isco_code', 'name_en', 'name_am'])
            ->map(function (Occupation $o) use ($groups): array {
                $suggestions = ($groups->get((string) $o->isco_code) ?? collect())->map->only(['id', 'revision', 'code', 'name_en', 'name_am'])->values()->all();

                return ['id' => $o->id, 'code' => $o->code, 'isco_code' => $o->isco_code, 'name_en' => $o->name_en, 'name_am' => $o->name_am, 'suggestions' => $suggestions, 'needs_review' => count($suggestions) > 1, 'flag' => count($suggestions) > 1 ? self::REVIEW : self::UNMAPPED];
            })->values()->all();
        $competencies = Competency::query()->whereNull('competency_type_id')->where('is_active', true)->with('framework:id,code')->orderBy('code')->limit(2000)->get()
            ->map(fn (Competency $c) => ['id' => $c->id, 'code' => $c->code, 'name_en' => $c->name_en, 'name_am' => $c->name_am, 'framework_code' => $c->framework?->code, 'flag' => self::UNMAPPED])->values()->all();

        return ['occupations' => $occupations, 'competencies' => $competencies];
    }

    /**
     * Map every legacy position task that has exactly one exact-code
     * candidate. Everything else is left for a person.
     *
     * @return array{mapped: int, review: int, none: int}
     */
    public function applySuggestions(User $actor, string $organizationId): array
    {
        $masters = $this->masterIndex($organizationId);
        $counts = ['mapped' => 0, 'review' => 0, 'none' => 0];
        foreach (PositionServiceTask::query()->where('organization_id', $organizationId)->whereNull('master_task_id')->where('is_active', true)->orderBy('code')->cursor() as $task) {
            $suggestions = $this->suggestionsFor($task, $masters);
            if ($suggestions === []) {
                $counts['none']++;
            } elseif ($this->needsReview($suggestions)) {
                $counts['review']++;
            } else {
                $this->processes->linkPositionTask($actor, $task, $suggestions[0]['id']);
                $counts['mapped']++;
            }
        }
        $this->audit->record(AuditEventType::MappingSuggestionsApplied, $actor, $actor, ['organization_id' => $organizationId, ...$counts]);

        return $counts;
    }

    public function linkOccupation(User $actor, Occupation $occupation, ?string $groupId): Occupation
    {
        $group = $groupId === null ? null : IscoGroup::query()->whereKey($groupId)->where('is_active', true)->first();
        if ($groupId !== null && $group === null) {
            throw ValidationException::withMessages(['isco_group_id' => __('organization-design.isco_group_invalid')]);
        }
        $old = $occupation->isco_group_id;
        $occupation->forceFill(['isco_group_id' => $group?->getKey()])->save();
        $this->audit->record(AuditEventType::OccupationLinked, $actor, $occupation, ['isco_group_id' => $group?->getKey()], ['isco_group_id' => $old]);

        return $occupation;
    }

    public function linkCompetency(User $actor, Competency $competency, ?string $typeId): Competency
    {
        $type = $typeId === null ? null : CompetencyType::query()->whereKey($typeId)->where('is_active', true)->first();
        if ($typeId !== null && $type === null) {
            throw ValidationException::withMessages(['competency_type_id' => __('organization-design.competency_type_invalid')]);
        }
        $old = $competency->competency_type_id;
        $competency->forceFill(['competency_type_id' => $type?->getKey()])->save();
        $this->audit->record(AuditEventType::CompetencyLinked, $actor, $competency, ['competency_type_id' => $type?->getKey()], ['competency_type_id' => $old]);

        return $competency;
    }

    /**
     * Candidate master tasks for a legacy task: the same code, else the same name.
     *
     * @param  array{by_code: Collection<string, Collection<int, ProcessTask>>, by_name: Collection<string, Collection<int, ProcessTask>>}  $masters
     * @return list<array{id: string, code: string, name_en: string|null, name_am: string|null, process_code: string|null, match: string}>
     */
    public function suggestionsFor(PositionServiceTask $task, array $masters): array
    {
        $byCode = $masters['by_code']->get(mb_strtolower(trim((string) $task->code))) ?? collect();
        $candidates = $byCode->isNotEmpty() ? $byCode->map(fn ($m) => [$m, 'code']) : ($masters['by_name']->get(mb_strtolower(trim((string) $task->name_en))) ?? collect())->map(fn ($m) => [$m, 'name']);

        return $candidates->map(fn ($pair) => ['id' => $pair[0]->id, 'code' => $pair[0]->code, 'name_en' => $pair[0]->name_en, 'name_am' => $pair[0]->name_am, 'process_code' => $pair[0]->process?->code, 'match' => $pair[1]])->values()->all();
    }

    /** @param list<array{match: string}> $suggestions */
    public function needsReview(array $suggestions): bool
    {
        return $suggestions !== [] && ! (count($suggestions) === 1 && $suggestions[0]['match'] === 'code');
    }

    /** @return array{by_code: Collection<string, Collection<int, ProcessTask>>, by_name: Collection<string, Collection<int, ProcessTask>>} */
    public function masterIndex(string $organizationId): array
    {
        $masters = ProcessTask::query()->where('organization_id', $organizationId)->where('is_active', true)->with('process:id,code')->orderBy('code')->limit(5000)->get();

        return [
            'by_code' => $masters->groupBy(fn (ProcessTask $t) => mb_strtolower(trim((string) $t->code))),
            'by_name' => $masters->groupBy(fn (ProcessTask $t) => mb_strtolower(trim((string) $t->name_en))),
        ];
    }
}
