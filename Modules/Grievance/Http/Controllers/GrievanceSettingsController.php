<?php

declare(strict_types=1);

namespace Modules\Grievance\Http\Controllers;

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationLetterhead;
use App\Models\OrganizationSeal;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Grievance\Application\Services\GrievanceAudit;
use Modules\Grievance\Application\Services\GrievanceCorrespondenceService;
use Modules\Grievance\Application\Services\GrievanceHandlerRegistry;
use Modules\Grievance\Application\Services\GrievancePresenter;
use Modules\Grievance\Domain\Enums\GrievanceConfidentiality;
use Modules\Grievance\Domain\Enums\GrievanceDecisionType;
use Modules\Grievance\Domain\Enums\GrievanceHandlerType;
use Modules\Grievance\Domain\Enums\GrievanceLetterLanguage;
use Modules\Grievance\Domain\Enums\GrievanceLetterType;
use Modules\Grievance\Domain\Enums\GrievancePriority;
use Modules\Grievance\Domain\Enums\GrievanceReasonCodeType;
use Modules\Grievance\Domain\Models\GrievanceApprovalRule;
use Modules\Grievance\Domain\Models\GrievanceCategory;
use Modules\Grievance\Domain\Models\GrievanceCommittee;
use Modules\Grievance\Domain\Models\GrievanceDelegation;
use Modules\Grievance\Domain\Models\GrievanceExternalAuthority;
use Modules\Grievance\Domain\Models\GrievanceLetterTemplate;
use Modules\Grievance\Domain\Models\GrievanceReasonCode;
use Modules\Grievance\Domain\Models\GrievanceSlaProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Grievance Management → Settings: policy switches, categories, reason codes,
 * approval rules, external authorities, letter templates, letterheads,
 * official seals and approval delegations.
 */
