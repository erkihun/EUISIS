<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Enums\OrganizationDesignStatus;
use App\Models\OrganizationFunction;
use App\Models\OrganizationUnit;
use App\Models\Process;
use App\Models\ResponsibilityType;
use App\Models\Service;
use App\Models\UnitDesignProposal;
use App\Models\UnitResponsibility;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Responsibility mapping (brief §37–39): which unit — or proposed unit —
 * holds which responsibility for a function, service, process or activity,
 * and which objects nobody covers.
 */
class ResponsibilityService
{
    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    /**
     * Replace a subject's responsibilities.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function sync(User $actor, OrganizationUnit|UnitDesignProposal $subject, array $items): void
    {
        if ($subject instanceof UnitDesignProposal && ! $subject->study->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.study_not_draft')]);
        }
        $organizationId = $subject->organization_id;
        $column = $subject instanceof OrganizationUnit ? 'organization_unit_id' : 'unit_design_proposal_id';
        $types = ResponsibilityType::query()->where('is_active', true)->get()->keyBy('id');

        $rows = [];
        $seen = [];
        foreach (array_values($items) as $i => $item) {
            $objectType = $item['object_type'] ?? '';
            $model = UnitResponsibility::OBJECT_MODELS[$objectType] ?? null;
            if ($model === null) {
                throw ValidationException::withMessages(["items.{$i}.object_type" => __('organization-design.responsibility_object_type_invalid')]);
            }
            if (! $model::query()->whereKey($item['object_id'] ?? null)->where('organization_id', $organizationId)->exists()) {
                throw ValidationException::withMessages(["items.{$i}.object_id" => __('organization-design.responsibility_object_foreign')]);
            }
            $type = $types->get($item['responsibility_type_id'] ?? null);
            if ($type === null) {
                throw ValidationException::withMessages(["items.{$i}.responsibility_type_id" => __('organization-design.responsibility_type_invalid')]);
            }
            $key = $objectType.'|'.$item['object_id'].'|'.$type->getKey();
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["items.{$i}.responsibility_type_id" => __('organization-design.responsibility_duplicate')]);
            }
            $seen[$key] = true;
            if ($type->is_exclusive) {
                $held = UnitResponsibility::query()->where('organization_id', $organizationId)->where('object_type', $objectType)->where('object_id', $item['object_id'])
                    ->where('responsibility_type_id', $type->getKey())->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<>', $subject->getKey()))->exists();
                if ($held) {
                    throw ValidationException::withMessages(["items.{$i}.responsibility_type_id" => __('organization-design.responsibility_exclusive_taken', ['type' => $type->name_en])]);
                }
            }
            $rows[] = ['object_type' => $objectType, 'object_id' => $item['object_id'], 'responsibility_type_id' => $type->getKey(), 'notes' => $item['notes'] ?? null, 'sequence' => $i];
        }

        DB::transaction(function () use ($actor, $subject, $column, $organizationId, $rows): void {
            UnitResponsibility::query()->where($column, $subject->getKey())->delete();
            foreach ($rows as $row) {
                UnitResponsibility::query()->create([...$row, 'organization_id' => $organizationId, $column => $subject->getKey(), 'created_by' => $actor->getKey()]);
            }
            $this->audit->record(AuditEventType::ResponsibilitiesSynced, $actor, $subject, ['rows' => count($rows)]);
        });
    }

    /**
     * Every active unit of the organization with its responsibilities.
     *
     * @return list<array<string, mixed>>
     */
    public function matrix(string $organizationId): array
    {
        $labels = $this->objectLabels($organizationId);
        $rows = UnitResponsibility::query()->where('organization_id', $organizationId)->whereNotNull('organization_unit_id')->with('type:id,code,name_en,name_am,is_exclusive,covers_object')->orderBy('sequence')->get()->groupBy('organization_unit_id');
        $units = OrganizationUnit::query()->where('organization_id', $organizationId)->whereIn('status', ['active', 'draft'])->orderBy('code')->get(['id', 'code', 'name_en', 'name_am', 'parent_unit_id', 'unit_type']);

        return $units->map(fn (OrganizationUnit $unit) => [
            'unit' => $unit->only(['id', 'code', 'name_en', 'name_am', 'parent_unit_id', 'unit_type']),
            'rows' => self::rows($rows->get($unit->id, collect()), $labels),
        ])->values()->all();
    }

