<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Controllers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Leave\Application\Services\LeaveRules;
use Modules\Leave\Application\Services\LeaveSettings;
use Modules\Leave\Domain\Enums\LeaveCategory;
use Modules\Leave\Domain\Models\LeaveLedgerEntry;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Domain\Models\LeaveRule;
use Modules\Leave\Domain\Models\LeaveRuleSet;
use Modules\Leave\Domain\Models\LeaveType;

/**
 * The settings page controls the module end to end: how a request is
 * decided and how the leave schedule works (general), the leave types and
 * every switch of each, and the rule sets that hold the figures of the law
 * (ADR 0039, decision 3). A rule set is prepared as a draft, then put in force from a
 * date; figures that requests were already counted under are never edited:
 * a change of law is a new rule set.
 */
class SettingsController extends Controller
{
    /** The type-specific figures a rule may carry, each a whole number or empty. */
    private const SETTING_KEYS = [
        'window_months', 'max_months_in_window', 'long_window_years', 'max_months_in_long_window', 'full_pay_months',
        'probation_months', 'probation_max_months', 'certificate_from_days', 'prenatal_days', 'postnatal_days', 'miscarriage_days',
    ];

    public function __construct(private readonly WriteAuditLogAction $audit, private readonly LeaveRules $rules) {}

    public function index(Request $request, LeaveSettings $settings): Response
    {
        $this->authorizeSettings($request);
        $used = LeaveRequest::query()->whereNotNull('leave_rule_set_id')->distinct()->pluck('leave_rule_set_id')->all();
        $usedTypes = array_values(array_unique([
            ...LeaveRequest::query()->distinct()->pluck('leave_type_id')->all(),
            ...LeaveLedgerEntry::query()->distinct()->pluck('leave_type_id')->all(),
        ]));

        return Inertia::render('modules/leave/Settings', [
            'leaveTypes' => LeaveType::query()->orderBy('sort_order')->get()->map(fn (LeaveType $type): array => [
                'id' => $type->id, 'code' => $type->code, 'name_en' => $type->name_en, 'name_am' => $type->name_am,
                'category' => $type->category->value, 'day_unit' => $type->day_unit, 'pay_class' => $type->pay_class,
                'uses_balance' => $type->uses_balance, 'allows_half_day' => $type->allows_half_day,
                'requires_supervisor_approval' => $type->requires_supervisor_approval, 'requires_document' => $type->requires_document,
                'employee_can_request' => $type->employee_can_request, 'gender' => $type->gender, 'is_active' => $type->is_active,
                'allows_past_start' => $type->allows_past_start, 'sort_order' => $type->sort_order, 'is_system' => $type->is_system,
                // The kind of leave and its balance are fixed once requests or ledger lines exist.
                'in_use' => in_array($type->id, $usedTypes, true),
            ])->all(),
            'ruleSets' => LeaveRuleSet::query()->with('rules')->orderByDesc('effective_from')->orderByDesc('created_at')->get()->map(fn (LeaveRuleSet $set): array => [
                'id' => $set->id, 'name' => $set->name, 'legal_reference' => $set->legal_reference, 'notes' => $set->notes,
                'effective_from' => $set->effective_from->toDateString(), 'effective_to' => $set->effective_to?->toDateString(),
                'status' => $set->status, 'is_confirmed' => $set->is_confirmed,
                // Figures stay open for editing until a request has been counted under them.
                'figures_locked' => in_array($set->id, $used, true),
                'rules' => $set->rules->map(fn (LeaveRule $rule): array => [
                    'leave_type_id' => $rule->leave_type_id, 'base_days' => $rule->base_days, 'increment_per_service_year' => $rule->increment_per_service_year,
                    'max_days' => $rule->max_days, 'waiting_months' => $rule->waiting_months, 'carry_over_mode' => $rule->carry_over_mode,
                    'carry_over_years' => $rule->carry_over_years, 'max_days_per_request' => $rule->max_days_per_request,
                    'max_days_per_year' => $rule->max_days_per_year, 'settings' => $rule->settings ?? (object) [],
                ])->all(),
            ])->all(),
            'settingKeys' => self::SETTING_KEYS,
            'leaveSettings' => $settings->all(),
        ]);
    }

