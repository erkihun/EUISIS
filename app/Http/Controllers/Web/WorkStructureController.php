<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TaskQualitySource;
use App\Http\Controllers\Controller;
use App\Models\DailyActivityItem;
use App\Models\PositionService;
use App\Models\PositionServiceSubService;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use App\Services\DailyActivity\TaskStandardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Position Service → Sub-Service → Main Task → Task Standard (BPR plan).
 *
 * Master data for the Employee Daily Plan & Work Execution Register. Every
 * action is authorised against the owning position service, so organization
 * scope applies at every level: a sub-service, task or standard id from
 * another organization is refused, whatever the URL says.
 */
class WorkStructureController extends Controller
{
    public function __construct(
        private readonly TaskStandardService $standards,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function show(Request $request, PositionService $positionService): Response
    {
        $this->authorize('viewStructure', $positionService);
        $positionService->load(['position:id,title_en,title_am', 'organization:id,name_en,name_am']);

        $subServices = PositionServiceSubService::query()
            ->where('position_service_id', $positionService->id)
            ->with(['tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('code'),
                'tasks.standards' => fn ($query) => $query->orderByDesc('version_no')])
            ->orderBy('sort_order')->orderBy('code')
            ->get();

        $user = $request->user();

        return Inertia::render('PositionServices/Structure', [
            'service' => [
                'id' => $positionService->id,
                'service_no' => $positionService->service_no,
                'name_en' => $positionService->name_en,
                'name_am' => $positionService->name_am,
                'position' => $positionService->position?->only(['title_en', 'title_am']),
                'organization' => $positionService->organization?->only(['name_en', 'name_am']),
            ],
            'sub_services' => $subServices->map(fn (PositionServiceSubService $sub): array => [
                'id' => $sub->id,
                'code' => $sub->code,
                'name_en' => $sub->name_en,
                'name_am' => $sub->name_am,
                'description' => $sub->description,
                'is_active' => $sub->is_active,
                'sort_order' => $sub->sort_order,
                'tasks' => $sub->tasks->map(fn (PositionServiceTask $task): array => [
                    'id' => $task->id,
                    'code' => $task->code,
                    'name_en' => $task->name_en,
                    'name_am' => $task->name_am,
                    'description' => $task->description,
                    'is_active' => $task->is_active,
                    'sort_order' => $task->sort_order,
                    'standards' => $task->standards->map(fn (PositionServiceTaskStandard $standard): array => [
                        'id' => $standard->id,
                        'version_no' => $standard->version_no,
                        'status' => $standard->status->value,
                        'standard_measure' => $standard->standard_measure,
                        'bpr_reference' => $standard->bpr_reference,
                        'planned_quantity' => $standard->planned_quantity,
                        'quantity_unit' => $standard->quantity_unit,
                        'planned_time_minutes' => $standard->planned_time_minutes,
                        'planned_quality' => $standard->planned_quality,
                        'quality_unit' => $standard->quality_unit,
                        'quality_measure' => $standard->quality_measure,
                        'quality_source' => $standard->quality_source->value,
                        'effective_from' => $standard->effective_from?->toDateString(),
                        'effective_to' => $standard->effective_to?->toDateString(),
                        'approved_at' => $standard->approved_at?->toIso8601String(),
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'quality_sources' => TaskQualitySource::values(),
            'can' => [
                'manage' => $user->can('manageStructure', $positionService),
                'approve' => $user->can('approveStandards', $positionService),
            ],
        ]);
    }

    // ── Sub-services ────────────────────────────────────────────────────────

    public function storeSubService(Request $request, PositionService $positionService): RedirectResponse
    {
        $this->authorize('manageStructure', $positionService);
        $data = $this->validateNode($request, 'position_service_sub_services', 'position_service_id', $positionService->id);

        $sub = PositionServiceSubService::query()->create([
            ...$data,
            'position_service_id' => $positionService->id,
            'organization_id' => $positionService->organization_id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $this->recordStructure($request, $sub, null);

        return back()->with('success', __('work-standards.saved'));
    }

    public function updateSubService(Request $request, PositionServiceSubService $subService): RedirectResponse
    {
        $this->authorize('manageStructure', $this->owner($subService));
        $data = $this->validateNode($request, 'position_service_sub_services', 'position_service_id', $subService->position_service_id, $subService->id);

        $before = $subService->only(array_keys($data));
        $subService->fill([...$data, 'updated_by' => $request->user()->id])->save();
        $this->recordStructure($request, $subService, $before);

        return back()->with('success', __('work-standards.saved'));
    }

    public function destroySubService(Request $request, PositionServiceSubService $subService): RedirectResponse
    {
        $this->authorize('manageStructure', $this->owner($subService));
        $this->refuseIfUsed('sub_service_id', $subService->id);

        $this->recordStructure($request, $subService, $subService->only(['code', 'name_en']), deleted: true);
        $subService->tasks()->delete();
        $subService->delete();

        return back()->with('success', __('work-standards.deleted'));
    }

    // ── Main tasks ──────────────────────────────────────────────────────────

    public function storeTask(Request $request, PositionServiceSubService $subService): RedirectResponse
    {
        $this->authorize('manageStructure', $this->owner($subService));
        $data = $this->validateNode($request, 'position_service_tasks', 'sub_service_id', $subService->id);

        $task = PositionServiceTask::query()->create([
            ...$data,
            'sub_service_id' => $subService->id,
            'position_service_id' => $subService->position_service_id,
            'organization_id' => $subService->organization_id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $this->recordStructure($request, $task, null);

        return back()->with('success', __('work-standards.saved'));
    }

    public function updateTask(Request $request, PositionServiceTask $task): RedirectResponse
    {
        $this->authorize('manageStructure', $this->owner($task));
        $data = $this->validateNode($request, 'position_service_tasks', 'sub_service_id', $task->sub_service_id, $task->id);

        $before = $task->only(array_keys($data));
        $task->fill([...$data, 'updated_by' => $request->user()->id])->save();
        $this->recordStructure($request, $task, $before);

        return back()->with('success', __('work-standards.saved'));
    }

    public function destroyTask(Request $request, PositionServiceTask $task): RedirectResponse
    {
        $this->authorize('manageStructure', $this->owner($task));
        $this->refuseIfUsed('task_id', $task->id);

        $this->recordStructure($request, $task, $task->only(['code', 'name_en']), deleted: true);
        $task->delete();

        return back()->with('success', __('work-standards.deleted'));
    }

    // ── Standards ───────────────────────────────────────────────────────────

    public function storeStandard(Request $request, PositionServiceTask $task): RedirectResponse
    {
        $this->authorize('manageStructure', $this->owner($task));
        $this->standards->createDraft($request->user(), $task, $this->validateStandard($request));

        return back()->with('success', __('work-standards.draft_saved'));
    }

    public function updateStandard(Request $request, PositionServiceTaskStandard $standard): RedirectResponse
    {
        $this->authorize('manageStructure', $this->owner($standard->task));
        $this->standards->updateDraft($request->user(), $standard, $this->validateStandard($request));

        return back()->with('success', __('work-standards.draft_saved'));
    }

    public function destroyStandard(Request $request, PositionServiceTaskStandard $standard): RedirectResponse
    {
        $this->authorize('manageStructure', $this->owner($standard->task));
        $this->standards->deleteDraft($request->user(), $standard);

        return back()->with('success', __('work-standards.deleted'));
    }

    public function approveStandard(Request $request, PositionServiceTaskStandard $standard): RedirectResponse
    {
        $this->authorize('approveStandards', $this->owner($standard->task));
        $this->standards->approve($request->user(), $standard);

        return back()->with('success', __('work-standards.approved'));
    }

    public function retireStandard(Request $request, PositionServiceTaskStandard $standard): RedirectResponse
    {
        $this->authorize('approveStandards', $this->owner($standard->task));
        $this->standards->retire($request->user(), $standard);

        return back()->with('success', __('work-standards.retired'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** The position service a node belongs to; a removed service ends here. */
    private function owner(PositionServiceSubService|PositionServiceTask|null $node): PositionService
    {
        return $node?->positionService ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function validateNode(Request $request, string $table, string $parentColumn, string $parentId, ?string $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique($table, 'code')
                ->where($parentColumn, $parentId)->whereNull('deleted_at')->ignore($ignoreId)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]) + ['sort_order' => 0];
    }

    /**
     * A standard must measure at least one dimension, with planned values
     * above zero: they are the denominators of the form's formulas. When
     * quality is measured, how it is measured must be written down: the form
     * does not define it, and inventing it is not ours to do.
     *
     * @return array<string, mixed>
     */
    private function validateStandard(Request $request): array
    {
        $data = $request->validate([
            'standard_measure' => ['nullable', 'string', 'max:500'],
            'bpr_reference' => ['nullable', 'string', 'max:255'],
            'planned_quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'quantity_unit' => ['nullable', 'string', 'max:64', 'required_with:planned_quantity'],
            'planned_time_minutes' => ['nullable', 'numeric', 'gt:0', 'max:1440'],
            'planned_quality' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'quality_unit' => ['nullable', 'string', 'max:64', 'required_with:planned_quality'],
            'quality_measure' => ['nullable', 'string', 'max:2000', 'required_with:planned_quality'],
            'quality_source' => ['required', Rule::in(TaskQualitySource::values())],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);

        if (blank($data['planned_quantity'] ?? null) && blank($data['planned_time_minutes'] ?? null) && blank($data['planned_quality'] ?? null)) {
            throw ValidationException::withMessages(['planned_quantity' => __('work-standards.dimension_required')]);
        }

        foreach (['planned_quantity', 'planned_time_minutes', 'planned_quality', 'quantity_unit', 'quality_unit', 'quality_measure', 'standard_measure', 'bpr_reference', 'effective_to'] as $field) {
            $data[$field] = blank($data[$field] ?? null) ? null : $data[$field];
        }

        return $data;
    }

    /** Recorded work keeps its structure: retire it (inactive) instead. */
    private function refuseIfUsed(string $column, string $id): void
    {
        if (DailyActivityItem::query()->where($column, $id)->exists()) {
            throw ValidationException::withMessages(['structure' => __('work-standards.in_use')]);
        }
    }

    /** @param array<string, mixed>|null $before */
    private function recordStructure(Request $request, PositionServiceSubService|PositionServiceTask $node, ?array $before, bool $deleted = false): void
    {
        $this->audit->execute(
            AuditEventType::WorkStructureChanged,
            $request->user(),
            $node,
            $node->organization_id,
            $before,
            $deleted ? ['deleted' => true] : $node->only(['code', 'name_en', 'name_am', 'is_active', 'sort_order']),
            request: $request,
        );
    }
}
