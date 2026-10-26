<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\CodeRules\ArchiveCodeRuleAction;
use App\Actions\CodeRules\CreateCodeRuleAction;
use App\Actions\CodeRules\PreviewCodeRuleAction;
use App\Actions\CodeRules\RestoreCodeRuleAction;
use App\Actions\CodeRules\UpdateCodeRuleAction;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\CodeRuleScopeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewCodeForEntityRequest;
use App\Http\Requests\PreviewCodeRuleRequest;
use App\Http\Requests\StoreCodeRuleRequest;
use App\Http\Requests\UpdateCodeRuleRequest;
use App\Http\Resources\CodeGenerationLogResource;
use App\Http\Resources\CodeRuleResource;
use App\Models\CodeRule;
use App\Models\CodeRuleSequence;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Services\AuditWriter;
use App\Services\CodeGeneration\CodeFormatTokenRegistry;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleResolver;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use App\Services\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The EUISIS Code Rules screens (CodeRules/Index, Create, Show, Edit) over the
 * Core's rules: the same pages, props and routes, for the entity types the
 * Core numbers (CodeRuleTargetRegistry). An active rule drives the Core's
 * CodeGenerator; without one the formats under System Settings apply.
 */
class CodeRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CodeRule::class);
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $paginator = CodeRule::query()
            ->with(['creator:id,name', 'updater:id,name'])
            ->withCount('generationLogs')
            ->when($request->string('search')->toString() !== '', function ($query) use ($request, $like): void {
                $search = $request->string('search')->toString();
                $query->where(function ($nested) use ($search, $like): void {
                    $nested->where('name_en', $like, "%{$search}%")
                        ->orWhere('name_am', $like, "%{$search}%")
                        ->orWhere('prefix', $like, "%{$search}%")
                        ->orWhere('format', $like, "%{$search}%");
                });
            })
            ->when($request->string('entity_type')->toString() !== '', fn ($query) => $query->where('entity_type', $request->string('entity_type')->toString()))
            ->when($request->string('scope_type')->toString() !== '', fn ($query) => $query->where('scope_type', $request->string('scope_type')->toString()))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->string('reset_frequency')->toString() !== '', fn ($query) => $query->where('reset_frequency', $request->string('reset_frequency')->toString()))
            ->orderBy('entity_type')
            ->orderBy('name_en')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('CodeRules/Index', [
            'codeRules' => [
                'data' => CodeRuleResource::collection($paginator->getCollection())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
            'summary' => [
                'total' => CodeRule::query()->count(),
                'active' => CodeRule::query()->where('is_active', true)->count(),
                'scoped' => CodeRule::query()->whereNotNull('scope_type')->count(),
                'entity_types' => CodeRule::query()->distinct()->count('entity_type'),
            ],
            'filters' => $request->only(['search', 'entity_type', 'scope_type', 'is_active', 'reset_frequency']),
            'options' => $this->formOptions(),
            'can' => [
                'create' => $request->user()?->can('create', CodeRule::class) ?? false,
                'preview' => $request->user()?->can('preview', CodeRule::class) ?? false,
            ],
        ]);
    }

    public function create(Request $request, CodeFormatTokenRegistry $tokenRegistry): Response
    {
        $this->authorize('create', CodeRule::class);

        return Inertia::render('CodeRules/Create', [
            'options' => $this->formOptions(),
            'available_tokens' => $tokenRegistry->forFrontend(activeOnly: true),
            'can' => ['preview' => $request->user()?->can('preview', CodeRule::class) ?? false],
        ]);
    }

    public function store(StoreCodeRuleRequest $request, CreateCodeRuleAction $action): RedirectResponse
    {
        $codeRule = $action->execute($request->validated(), $request->user());

        return to_route('code-rules.show', $codeRule)->with('flash', ['message' => __('code-rules.created'), 'type' => 'success']);
    }

    public function show(Request $request, CodeRule $codeRule): Response
    {
        $this->authorize('view', $codeRule);
        $codeRule->load(['creator:id,name', 'updater:id,name']);
        $codeRule->loadCount('generationLogs');
        $canViewSequences = $request->user()?->can('code-rules.viewSequences') ?? false;

        return Inertia::render('CodeRules/Show', [
            'codeRule' => (new CodeRuleResource($codeRule))->resolve(),
            'generationLogs' => CodeGenerationLogResource::collection(
                $codeRule->generationLogs()->latest('generated_at')->with('generator:id,name')->limit(20)->get()
            )->resolve(),
            'sequences' => $canViewSequences ? $this->sequenceRows($codeRule) : [],
            'can' => [
                'preview' => $request->user()?->can('preview', CodeRule::class) ?? false,
                'viewSequences' => $canViewSequences,
                'resetSequence' => $request->user()?->can('code-rules.resetSequence') ?? false,
            ],
        ]);
    }

    public function sequences(Request $request, CodeRule $codeRule): JsonResponse
    {
        $this->authorize('view', $codeRule);
        abort_unless($request->user()?->can('code-rules.viewSequences') ?? false, 403);

        return response()->json(['sequences' => $this->sequenceRows($codeRule)]);
    }

    public function resetSequence(Request $request, CodeRule $codeRule, CodeRuleSequence $sequence, AuditWriter $audit): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $codeRule);
        abort_unless($request->user()?->can('code-rules.resetSequence') ?? false, 403);
        abort_unless($sequence->code_rule_id === $codeRule->getKey(), 404);

        $oldNextNumber = $sequence->next_number;
        $resetNumber = max(1, $codeRule->initial_sequence_number ?? 1);
        $sequence->forceFill(['next_number' => $resetNumber, 'last_number' => null, 'last_generated_code' => null, 'last_reset_at' => now()])->save();
        $audit->writeFor('code_rule.sequence_reset', $request->user(), 'CodeRule', (string) $codeRule->getKey(), null,
            ['next_number' => $oldNextNumber], ['next_number' => $resetNumber, 'scope_key' => $sequence->sequence_scope_key]);

        if ($request->header('X-Inertia')) {
            return back()->with('flash', ['message' => __('code-rules.sequence_reset_success'), 'type' => 'success']);
        }

        return response()->json(['message' => __('code-rules.sequence_reset_success')]);
    }

    public function edit(Request $request, CodeRule $codeRule, CodeFormatTokenRegistry $tokenRegistry): Response
    {
        $this->authorize('update', $codeRule);
        $codeRule->load(['creator:id,name', 'updater:id,name']);

        return Inertia::render('CodeRules/Edit', [
            'codeRule' => (new CodeRuleResource($codeRule))->resolve(),
            'options' => $this->formOptions(),
            'available_tokens' => $tokenRegistry->forFrontend(activeOnly: true),
            'can' => ['preview' => $request->user()?->can('preview', CodeRule::class) ?? false],
        ]);
    }

    public function update(UpdateCodeRuleRequest $request, CodeRule $codeRule, UpdateCodeRuleAction $action): RedirectResponse
    {
        $action->execute($codeRule, $request->validated(), $request->user());

        return to_route('code-rules.show', $codeRule)->with('flash', ['message' => __('code-rules.updated'), 'type' => 'success']);
    }

    public function archive(Request $request, CodeRule $codeRule, ArchiveCodeRuleAction $action): RedirectResponse
    {
        $this->authorize('archive', $codeRule);
        $action->execute($codeRule, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('code-rules.index')->with('flash', ['message' => __('recycle-bin.archived_successfully'), 'type' => 'success']);
    }

    public function restore(Request $request, string $codeRule, RestoreCodeRuleAction $action): RedirectResponse
    {
        $codeRule = CodeRule::query()->withTrashed()->findOrFail($codeRule);
        $this->authorize('restore', $codeRule);
        $action->execute($codeRule, $request->user(), $request);

        return back()->with('flash', ['message' => __('recycle-bin.restored_successfully'), 'type' => 'success']);
    }

    /** The form's live preview of the rule being written. */
    public function preview(PreviewCodeRuleRequest $request, PreviewCodeRuleAction $action): JsonResponse
    {
        $validated = $request->validated();
        $context = array_filter([
            'organization_id' => $validated['organization_id'] ?? null,
            'organization_type_id' => $validated['organization_type_id'] ?? null,
            'parent_organization_id' => $validated['parent_organization_id'] ?? null,
            'organization_unit_id' => $validated['organization_unit_id'] ?? null,
            'organization_unit_type_id' => $validated['organization_unit_type_id'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
            'position_id' => $validated['position_id'] ?? null,
            'request_type' => $validated['request_type'] ?? null,
            'custom' => $validated['custom'] ?? null,
            'custom_1' => $validated['custom_1'] ?? null,
            'custom_2' => $validated['custom_2'] ?? null,
            'custom_3' => $validated['custom_3'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return response()->json(['preview' => $action->execute($validated, $context)]);
    }

    /** The code a new record of a kind would get (the create forms' preview); the sequence is not advanced. */
    public function previewCode(PreviewCodeForEntityRequest $request, CodeRuleResolver $resolver, CodeGeneratorService $generator, CodeGenerator $settingsGenerator): JsonResponse
    {
        $entityType = $request->validated('entity_type');
        $context = array_merge((array) ($request->validated('context') ?? []), array_filter([
            'scope_type' => $request->validated('scope_type'),
            'scope_id' => $request->validated('scope_id'),
        ]));
        $rule = $resolver->resolve($entityType, $context);
        if ($rule === null) {
            // Without a rule the Core numbers from System Settings > Numbering; the form still shows the code.
            $kind = match ($entityType) {
                'organization' => 'organization', 'organization_unit' => 'unit', 'position', 'employee_position' => 'position', 'employee' => 'employee', 'id_card' => 'card', default => null
            };
            $code = $kind === null ? null : $settingsGenerator->preview($kind, $context);

            return response()->json([
                'code' => $code,
                'rule' => $code === null ? null : ['id' => $entityType, 'name' => __('Core numbering'), 'entity_type' => $entityType, 'is_scoped' => false, 'scope_type' => null],
                'manual_override_allowed' => true,
                'requires_override_permission' => false,
                'error' => $code === null ? __('code-rules.no_active_rule') : null,
            ]);
        }
        $rulePayload = [
            'id' => $rule->id,
            'name' => app()->getLocale() === 'am' ? ($rule->name_am ?? $rule->name_en) : $rule->name_en,
            'entity_type' => $rule->entity_type instanceof CodeRuleEntityType ? $rule->entity_type->value : $rule->entity_type,
            'is_scoped' => $rule->scope_type !== null,
            'scope_type' => $rule->scope_type,
        ];
        if ($request->user()?->can('code-rules.view')) {
            $rulePayload['next_number'] = $rule->next_number;
        }

        return response()->json([
            'code' => $generator->preview($rule, $context),
            'rule' => $rulePayload,
            'manual_override_allowed' => $rule->allow_manual_override,
            'requires_override_permission' => $rule->require_approval_for_override,
            'error' => null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function sequenceRows(CodeRule $codeRule): array
    {
        return $codeRule->sequences()->orderByDesc('updated_at')->get()->map(fn (CodeRuleSequence $seq): array => [
            'id' => $seq->id,
            'scope_key' => $seq->sequence_scope_key,
            'scope_values' => $seq->sequence_scope_values ?? [],
            'next_number' => $seq->next_number,
            'last_number' => $seq->last_number,
            'last_generated_code' => $seq->last_generated_code,
            'updated_at' => $seq->updated_at?->toIso8601String(),
        ])->all();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        $option = static fn (string $prefix) => static fn (\BackedEnum $case): array => ['value' => $case->value, 'label_key' => $prefix.$case->value];

        return [
            // The entity types the Core numbers; the scope types the Core has (no service types).
            'entity_types' => array_map($option('codeRules.entityTypes.'), CodeRuleTargetRegistry::entityTypes()),
            'scope_types' => array_map($option('codeRules.scopeTypes.'), array_values(array_filter(CodeRuleScopeType::cases(), fn (CodeRuleScopeType $type): bool => $type !== CodeRuleScopeType::ServiceType))),
            'reset_frequencies' => array_map($option('codeRules.resetFrequencies.'), CodeRuleResetFrequency::cases()),
            'sequence_scope_strategies' => array_map($option('codeRules.scopeStrategies.'), CodeRuleScopeStrategy::cases()),
            'scope_options' => [
                CodeRuleScopeType::Organization->value => Organization::query()->orderBy('name_en')->get(['id', 'name_en', 'code'])
                    ->map(fn (Organization $organization): array => ['id' => $organization->id, 'label' => "{$organization->code} - {$organization->name_en}"]),
                CodeRuleScopeType::OrganizationType->value => OrganizationType::query()->orderBy('name_en')->get(['id', 'name_en', 'code'])
                    ->map(fn (OrganizationType $type): array => ['id' => $type->id, 'label' => "{$type->code} - {$type->name_en}"]),
                CodeRuleScopeType::OrganizationUnitType->value => OrganizationUnitType::query()->orderBy('name_en')->get(['id', 'name_en', 'code'])
                    ->map(fn (OrganizationUnitType $type): array => ['id' => $type->id, 'label' => trim(($type->code ?? '').' - '.$type->name_en, ' -')]),
            ],
            'year_formats' => [
                ['value' => 'Y', 'label' => now()->format('Y')],
                ['value' => 'y', 'label' => now()->format('y')],
            ],
        ];
    }
}