    /** The module's own switches: how a request is decided and how the leave schedule works. */
    public function updateGeneral(Request $request, LeaveSettings $settings): RedirectResponse
    {
        $this->authorizeSettings($request);
        $data = $request->validate([
            'hr_confirmation_required' => ['required', 'boolean'],
            'min_notice_days' => ['required', 'integer', 'min:0', 'max:90'],
            'schedule_enabled' => ['required', 'boolean'],
            'schedule_requires_approval' => ['required', 'boolean'],
            'schedule_auto_count' => ['required', 'boolean'],
        ], [], ['min_notice_days' => __('leave::messages.fields.min_notice_days')]);

        $change = $settings->update($data, (int) $request->user()->getKey());
        $this->audit->execute(AuditEventType::LeaveRulesChanged, $request->user(), null, null, $change['before'], [...$change['after'], 'action' => 'general_settings']);

        return back()->with('success', __('leave::messages.flash.settings_saved'));
    }

    /** A new leave type. It can be asked for once a rule set in force carries its figures. */
    public function storeType(Request $request): RedirectResponse
    {
        $this->authorizeSettings($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('leave_types', 'code')],
            ...$this->typeRules(),
            'category' => ['required', Rule::enum(LeaveCategory::class)],
            'uses_balance' => ['required', 'boolean'],
        ], [], $this->typeAttributes());

        $type = DB::transaction(function () use ($data): LeaveType {
            $type = new LeaveType;
            $type->fill([...$data, 'gender' => $data['gender'] ?: null]);
            $type->forceFill(['is_system' => false])->save();
            // Every rule set gets an empty line for the new type, so its figures can be entered where the others are.
            foreach (LeaveRuleSet::query()->get() as $set) {
                $set->rules()->firstOrCreate(['leave_type_id' => $type->id]);
            }

            return $type;
        });
        $this->audit->execute(AuditEventType::LeaveRulesChanged, $request->user(), $type, null, null, ['leave_type' => $type->code, 'action' => 'type_created', 'category' => $data['category']]);

        return back()->with('success', __('leave::messages.flash.type_created'));
    }

    public function updateType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorizeSettings($request);
        $inUse = $this->typeInUse($leaveType);
        $data = $request->validate([
            ...$this->typeRules(),
            'category' => ['sometimes', Rule::enum(LeaveCategory::class)],
            'uses_balance' => ['sometimes', 'boolean'],
        ], [], $this->typeAttributes());
        // The kind of leave carries the law, and the balance its ledger: neither changes once the type has been used.
        if ($inUse) {
            foreach (['category', 'uses_balance'] as $fixed) {
                if (array_key_exists($fixed, $data) && $data[$fixed] != ($fixed === 'category' ? $leaveType->category->value : $leaveType->uses_balance)) {
                    throw ValidationException::withMessages([$fixed => __('leave::messages.errors.type_in_use')]);
                }
            }
        }
        $data['gender'] = $data['gender'] ?: null;
        $before = $leaveType->only(array_keys($data));
        $leaveType->update($data);
        $this->audit->execute(AuditEventType::LeaveRulesChanged, $request->user(), $leaveType, null, $before, [...$leaveType->only(array_keys($data)), 'leave_type' => $leaveType->code]);

        return back()->with('success', __('leave::messages.flash.settings_saved'));
    }

    /** @return array<string, mixed> */
    private function typeRules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:150'],
            'name_am' => ['required', 'string', 'max:150'],
            'day_unit' => ['required', Rule::in([LeaveType::UNIT_WORKING, LeaveType::UNIT_CALENDAR])],
            'pay_class' => ['required', Rule::in([LeaveType::PAY_FULL, LeaveType::PAY_NONE, LeaveType::PAY_BY_PERIOD])],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'allows_half_day' => ['required', 'boolean'],
            'allows_past_start' => ['required', 'boolean'],
            'requires_supervisor_approval' => ['required', 'boolean'],
            'requires_document' => ['required', 'boolean'],
            'employee_can_request' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private function typeAttributes(): array
    {
        return ['code' => __('leave::messages.fields.code'), 'name_en' => __('leave::messages.fields.name_en'), 'name_am' => __('leave::messages.fields.name_am'), 'sort_order' => __('leave::messages.fields.sort_order')];
    }

    private function typeInUse(LeaveType $type): bool
    {
        return LeaveRequest::query()->where('leave_type_id', $type->id)->exists()
            || LeaveLedgerEntry::query()->where('leave_type_id', $type->id)->exists();
    }

    /** A new draft, with the figures of the rule set it is copied from. */
    public function storeRuleSet(Request $request): RedirectResponse
    {
        $this->authorizeSettings($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'legal_reference' => ['nullable', 'string', 'max:500'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'copy_from' => ['nullable', 'uuid'],
        ], [], ['effective_from' => __('leave::messages.fields.effective_from')]);

        DB::transaction(function () use ($request, $data): void {
            $set = new LeaveRuleSet;
            $set->fill(['name' => $data['name'], 'legal_reference' => $data['legal_reference'] ?? null, 'effective_from' => $data['effective_from'], 'is_confirmed' => false]);
            $set->forceFill(['status' => LeaveRuleSet::STATUS_DRAFT, 'created_by' => $request->user()->getKey()])->save();

            $source = isset($data['copy_from']) ? LeaveRuleSet::query()->with('rules')->find($data['copy_from']) : null;
            $existing = $source?->rules->keyBy('leave_type_id') ?? collect();
            foreach (LeaveType::query()->get() as $type) {
                $from = $existing->get($type->id);
                $set->rules()->create(['leave_type_id' => $type->id, ...($from === null ? [] : $from->only([
                    'base_days', 'increment_per_service_year', 'max_days', 'waiting_months', 'carry_over_mode', 'carry_over_years',
                    'max_days_per_request', 'max_days_per_year', 'settings',
                ]))]);
            }
            $this->audit->execute(AuditEventType::LeaveRulesChanged, $request->user(), $set, null, null, ['rule_set' => $set->name, 'action' => 'created', 'effective_from' => $data['effective_from']]);
        });

        return back()->with('success', __('leave::messages.flash.rule_set_created'));
    }

    public function updateRuleSet(Request $request, LeaveRuleSet $leaveRuleSet): RedirectResponse
    {
        $this->authorizeSettings($request);
        $number = ['nullable', 'numeric', 'min:0', 'max:366'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'legal_reference' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_confirmed' => ['required', 'boolean'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'rules' => ['sometimes', 'array'],
            'rules.*.leave_type_id' => ['required', 'uuid'],
            'rules.*.base_days' => $number,
            'rules.*.increment_per_service_year' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'rules.*.max_days' => $number,
            'rules.*.waiting_months' => ['nullable', 'integer', 'min:0', 'max:60'],
            'rules.*.carry_over_mode' => ['required', Rule::in([LeaveRule::CARRY_NONE, LeaveRule::CARRY_AUTOMATIC, LeaveRule::CARRY_AUTHORIZED])],
            'rules.*.carry_over_years' => ['nullable', 'integer', 'min:0', 'max:10'],
            'rules.*.max_days_per_request' => $number,
            'rules.*.max_days_per_year' => $number,
            'rules.*.settings' => ['nullable', 'array'],
        ], [], ['effective_from' => __('leave::messages.fields.effective_from')]);

        $locked = LeaveRequest::query()->where('leave_rule_set_id', $leaveRuleSet->id)->exists();
        if ($locked && (isset($data['rules']) && $this->figuresDiffer($leaveRuleSet, $data['rules']) || $data['effective_from'] !== $leaveRuleSet->effective_from->toDateString())) {
            throw ValidationException::withMessages(['rules' => __('leave::messages.errors.rule_set_in_use')]);
        }

        DB::transaction(function () use ($request, $leaveRuleSet, $data, $locked): void {
            $leaveRuleSet->update([
                'name' => $data['name'], 'legal_reference' => $data['legal_reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'is_confirmed' => $data['is_confirmed'], 'effective_from' => $data['effective_from'],
            ]);
            $leaveRuleSet->forceFill(['updated_by' => $request->user()->getKey()])->save();
            if (! $locked) {
                foreach ($data['rules'] ?? [] as $figures) {
                    $leaveRuleSet->rules()->updateOrCreate(['leave_type_id' => $figures['leave_type_id']], $this->ruleAttributes($figures));
                }
            }
            $this->audit->execute(AuditEventType::LeaveRulesChanged, $request->user(), $leaveRuleSet, null, null, [
                'rule_set' => $leaveRuleSet->name, 'action' => 'updated', 'is_confirmed' => $data['is_confirmed'], 'figures_changed' => ! $locked && isset($data['rules']),
            ]);
        });
        $this->rules->forget();

        return back()->with('success', __('leave::messages.flash.settings_saved'));
    }

    /** Put a draft in force from its date; the rule set it replaces ends the day before. */
    public function activateRuleSet(Request $request, LeaveRuleSet $leaveRuleSet): RedirectResponse
    {
        $this->authorizeSettings($request);
        if ($leaveRuleSet->status !== LeaveRuleSet::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => __('leave::messages.errors.rule_set_not_draft')]);
        }

        DB::transaction(function () use ($request, $leaveRuleSet): void {
            $from = $leaveRuleSet->effective_from->toDateString();
            $dayBefore = Carbon::parse($from)->subDay()->toDateString();
            foreach (LeaveRuleSet::query()->where('status', LeaveRuleSet::STATUS_ACTIVE)->whereKeyNot($leaveRuleSet->id)->get() as $other) {
                if ($other->effective_from->toDateString() >= $from) {
                    // Starts on or after the new one: it would win on its dates, so it is retired.
                    $other->forceFill(['status' => LeaveRuleSet::STATUS_RETIRED])->save();
                } elseif ($other->effective_to === null || $other->effective_to->toDateString() >= $from) {
                    $other->update(['effective_to' => $dayBefore]);
                }
            }
            $leaveRuleSet->forceFill(['status' => LeaveRuleSet::STATUS_ACTIVE, 'updated_by' => $request->user()->getKey()])->save();
            $this->audit->execute(AuditEventType::LeaveRulesChanged, $request->user(), $leaveRuleSet, null, null, ['rule_set' => $leaveRuleSet->name, 'action' => 'activated', 'effective_from' => $from]);
        });
        $this->rules->forget();

        return back()->with('success', __('leave::messages.flash.rule_set_activated'));
    }

    private function authorizeSettings(Request $request): void
    {
        abort_unless($request->user()->can('leave.settings.manage'), 403);
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private function ruleAttributes(array $figures): array
    {
        $settings = [];
        foreach (self::SETTING_KEYS as $key) {
            $value = $figures['settings'][$key] ?? null;
            if (is_numeric($value) && (int) $value >= 0) {
                $settings[$key] = (int) $value;
            }
        }
        $decimal = static fn (mixed $value): ?float => is_numeric($value) ? round((float) $value * 2) / 2 : null;

        return [
            'base_days' => $decimal($figures['base_days'] ?? null),
            'increment_per_service_year' => $decimal($figures['increment_per_service_year'] ?? null),
            'max_days' => $decimal($figures['max_days'] ?? null),
            'waiting_months' => (int) ($figures['waiting_months'] ?? 0),
            'carry_over_mode' => $figures['carry_over_mode'],
            'carry_over_years' => (int) ($figures['carry_over_years'] ?? 0),
            'max_days_per_request' => $decimal($figures['max_days_per_request'] ?? null),
            'max_days_per_year' => $decimal($figures['max_days_per_year'] ?? null),
            'settings' => $settings === [] ? null : $settings,
        ];
    }

    /** @param array<int, array<string, mixed>> $incoming */
    private function figuresDiffer(LeaveRuleSet $set, array $incoming): bool
    {
        $current = $set->rules()->get()->keyBy('leave_type_id');
        foreach ($incoming as $figures) {
            $rule = $current->get($figures['leave_type_id']);
            $attributes = $this->ruleAttributes($figures);
            if ($rule === null) {
                return true;
            }
            foreach ($attributes as $key => $value) {
                $existing = $rule->{$key};
                if ($key === 'settings') {
                    $a = $existing ?? [];
                    $b = $value ?? [];
                    ksort($a);
                    ksort($b);
                    if ($a != $b) {
                        return true;
                    }
                } elseif ((is_numeric($existing) ? (float) $existing : $existing) != (is_numeric($value) ? (float) $value : $value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