class GrievanceSettingsController extends Controller
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly GrievanceAudit $audit,
        private readonly GrievancePresenter $presenter,
        private readonly OrganizationScopeService $scope,
        private readonly GrievanceHandlerRegistry $handlers,
    ) {}

    /**
     * What Regulation 201/2018 needs configured before grievances can run to a
     * lawful end, counted here so every viewer sees the same answer (the seal
     * list itself is shown only to seal managers).
     *
     * @return array<string, mixed>
     */
    private function statutoryReadiness(User $user): array
    {
        $today = now()->toDateString();

        return [
            // Arts. 30(1), 35(1): someone must be named to decide on committee recommendations.
            'head_decision_rules' => GrievanceApprovalRule::query()
                ->where('is_active', true)->where('requires_approval', true)->whereNotNull('approver_position_id')
                ->where('handler_type', GrievanceHandlerType::Committee->value)
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                ->count(),
            // Art. 35(2): the written decision carries the institution seal.
            'approved_seals' => OrganizationSeal::query()->where('status', 'active')->whereNotNull('approved_at')->count(),
            // Working-day periods skip public holidays only if they are listed.
            'holidays' => PublicHoliday::query()->where('is_active', true)->count(),
            'holidays_url' => $user->can('viewAny', PublicHoliday::class) ? route('cafeteria.holidays.index') : null,
            // Statutory periods use the civil-service work week, not the SLA setting.
            'work_week_days' => array_values(array_map('intval', (array) config('civil_service.work_week_days', [1, 2, 3, 4, 5]))),
        ];
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievance_settings.view'), 403);
        $tab = (string) ($request->string('tab')->value() ?: 'policy');

        return Inertia::render('Grievances/Settings/Index', [
            'tab' => $tab,
            'fields' => $this->settings->getGroupForAdmin(SystemSettingsRegistry::GROUP_GRIEVANCES),
            'categories' => GrievanceCategory::query()->withCount('grievances')->orderBy('sort_order')->orderBy('name_en')->get(),
            'reasonCodes' => GrievanceReasonCode::query()->orderBy('type')->orderBy('sort_order')->get(),
            'approvalRules' => GrievanceApprovalRule::query()->with(['organization:id,name_en,name_am', 'category:id,name_en,name_am', 'approverPosition:id,title_en,title_am', 'approvalSlaProfile:id,name_en,name_am'])
                ->orderByDesc('is_active')->orderBy('priority')->get()->map(fn (GrievanceApprovalRule $r) => [
                    ...$r->only(['id', 'name_en', 'name_am', 'handler_id', 'requires_approval', 'priority', 'is_active', 'organization_id', 'category_id', 'approver_position_id', 'approval_sla_profile_id']),
                    'handler_type' => $r->handler_type?->value,
                    'decision_type' => $r->decision_type?->value,
                    'handler' => $r->handler_type && $r->handler_id ? $this->presenter->handler($r->handler_type->value, $r->handler_id) : null,
                    'organization' => $r->organization?->only(['id', 'name_en', 'name_am']),
                    'category' => $r->category?->only(['id', 'name_en', 'name_am']),
                    'approver_position' => $r->approverPosition?->only(['id', 'title_en', 'title_am']),
                    'approval_sla_profile' => $r->approvalSlaProfile?->only(['id', 'name_en', 'name_am']),
                    'effective_from' => $r->effective_from?->toDateString(),
                    'effective_to' => $r->effective_to?->toDateString(),
                ]),
            'externalAuthorities' => GrievanceExternalAuthority::query()->with('organization:id,name_en,name_am')->orderBy('name_en')->get(),
            'templates' => GrievanceLetterTemplate::query()->with('organization:id,name_en,name_am')->orderBy('template_type')->orderBy('language')->get()
                ->map(fn (GrievanceLetterTemplate $t) => [
                    ...$t->only(['id', 'name', 'subject_template', 'body_template', 'is_active', 'organization_id']),
                    'template_type' => $t->template_type->value,
                    'language' => $t->language->value,
                    'organization' => $t->organization?->only(['id', 'name_en', 'name_am']),
                    'effective_from' => $t->effective_from?->toDateString(),
                    'effective_to' => $t->effective_to?->toDateString(),
                ]),
            'letterheads' => OrganizationLetterhead::query()->with('organization:id,name_en,name_am')->get(),
            'seals' => $user->can('grievance_settings.manage_seals') || $user->can('grievance_correspondence.apply_seal')
                ? $this->scope->applyOrganizationScope(OrganizationSeal::query(), $user)->with('organization:id,name_en,name_am')->latest()->get()->map(fn (OrganizationSeal $s) => [
                    ...$s->only(['id', 'name', 'status', 'mime_type', 'sha256', 'organization_id', 'uploaded_by', 'approved_by']),
                    'organization' => $s->organization?->only(['id', 'name_en', 'name_am']),
                    'approved_at' => $s->approved_at?->toIso8601String(),
                    'effective_from' => $s->effective_from?->toDateString(),
                    'effective_to' => $s->effective_to?->toDateString(),
                ])
                : [],
            'delegations' => GrievanceDelegation::query()->with(['delegator:id,name', 'delegate:id,name', 'position:id,title_en,title_am', 'organization:id,name_en,name_am'])->latest()->limit(200)->get()
                ->map(fn (GrievanceDelegation $d) => [
                    ...$d->only(['id', 'authority', 'status', 'reason']),
                    'delegator' => $d->delegator?->only(['id', 'name']),
                    'delegate' => $d->delegate?->only(['id', 'name']),
                    'position' => $d->position?->only(['id', 'title_en', 'title_am']),
                    'organization' => $d->organization?->only(['id', 'name_en', 'name_am']),
                    'starts_at' => $d->starts_at?->toIso8601String(),
                    'ends_at' => $d->ends_at?->toIso8601String(),
                    'revoked_at' => $d->revoked_at?->toIso8601String(),
                ]),
            'statutory' => $this->statutoryReadiness($user),
            'options' => [
                'organizations' => $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->where('status', 'active')->orderBy('name_en')->limit(2000)->get(['id', 'name_en', 'name_am']),
                'committees' => $this->scope->applyOrganizationScope(GrievanceCommittee::query(), $user)->orderBy('name_en')->limit(2000)->get(['id', 'name_en', 'name_am', 'organization_id', 'status']),
                'reason_code_types' => GrievanceReasonCodeType::values(),
                'confidentiality' => GrievanceConfidentiality::values(),
                'priorities' => GrievancePriority::values(),
                'decision_types' => GrievanceDecisionType::values(),
                'handler_types' => [GrievanceHandlerType::Committee->value, GrievanceHandlerType::OrganizationUnit->value, GrievanceHandlerType::ExternalAuthority->value],
                'letter_types' => GrievanceLetterType::values(),
                'letter_languages' => [GrievanceLetterLanguage::Am->value, GrievanceLetterLanguage::En->value],
                'tokens' => GrievanceCorrespondenceService::TOKENS,
                'approval_sla_profiles' => GrievanceSlaProfile::query()->where('purpose', 'approval')->where('is_active', true)->get(['id', 'name_en', 'name_am']),
            ],
            'can' => [
                'update' => $user->can('grievance_settings.update'),
                'delegations' => $user->can('grievance_settings.manage_delegations'),
                'seals' => $user->can('grievance_settings.manage_seals'),
            ],
        ]);
    }

    public function updatePolicy(Request $request, UpdateSystemSettingsGroupAction $update): RedirectResponse
    {
        $this->authorizeUpdate($request);
        $rules = collect(SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_GRIEVANCES))->map(fn (array $f) => $f['validation_rules'])->all();
        $data = $request->validate($rules + [
            'committee_max_members' => ['required', 'integer', 'min:1', 'max:25', 'gte:committee_min_members'],
        ]);
        $update->execute(SystemSettingsRegistry::GROUP_GRIEVANCES, $data, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.settings_saved'), 'type' => 'success']);
    }

    // ── Categories ───────────────────────────────────────────────────────────

    public function saveCategory(Request $request, ?GrievanceCategory $category = null): RedirectResponse
    {
        $this->authorizeUpdate($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('grievance_categories', 'code')->ignore($category?->getKey())],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'default_confidentiality' => ['nullable', Rule::in(GrievanceConfidentiality::values())],
            'default_priority' => ['nullable', Rule::in(GrievancePriority::values())],
            'requires_executive_approval' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
        $data['sort_order'] ??= 0;
        $category ??= new GrievanceCategory;
        $category->fill($data)->save();
        $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $request->user(), $category, ['category' => $category->code]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Reason codes ─────────────────────────────────────────────────────────

    public function saveReasonCode(Request $request, ?GrievanceReasonCode $reasonCode = null): RedirectResponse
    {
        $this->authorizeUpdate($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(GrievanceReasonCodeType::values())],
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_]+$/', Rule::unique('grievance_reason_codes')->where('type', $request->input('type'))->ignore($reasonCode?->getKey())],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        $data['sort_order'] ??= 0;
        $reasonCode ??= new GrievanceReasonCode;
        $reasonCode->fill($data)->save();
        $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $request->user(), $reasonCode, ['reason_code' => $reasonCode->code, 'type' => $data['type']]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Approval rules ───────────────────────────────────────────────────────

    public function saveApprovalRule(Request $request, ?GrievanceApprovalRule $rule = null): RedirectResponse
    {
        $this->authorizeUpdate($request);
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'handler_type' => ['nullable', Rule::in([GrievanceHandlerType::Committee->value, GrievanceHandlerType::OrganizationUnit->value, GrievanceHandlerType::ExternalAuthority->value])],
            'handler_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid', 'exists:grievance_categories,id'],
            'decision_type' => ['nullable', Rule::in(GrievanceDecisionType::values())],
            'requires_approval' => ['required', 'boolean'],
            'approver_position_id' => ['required_if:requires_approval,true', 'nullable', 'uuid', 'exists:positions,id'],
            'approval_sla_profile_id' => ['nullable', 'uuid', 'exists:grievance_sla_profiles,id'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
        ]);
        $this->authorizeOrganization($request, $data['organization_id'] ?? null, $rule?->organization_id);
        $data['handler_id'] = $this->validatedHandlerId($data['handler_type'] ?? null, $data['handler_id'] ?? null);
        $rule ??= new GrievanceApprovalRule(['created_by' => $request->user()->getKey()]);
        $rule->fill($data)->save();
        $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $request->user(), $rule, ['approval_rule' => $rule->getKey(), 'requires_approval' => $rule->requires_approval, 'position' => $rule->approver_position_id]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── External authorities ─────────────────────────────────────────────────

    public function saveExternalAuthority(Request $request, ?GrievanceExternalAuthority $authority = null): RedirectResponse
    {
        $this->authorizeUpdate($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('grievance_external_authorities', 'code')->ignore($authority?->getKey())],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'is_administrative_tribunal' => ['required', 'boolean'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ]);
        $this->authorizeOrganization($request, $data['organization_id'] ?? null, $authority?->organization_id);
        $authority ??= new GrievanceExternalAuthority;
        $authority->fill($data)->save();
        $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $request->user(), $authority, ['external_authority' => $authority->code]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Letter templates ─────────────────────────────────────────────────────

    public function saveTemplate(Request $request, ?GrievanceLetterTemplate $template = null): RedirectResponse
    {
        $this->authorizeUpdate($request);
        $data = $request->validate([
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'template_type' => ['required', Rule::in(GrievanceLetterType::values())],
            'language' => ['required', Rule::in([GrievanceLetterLanguage::Am->value, GrievanceLetterLanguage::En->value])],
            'name' => ['required', 'string', 'max:255'],
            'subject_template' => ['required', 'string', 'max:255'],
            'body_template' => ['required', 'string', 'max:30000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
        ]);
        $this->authorizeOrganization($request, $data['organization_id'] ?? null, $template?->organization_id);
        $this->assertKnownTokens($data['subject_template'].' '.$data['body_template']);
        if ($data['is_active']) {
            $this->assertNoOverlappingTemplate($data, $template);
        }

        $template ??= new GrievanceLetterTemplate(['created_by' => $request->user()->getKey()]);
        $template->fill([...$data, 'updated_by' => $request->user()->getKey()])->save();
        $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $request->user(), $template, ['template' => $template->getKey(), 'type' => $data['template_type'], 'language' => $data['language']]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Letterheads ──────────────────────────────────────────────────────────

    public function saveLetterhead(Request $request): RedirectResponse
    {
        $this->authorizeUpdate($request);
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'header_line_en' => ['nullable', 'string', 'max:255'],
            'header_line_am' => ['nullable', 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:1000'],
            'address_am' => ['nullable', 'string', 'max:1000'],
            'po_box' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:60'],
            'fax' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'footer_en' => ['nullable', 'string', 'max:1000'],
            'footer_am' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_unless($this->scope->canExercisePermission($request->user(), 'grievance_settings.update', $data['organization_id']), 403);
        $letterhead = OrganizationLetterhead::query()->updateOrCreate(['organization_id' => $data['organization_id']], [...$data, 'updated_by' => $request->user()->getKey()]);
        $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $request->user(), $letterhead, ['letterhead' => $letterhead->organization_id]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Official seals (controlled asset) ────────────────────────────────────

    public function uploadSeal(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('grievance_settings.manage_seals'), 403);
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:png', 'mimetypes:image/png', 'max:1024', 'dimensions:min_width=100,min_height=100,max_width=2000,max_height=2000'],
            'effective_from' => ['nullable', 'date'],
        ]);
        abort_unless($this->scope->canExercisePermission($user, 'grievance_settings.manage_seals', $data['organization_id']), 403);

        $file = $request->file('file');
        $path = $file->storeAs('organization-seals/'.$data['organization_id'], Str::uuid7().'.png', 'local');
        $seal = OrganizationSeal::query()->create([
            'organization_id' => $data['organization_id'],
            'name' => $data['name'],
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'image/png',
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'status' => 'pending',
            'effective_from' => $data['effective_from'] ?? now()->toDateString(),
            'uploaded_by' => $user->getKey(),
        ]);
        $this->audit->record(AuditEventType::GrievanceSealChanged, $user, $seal, ['seal' => $seal->getKey(), 'action' => 'uploaded', 'sha256' => $seal->sha256]);

        return back()->with('flash', ['message' => __('grievances.flash.seal_uploaded'), 'type' => 'success']);
    }

    /** A second person approves a seal before it can be applied; retiring keeps the record. */
    public function decideSeal(Request $request, OrganizationSeal $seal): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('grievance_settings.manage_seals') && $this->scope->canExercisePermission($user, 'grievance_settings.manage_seals', $seal->organization_id), 403);
        $data = $request->validate(['action' => ['required', 'in:approve,retire']]);
        if ($data['action'] === 'approve' && (int) $seal->uploaded_by === (int) $user->getKey() && ! $user->isSuperAdmin()) {
            throw ValidationException::withMessages(['seal' => __('grievances.errors.separation_of_duties')]);
        }

        DB::transaction(function () use ($seal, $user, $data): void {
            // Lock the organization's seals: two concurrent approvals must not leave two active seals.
            $seals = OrganizationSeal::query()->where('organization_id', $seal->organization_id)->lockForUpdate()->get();
            $current = $seals->firstWhere('id', $seal->getKey());
            // Only a pending seal can be approved; a retired seal stays retired.
            $allowed = $data['action'] === 'approve' ? $current?->status === 'pending' : $current !== null && $current->status !== 'retired';
            if (! $allowed) {
                throw ValidationException::withMessages(['seal' => __('grievances.errors.stale')]);
            }

            if ($data['action'] === 'approve') {
                // One active seal per organization.
                OrganizationSeal::query()->where('organization_id', $current->organization_id)->where('status', 'active')->whereKeyNot($current->getKey())
                    ->update(['status' => 'retired', 'effective_to' => now()->toDateString()]);
                $current->forceFill(['status' => 'active', 'approved_by' => $user->getKey(), 'approved_at' => now()])->save();
            } else {
                $current->forceFill(['status' => 'retired', 'effective_to' => now()->toDateString()])->save();
            }
            $this->audit->record(AuditEventType::GrievanceSealChanged, $user, $current, ['seal' => $current->getKey(), 'action' => $data['action']]);
        });

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function previewSeal(Request $request, OrganizationSeal $seal): StreamedResponse
    {
        $user = $request->user();
        abort_unless(($user->can('grievance_settings.manage_seals') || $user->can('grievance_correspondence.apply_seal'))
            && $this->scope->canExercisePermission($user, $user->can('grievance_settings.manage_seals') ? 'grievance_settings.manage_seals' : 'grievance_correspondence.apply_seal', $seal->organization_id), 403);
        abort_unless(Storage::disk($seal->disk)->exists($seal->path), 404);

        return Storage::disk($seal->disk)->response($seal->path, 'seal.png', ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ── Delegations ──────────────────────────────────────────────────────────

    public function saveDelegation(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('grievance_settings.manage_delegations'), 403);
        // The page picks whole days: a delegation ending on a date covers that entire day.
        $endsAt = $request->input('ends_at');
        if (is_string($endsAt) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsAt) === 1) {
            $request->merge(['ends_at' => $endsAt.' 23:59:59']);
        }
        $data = $request->validate([
            'delegator_user_id' => ['required', 'integer', 'exists:users,id'],
            'delegate_user_id' => ['required', 'integer', 'exists:users,id', 'different:delegator_user_id'],
            'position_id' => ['nullable', 'uuid', 'exists:positions,id'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $this->authorizeOrganization($request, $data['organization_id'] ?? null, null, 'grievance_settings.manage_delegations');
        // No self-delegation: the manager can neither delegate to themselves
        // nor record a delegation that benefits themselves.
        if ((int) $data['delegate_user_id'] === (int) $user->getKey() || ((int) $data['delegator_user_id'] === (int) $user->getKey() && ! $user->isSuperAdmin())) {
            throw ValidationException::withMessages(['delegate_user_id' => __('grievances.errors.self_delegation')]);
        }
        if (! User::query()->find($data['delegate_user_id'])?->can('grievance_decisions.approve')) {
            throw ValidationException::withMessages(['delegate_user_id' => __('grievances.errors.delegate_lacks_permission')]);
        }

        $delegation = GrievanceDelegation::query()->create([...$data, 'authority' => 'decision_approval', 'status' => 'active', 'created_by' => $user->getKey()]);
        $this->audit->record(AuditEventType::GrievanceDelegationChanged, $user, $delegation, ['delegator' => $data['delegator_user_id'], 'delegate' => $data['delegate_user_id'], 'ends_at' => $data['ends_at']]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function revokeDelegation(Request $request, GrievanceDelegation $delegation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('grievance_settings.manage_delegations'), 403);
        $this->authorizeOrganization($request, $delegation->organization_id, null, 'grievance_settings.manage_delegations');
        // Conditional update: a second revoke must not overwrite who revoked it and when.
        $revoked = GrievanceDelegation::query()->whereKey($delegation->getKey())->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_by' => $user->getKey(), 'revoked_at' => now(), 'updated_at' => now()]);
        if ($revoked === 0) {
            throw ValidationException::withMessages(['delegation' => __('grievances.errors.stale')]);
        }
        $delegation->refresh();
        $this->audit->record(AuditEventType::GrievanceDelegationChanged, $user, $delegation, ['revoked' => true]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    private function authorizeUpdate(Request $request): void
    {
        abort_unless($request->user()->can('grievance_settings.update'), 403);
    }

    /**
     * A scoped administrator configures only organizations in their scope —
     * both the record's current organization and the one it is being moved
     * to. "All organizations" (null) needs authority that is not org-bound.
     */
    private function authorizeOrganization(Request $request, ?string $organizationId, ?string $currentOrganizationId = null, string $permission = 'grievance_settings.update'): void
    {
        $user = $request->user();
        abort_unless($this->scope->canExercisePermission($user, $permission, $organizationId), 403);
        if ($currentOrganizationId !== null && $currentOrganizationId !== $organizationId) {
            abort_unless($this->scope->canExercisePermission($user, $permission, $currentOrganizationId), 403);
        }
    }

    /** A rule may target one specific handler, which must exist; without a handler type there is no handler. */
    private function validatedHandlerId(?string $handlerType, ?string $handlerId): ?string
    {
        if ($handlerType === null || $handlerId === null) {
            return null;
        }
        if ($this->handlers->find(GrievanceHandlerType::from($handlerType), $handlerId) === null) {
            throw ValidationException::withMessages(['handler_id' => __('grievances.errors.handler_missing')]);
        }

        return $handlerId;
    }

    /**
     * Letters pick one active template per type, language and organization;
     * two active templates over the same days would make that choice arbitrary.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoOverlappingTemplate(array $data, ?GrievanceLetterTemplate $template): void
    {
        $overlaps = GrievanceLetterTemplate::query()
            ->where('is_active', true)
            ->where('template_type', $data['template_type'])
            ->where('language', $data['language'])
            ->where(fn ($q) => ($data['organization_id'] ?? null) === null ? $q->whereNull('organization_id') : $q->where('organization_id', $data['organization_id']))
            ->when($template?->exists, fn ($q) => $q->whereKeyNot($template->getKey()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
            ->when(($data['effective_to'] ?? null) !== null, fn ($q) => $q->whereDate('effective_from', '<=', $data['effective_to']))
            ->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['effective_from' => __('grievances.errors.template_overlap')]);
        }
    }

    /** Templates may only use whitelisted tokens (a typo would silently print nothing). */
    private function assertKnownTokens(string $text): void
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);
        $unknown = array_values(array_diff(array_unique($matches[1]), GrievanceCorrespondenceService::TOKENS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body_template' => __('grievances.errors.unknown_tokens', ['tokens' => implode(', ', $unknown)])]);
        }
    }
}