    /**
     * Functions, services and processes no unit covers (a responsibility
     * type with covers_object), by object kind.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function gaps(string $organizationId): array
    {
        $coveringTypes = ResponsibilityType::query()->where('covers_object', true)->pluck('id')->all();
        $covered = UnitResponsibility::query()->where('organization_id', $organizationId)->whereNotNull('organization_unit_id')->whereIn('responsibility_type_id', $coveringTypes)
            ->get(['object_type', 'object_id'])->groupBy('object_type')->map(fn ($g) => $g->pluck('object_id')->flip());

        $functions = OrganizationFunction::query()->where('organization_id', $organizationId)->orderBy('path')->get(['id', 'code', 'title_en', 'title_am']);
        $services = Service::query()->where('organization_id', $organizationId)->where('status', OrganizationDesignStatus::Published)->orderBy('code')->get(['id', 'code', 'name_en', 'name_am']);
        $processes = Process::query()->where('organization_id', $organizationId)->where('status', OrganizationDesignStatus::Published)->orderBy('code')->get(['id', 'code', 'name_en', 'name_am']);

        return [
            'functions' => $functions->reject(fn ($f) => ($covered->get('function') ?? collect())->has($f->id))->map(fn ($f) => ['id' => $f->id, 'code' => $f->code, 'name_en' => $f->title_en, 'name_am' => $f->title_am])->values()->all(),
            'services' => $services->reject(fn ($s) => ($covered->get('service') ?? collect())->has($s->id))->map->only(['id', 'code', 'name_en', 'name_am'])->values()->all(),
            'processes' => $processes->reject(fn ($p) => ($covered->get('process') ?? collect())->has($p->id))->map->only(['id', 'code', 'name_en', 'name_am'])->values()->all(),
        ];
    }

    /**
     * Everything a unit may be responsible for in this organization, for the editor.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function objects(string $organizationId): array
    {
        return [
            'function' => OrganizationFunction::query()->where('organization_id', $organizationId)->orderBy('path')->get(['id', 'code', 'title_en', 'title_am', 'depth'])->map(fn ($f) => ['id' => $f->id, 'code' => $f->code, 'name_en' => $f->title_en, 'name_am' => $f->title_am, 'depth' => $f->depth])->values()->all(),
            'service' => Service::query()->where('organization_id', $organizationId)->whereIn('status', [OrganizationDesignStatus::Published, OrganizationDesignStatus::Draft])->orderBy('code')->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->values()->all(),
            'process' => Process::query()->where('organization_id', $organizationId)->whereIn('status', [OrganizationDesignStatus::Published, OrganizationDesignStatus::Draft])->orderBy('code')->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->values()->all(),
            'activity' => \App\Models\ProcessActivity::query()->where('organization_id', $organizationId)->orderBy('code')->limit(2000)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->values()->all(),
        ];
    }

    /**
     * Rows of one subject for the page, with object labels resolved.
     *
     * @param  Collection<int, UnitResponsibility>  $rows
     * @param  array<string, array<string, array<string, mixed>>>|null  $labels
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $rows, ?array $labels = null): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $labels ??= (new self(app(OrganizationDesignAudit::class)))->objectLabels($rows->first()->organization_id);

        return $rows->map(fn (UnitResponsibility $r) => [
            'id' => $r->id, 'object_type' => $r->object_type, 'object_id' => $r->object_id, 'responsibility_type_id' => $r->responsibility_type_id, 'notes' => $r->notes,
            'object' => $labels[$r->object_type][$r->object_id] ?? null,
            'type' => $r->type?->only(['id', 'code', 'name_en', 'name_am', 'is_exclusive', 'covers_object']),
        ])->values()->all();
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function objectLabels(string $organizationId): array
    {
        $out = [];
        foreach ($this->objects($organizationId) as $type => $list) {
            foreach ($list as $row) {
                $out[$type][$row['id']] = $row;
            }
        }

        return $out;
    }
}
