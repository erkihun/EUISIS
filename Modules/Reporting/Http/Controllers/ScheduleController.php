<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Support\CyclePlanner;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\UseCases\ManageSchedules;
use Modules\Reporting\Application\UseCases\PublishCycles;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Calendar\EthiopianDate;
use Modules\Reporting\Domain\Reminders\ReminderStages;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\Schedule;
use Modules\Reporting\Infrastructure\Persistence\Models\ScheduleTarget;

/** Schedules: who reports what, when; the preview of periods and deadlines; publishing a period or a special report. */
final class ScheduleController extends ReportingController
{
    private const RULE_KEYS = ['frequency', 'calendar_basis', 'fiscal_year_start_month', 'due_offset_days', 'opens_offset_days', 'due_time', 'timezone', 'holiday_adjustment'];

    public function index(Request $request): Response
    {
        ReportingGuard::require($this->actor($request), ReportingRoles::PUBLISH_SCHEDULES);
        $types = ReportType::query()->get(['id', 'name_en', 'name_am'])->keyBy('id');
        $targets = ScheduleTarget::query()->selectRaw('schedule_id, COUNT(*) AS total')->groupBy('schedule_id')->pluck('total', 'schedule_id');

        return Inertia::render('modules/reporting/Schedules', [
            'schedules' => Schedule::query()->orderBy('name_en')->get()->map(fn (Schedule $s): array => [
                ...$s->only(['id', 'name_en', 'name_am', 'frequency', 'calendar_basis', 'is_active', 'due_time']),
                'report_type' => $types[$s->report_type_id]?->only(['name_en', 'name_am']),
                'targets' => (int) ($targets[$s->id] ?? 0),
                'starts_on' => $s->starts_on->toDateString(),
            ])->all(),
            'report_types' => $this->formOptions()['report_types'],
            'cycles' => Cycle::query()->with('reportType')->orderByDesc('due_at')->limit(30)->get()->map(fn (Cycle $c): array => [
                'id' => $c->id, 'label_en' => $c->period_label_en, 'label_am' => $c->period_label_am, 'special' => $c->schedule_id === null,
                'report_type' => ['name_en' => $c->reportType->name_en, 'name_am' => $c->reportType->name_am],
                'opens_at' => $c->opens_at->toIso8601String(), 'due_at' => $c->due_at->toIso8601String(),
                'obligations' => Obligation::query()->where('cycle_id', $c->id)->count(),
            ])->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        ReportingGuard::require($this->actor($request), ReportingRoles::PUBLISH_SCHEDULES);

        return Inertia::render('modules/reporting/ScheduleForm', ['schedule' => null, 'targets' => [], 'receiving' => null, 'reviewers' => [], ...$this->formOptions()]);
    }

    public function edit(Request $request, string $schedule, OrganizationDirectory $organizations, ReportingAccessResolver $access): Response
    {
        ReportingGuard::require($this->actor($request), ReportingRoles::PUBLISH_SCHEDULES);
        $record = Schedule::query()->findOrFail($schedule);
        $targets = ScheduleTarget::query()->where('schedule_id', $record->id)->orderBy('id')->limit(ManageSchedules::MAX_TARGETS)->get();
        $names = $organizations->findMany($targets->pluck('organization_id')->unique()->values()->all());
        $people = $access->users(array_values(array_unique(array_filter([...$targets->pluck('preparer_user_id')->all(), ...$targets->pluck('accountable_user_id')->all(), ...$targets->pluck('person_user_id')->all(), ...($record->reviewer_user_ids ?? [])]))));
        $units = [];
        foreach ($targets->whereNotNull('organization_unit_id')->pluck('organization_id')->unique() as $organizationId) {
            $units += $organizations->units((string) $organizationId);
        }
        $person = fn (?int $id): ?array => $id === null || ! isset($people[$id]) ? null : $people[$id]->toArray();

        return Inertia::render('modules/reporting/ScheduleForm', [
            'schedule' => [
                ...$record->only(['id', 'report_type_id', 'name_en', 'name_am', 'review_sla_working_days', 'revision_days', 'late_policy', 'receiving_organization_id', 'is_active', ...self::RULE_KEYS]),
                'starts_on' => $record->starts_on->toDateString(), 'ends_on' => $record->ends_on?->toDateString(),
                'reminder_rules' => $record->reminder_rules ?? ReminderStages::DEFAULT_RULES,
            ],
            'targets' => $targets->map(fn (ScheduleTarget $t): array => [
                'organization' => isset($names[$t->organization_id]) ? $names[$t->organization_id]->toArray() : ['id' => $t->organization_id, 'code' => '?', 'name_en' => null, 'name_am' => null],
                'unit' => $t->organization_unit_id === null ? null : (isset($units[$t->organization_unit_id]) ? $units[$t->organization_unit_id]->toArray() : ['id' => $t->organization_unit_id, 'organization_id' => $t->organization_id, 'code' => '?', 'name_en' => null, 'name_am' => null]),
                'person' => $person($t->person_user_id), 'preparer' => $person($t->preparer_user_id), 'accountable' => $person($t->accountable_user_id),
            ])->all(),
            'receiving' => $record->receiving_organization_id === null ? null : $organizations->find($record->receiving_organization_id)?->toArray(),
            'reviewers' => array_values(array_filter(array_map(fn ($id): ?array => $person((int) $id), $record->reviewer_user_ids ?? []))),
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request, ManageSchedules $schedules): RedirectResponse
    {
        [$data, $targets] = $this->validated($request);
        $schedule = $schedules->save($this->actor($request), null, $data, $targets);

        return redirect()->route('reporting.schedules.edit', $schedule->id)->with('success', __('reporting::messages.flash.saved'));
    }

    public function update(Request $request, string $schedule, ManageSchedules $schedules): RedirectResponse
    {
        [$data, $targets] = $this->validated($request);
        $schedules->save($this->actor($request), $schedule, $data, $targets);

        return back()->with('success', __('reporting::messages.flash.saved'));
    }

    /** The next periods with their opening and deadline moments, before anything is published. */
    public function preview(Request $request, CyclePlanner $planner): JsonResponse
    {
        ReportingGuard::require($this->actor($request), ReportingRoles::PUBLISH_SCHEDULES);
        $rules = $request->validate($this->ruleRules());
        $from = $request->date('starts_on')?->toDateString() ?? now('Africa/Addis_Ababa')->toDateString();

        return response()->json(['periods' => array_map(fn (array $row): array => [
            'key' => $row['period']->key, 'label_en' => $row['period']->labelEn, 'label_am' => $row['period']->labelAm,
            'start' => $row['period']->startDate, 'end' => $row['period']->endDate,
            'start_ethiopian' => EthiopianDate::fromIso($row['period']->startDate)->format($this->language()),
            'end_ethiopian' => EthiopianDate::fromIso($row['period']->endDate)->format($this->language()),
            'opens_at' => CarbonImmutable::instance($row['opens_at'])->toIso8601String(), 'due_at' => CarbonImmutable::instance($row['due_at'])->toIso8601String(),
        ], $planner->preview($this->castRules($rules), $from, 8))]);
    }

    public function publish(Request $request, string $schedule, PublishCycles $cycles): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $cycle = $cycles->publishPeriod($this->actor($request), $schedule, $data['date']);

        return back()->with('success', __('reporting::messages.flash.published', ['count' => Obligation::query()->where('cycle_id', $cycle->id)->count()]));
    }

    /** A special (one-off) report with its own period and deadline. */
    public function special(Request $request, ManageSchedules $schedules, PublishCycles $cycles): RedirectResponse
    {
        $data = $request->validate([
            'report_type_id' => ['required', 'uuid'], 'period_label_en' => ['required', 'string', 'max:200'], 'period_label_am' => ['required', 'string', 'max:200'],
            'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'opens_at' => ['required', 'date'], 'due_at' => ['required', 'date', 'after:opens_at'],
            'receiving_organization_id' => ['nullable', 'uuid'], 'late_policy' => ['required', Rule::in(['accept', 'accept_with_reason', 'block'])],
            'targets' => ['required', 'array', 'min:1'], 'targets.*.organization_id' => ['required', 'uuid'],
            'targets.*.organization_unit_id' => ['nullable', 'uuid'],
            'targets.*.person_user_id' => ['nullable', 'integer'], 'targets.*.include_descendants' => ['boolean'],
        ]);
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::PUBLISH_SCHEDULES);
        $zone = 'Africa/Addis_Ababa';
        $cycle = $cycles->publishAdHoc($actor, [
            ...$data, 'opens_at' => CarbonImmutable::parse($data['opens_at'], $zone)->utc()->toDateTimeString(), 'due_at' => CarbonImmutable::parse($data['due_at'], $zone)->utc()->toDateTimeString(),
        ], $schedules->resolveTargets($actor, $data['targets'], $data['receiving_organization_id'] ?? ReportType::query()->findOrFail($data['report_type_id'])->receiving_organization_id));

        return back()->with('success', __('reporting::messages.flash.published', ['count' => Obligation::query()->where('cycle_id', $cycle->id)->count()]));
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'report_type_id' => ['required', 'uuid'], 'name_en' => ['required', 'string', 'max:200'], 'name_am' => ['required', 'string', 'max:200'],
            ...$this->ruleRules(),
            'review_sla_working_days' => ['required', 'integer', 'min:1', 'max:90'], 'revision_days' => ['required', 'integer', 'min:1', 'max:90'],
            'late_policy' => ['required', Rule::in(['accept', 'accept_with_reason', 'block'])],
            'receiving_organization_id' => ['nullable', 'uuid'],
            'reviewer_user_ids' => ['array', 'max:6'], 'reviewer_user_ids.*' => ['integer'],
            'reminder_rules' => ['array'], 'reminder_rules.days_before' => ['array', 'max:6'], 'reminder_rules.days_before.*' => ['integer', 'min:1', 'max:60'],
            'reminder_rules.on_due_date' => ['boolean'], 'reminder_rules.when_overdue' => ['boolean'], 'reminder_rules.escalate_after_working_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'is_active' => ['boolean'],
            'targets' => ['required', 'array', 'min:1', 'max:'.ManageSchedules::MAX_TARGETS],
            'targets.*.organization_id' => ['required', 'uuid'], 'targets.*.organization_unit_id' => ['nullable', 'uuid'], 'targets.*.person_user_id' => ['nullable', 'integer'],
            'targets.*.preparer_user_id' => ['nullable', 'integer'], 'targets.*.accountable_user_id' => ['nullable', 'integer'], 'targets.*.include_descendants' => ['boolean'],
        ]);
        $targets = $data['targets'];
        unset($data['targets']);

        return [$this->castRules($data), $targets];
    }

    /** @return array<string, mixed> */
    private function ruleRules(): array
    {
        return [
            'frequency' => ['required', Rule::in(['weekly', 'monthly', 'quarterly', 'semiannual', 'annual'])],
            'calendar_basis' => ['required', Rule::in(['ethiopian', 'gregorian'])],
            'fiscal_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'due_offset_days' => ['required', 'integer', 'min:-31', 'max:120'],
            'opens_offset_days' => ['required', 'integer', 'min:-120', 'max:120'],
            'due_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timezone' => ['required', 'timezone'],
            'holiday_adjustment' => ['required', Rule::in(['none', 'next_working_day', 'previous_working_day'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function castRules(array $data): array
    {
        foreach (['fiscal_year_start_month', 'due_offset_days', 'opens_offset_days'] as $key) {
            $data[$key] = (int) $data[$key];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'report_types' => ReportType::query()->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'frequency'])->map->only(['id', 'name_en', 'name_am', 'frequency'])->all(),
            'default_reminders' => ReminderStages::DEFAULT_RULES,
            'ethiopian_months' => ['en' => array_slice(EthiopianDate::MONTHS_EN, 0, 12, true), 'am' => array_slice(EthiopianDate::MONTHS_AM, 0, 12, true)],
        ];
    }
}
