<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\ArchiveCodeRuleAction;
use App\Actions\CodeRules\CreateCodeRuleAction;
use App\Actions\CodeRules\PreviewCodeRuleAction;
use App\Actions\CodeRules\RestoreCodeRuleAction;
use App\Actions\CodeRules\UpdateCodeRuleAction;
use App\Enums\AuditEventType;
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
use App\Models\ServiceType;
use App\Services\CodeGeneration\CodeFormatTokenRegistry;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CodeRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CodeRule::class);

        $paginator = CodeRule::query()
            ->with(['creator:id,name', 'updater:id,name'])
            ->withCount('generationLogs')
            ->when($request->string('search')->toString() !== '', function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_am', ci_like_operator(), "%{$search}%")
                        ->orWhere('prefix', ci_like_operator(), "%{$search}%")
                        ->orWhere('format', ci_like_operator(), "%{$search}%");
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
                ],
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
            'can' => [
                'preview' => $request->user()?->can('preview', CodeRule::class) ?? false,
            ],
        ]);
    }

    public function store(StoreCodeRuleRequest $request, CreateCodeRuleAction $action): RedirectResponse
    {
        $codeRule = $action->execute($request->validated(), $request->user());

        return to_route('code-rules.show', $codeRule)
            ->with('flash', ['message' => __('code-rules.created'), 'type' => 'success']);
    }

    public function show(Request $request, CodeRule $codeRule): Response
    {
        $this->authorize('view', $codeRule);

        $codeRule->load(['creator:id,name', 'updater:id,name']);
        $codeRule->loadCount('generationLogs');

        $canViewSequences = $request->user()?->can('code-rules.viewSequences') ?? false;

        $sequences = $canViewSequences
            ? $codeRule->sequences()
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (CodeRuleSequence $seq): array => [
                    'id' => $seq->id,
                    'scope_key' => $seq->sequence_scope_key,
                    'scope_values' => $seq->sequence_scope_values ?? [],
                    'next_number' => $seq->next_number,
                    'last_number' => $seq->last_number,
                    'last_generated_code' => $seq->last_generated_code,
                    'updated_at' => $seq->updated_at?->toIso8601String(),
                ])
                ->all()
            : [];

        return Inertia::render('CodeRules/Show', [
            'codeRule' => (new CodeRuleResource($codeRule))->resolve(),
            'generationLogs' => CodeGenerationLogResource::collection(
                $codeRule->generationLogs()->latest('generated_at')->with('generator:id,name')->limit(20)->get()
            )->resolve(),
            'sequences' => $sequences,
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

        if (! ($request->user()?->can('code-rules.viewSequences') ?? false)) {
            abort(403);
        }

        $sequences = $codeRule->sequences()
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (CodeRuleSequence $seq): array => [
                'id' => $seq->id,
                'scope_key' => $seq->sequence_scope_key,
                'scope_values' => $seq->sequence_scope_values ?? [],
                'next_number' => $seq->next_number,
                'last_number' => $seq->last_number,
                'last_generated_code' => $seq->last_generated_code,
                'updated_at' => $seq->updated_at?->toIso8601String(),
            ]);

        return response()->json(['sequences' => $sequences]);
    }

    public function resetSequence(
        Request $request,
        CodeRule $codeRule,
        CodeRuleSequence $sequence,
        WriteAuditLogAction $writeAuditLogAction,
    ): JsonResponse|RedirectResponse {
        $this->authorize('update', $codeRule);

        if (! ($request->user()?->can('code-rules.resetSequence') ?? false)) {
            abort(403);
        }

        if ($sequence->code_rule_id !== $codeRule->getKey()) {
            abort(404);
        }

        $oldNextNumber = $sequence->next_number;

        $sequence->forceFill([
            'next_number' => 1,
            'last_number' => null,
            'last_generated_code' => null,
            'last_reset_at' => now(),
        ])->save();

        $writeAuditLogAction->execute(
            AuditEventType::CodeRuleSequenceReset,
            $request->user(),
            $codeRule,
            null,
            ['next_number' => $oldNextNumber],
            ['next_number' => 1, 'scope_key' => $sequence->sequence_scope_key],
        );

        if ($request->header('X-Inertia')) {
            return back()->with('flash', [
                'message' => __('code-rules.sequence_reset_success'),
                'type' => 'success',
            ]);
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
            'can' => [
                'preview' => $request->user()?->can('preview', CodeRule::class) ?? false,
            ],
        ]);
    }

    public function update(UpdateCodeRuleRequest $request, CodeRule $codeRule, UpdateCodeRuleAction $action): RedirectResponse
    {
        $action->execute($codeRule, $request->validated(), $request->user());

        return to_route('code-rules.show', $codeRule)
            ->with('flash', ['message' => __('code-rules.updated'), 'type' => 'success']);
    }

    public function archive(Request $request, CodeRule $codeRule, ArchiveCodeRuleAction $action): RedirectResponse
    {
        $this->authorize('archive', $codeRule);

        $action->execute($codeRule, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('code-rules.index')->with('flash', ['message' => __('recycle-bin.deleted_successfully'), 'type' => 'success']);
    }

    public function restore(Request $request, string $codeRule, RestoreCodeRuleAction $action): RedirectResponse
    {
        $codeRule = CodeRule::query()->withTrashed()->findOrFail($codeRule);

        $this->authorize('restore', $codeRule);

        $action->execute($codeRule, $request->user(), $request);

        return back()->with('flash', ['message' => __('recycle-bin.restored_successfully'), 'type' => 'success']);
    }

    public function preview(
        PreviewCodeRuleRequest $request,
        PreviewCodeRuleAction $action,
    ): JsonResponse {
        $validated = $request->validated();
        $context = array_filter([
            'organization_id' => $validated['organization_id'] ?? null,
            'organization_type_id' => $validated['organization_type_id'] ?? null,
            'parent_organization_id' => $validated['parent_organization_id'] ?? null,
            'organization_unit_id' => $validated['organization_unit_id'] ?? null,
            'organization_unit_type_id' => $validated['organization_unit_type_id'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
            'position_id' => $validated['position_id'] ?? null,
            'service_type_id' => $validated['service_type_id'] ?? null,
            'service_provider_id' => $validated['service_provider_id'] ?? null,
            'request_type' => $validated['request_type'] ?? null,
            'workflow_code' => $validated['workflow_code'] ?? null,
            'approval_step_code' => $validated['approval_step_code'] ?? null,
            'document_type_code' => $validated['document_type_code'] ?? null,
            'custom' => $validated['custom'] ?? null,
            'custom_1' => $validated['custom_1'] ?? null,
            'custom_2' => $validated['custom_2'] ?? null,
            'custom_3' => $validated['custom_3'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return response()->json([
            'preview' => $action->execute($validated, $context),
        ]);
    }

    /**
     * Preview code for a given entity type in create/edit forms.
     * Does NOT increment the sequence counter.
     * Does NOT expose next_number unless user has code-rules.view.
     */
    public function previewCode(
        PreviewCodeForEntityRequest $request,
        CodeRuleResolver $resolver,
        CodeGeneratorService $generator,
    ): JsonResponse {
        $entityType = $request->validated('entity_type');
        $context = array_merge(
            (array) ($request->validated('context') ?? []),
            array_filter([
                'scope_type' => $request->validated('scope_type'),
                'scope_id' => $request->validated('scope_id'),
            ]),
        );

        $rule = $resolver->resolve($entityType, $context);

        if ($rule === null) {
            return response()->json([
                'code' => null,
                'rule' => null,
                'manual_override_allowed' => true,
                'requires_override_permission' => false,
                'error' => __('code-rules.no_active_rule'),
            ], 200);
        }

        $previewCode = $generator->preview($rule, $context);

        $rulePayload = [
            'id' => $rule->id,
            'name' => app()->getLocale() === 'am' ? ($rule->name_am ?? $rule->name_en) : $rule->name_en,
            'entity_type' => $rule->entity_type instanceof CodeRuleEntityType
                ? $rule->entity_type->value
                : $rule->entity_type,
            'is_scoped' => $rule->scope_type !== null,
            'scope_type' => $rule->scope_type,
        ];

        // Only expose next_number to users with code-rules.view permission
        if ($request->user()?->can('code-rules.view')) {
            $rulePayload['next_number'] = $rule->next_number;
        }

        return response()->json([
            'code' => $previewCode,
            'rule' => $rulePayload,
            'manual_override_allowed' => $rule->allow_manual_override,
            'requires_override_permission' => $rule->require_approval_for_override,
            'error' => null,
        ]);
    }

    private function formOptions(): array
    {
        return [
            'entity_types' => array_map(
                static fn (CodeRuleEntityType $entityType): array => ['value' => $entityType->value, 'label_key' => 'codeRules.entityTypes.'.$entityType->value],
                CodeRuleEntityType::cases(),
            ),
            'scope_types' => array_map(
                static fn (CodeRuleScopeType $scopeType): array => ['value' => $scopeType->value, 'label_key' => 'codeRules.scopeTypes.'.$scopeType->value],
                CodeRuleScopeType::cases(),
            ),
            'reset_frequencies' => array_map(
                static fn (CodeRuleResetFrequency $frequency): array => ['value' => $frequency->value, 'label_key' => 'codeRules.resetFrequencies.'.$frequency->value],
                CodeRuleResetFrequency::cases(),
            ),
            'sequence_scope_strategies' => array_map(
                static fn (CodeRuleScopeStrategy $strategy): array => ['value' => $strategy->value, 'label_key' => 'codeRules.scopeStrategies.'.$strategy->value],
                CodeRuleScopeStrategy::cases(),
            ),
            'scope_options' => [
                CodeRuleScopeType::Organization->value => Organization::query()
                    ->orderBy('name_en')
                    ->get(['id', 'name_en', 'code'])
                    ->map(fn (Organization $organization): array => [
                        'id' => $organization->id,
                        'label' => "{$organization->code} - {$organization->name_en}",
                    ]),
                CodeRuleScopeType::OrganizationType->value => OrganizationType::query()
                    ->orderBy('name_en')
                    ->get(['id', 'name_en', 'code'])
                    ->map(fn (OrganizationType $organizationType): array => [
                        'id' => $organizationType->id,
                        'label' => "{$organizationType->code} - {$organizationType->name_en}",
                    ]),
                CodeRuleScopeType::OrganizationUnitType->value => OrganizationUnitType::query()
                    ->orderBy('name_en')
                    ->get(['id', 'name_en', 'code', 'prefix'])
                    ->map(fn (OrganizationUnitType $organizationUnitType): array => [
                        'id' => $organizationUnitType->id,
                        'label' => trim(($organizationUnitType->prefix ?? $organizationUnitType->code ?? '').' - '.$organizationUnitType->name_en, ' -'),
                    ]),
                CodeRuleScopeType::ServiceType->value => ServiceType::query()
                    ->orderBy('name_en')
                    ->get(['id', 'name_en', 'code'])
                    ->map(fn (ServiceType $serviceType): array => [
                        'id' => $serviceType->id,
                        'label' => "{$serviceType->code} - {$serviceType->name_en}",
                    ]),
            ],
            'year_formats' => [
                ['value' => 'Y', 'label' => '2026'],
                ['value' => 'y', 'label' => '26'],
            ],
        ];
    }
}
