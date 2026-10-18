<?php

declare(strict_types=1);

use App\Models\SituationRoomAction;
use App\Models\SystemSetting;
use App\Services\SituationRoom\SituationCategory;
use App\Services\SituationRoom\SituationPermissions;
use App\Services\SituationRoom\SituationRoomService;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SituationRoomScenario;

/*
 * Metric correctness on known data, the small-group rule, configured
 * thresholds, and that reading the room never writes to a source record.
 */

beforeEach(function (): void {
    // The scenario seeds feedback at the start of the month; from the 15th it would all be "open over 14 days".
    Carbon::setTestNow(now()->startOfMonth()->addDays(5)->setTime(12, 0));
    app()->setLocale('en');
    $this->s = SituationRoomScenario::build();
    $this->viewer = $this->s->viewer(SituationPermissions::all(), 'bureau');
});

afterEach(fn () => Carbon::setTestNow());

/** @return array<string, array<string, mixed>> panels by key */
function srRun(string $category, array $query = []): array
{
    $room = app(SituationRoomService::class);
    $context = $room->context(test()->viewer, Request::create('/', 'GET', $query));

    return collect($room->run($room->analytics(SituationCategory::from($category)), $context)['panels'])->keyBy('key')->all();
}

function srItem(array $panel, string $key): mixed
{
    return collect($panel['data']['items'])->firstWhere('key', $key)['value'] ?? null;
}

function srLog(string $employeeId, string $organizationId, string $date, string $status, bool $late = false): void
{
    DB::table('daily_activity_logs')->insert([
        'id' => (string) Str::uuid7(), 'employee_id' => $employeeId, 'organization_id' => $organizationId, 'activity_date' => $date,
        'status' => $status, 'submitted_at' => $date.' 17:00:00', 'submission_count' => 1, 'is_late' => $late, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('counts the daily register: days, people, reporting rate, review queue', function (): void {
    $a1 = $this->s->people['woreda_a1'];
    $today = now()->toDateString();
    srLog($a1[0]->id, $this->s->org['woreda_a1']->id, $today, 'approved');
    srLog($a1[0]->id, $this->s->org['woreda_a1']->id, now()->startOfMonth()->toDateString(), 'submitted', late: true);
    srLog($a1[1]->id, $this->s->org['woreda_a1']->id, $today, 'under_review');
    srLog($a1[2]->id, $this->s->org['woreda_a1']->id, $today, 'draft');
    srLog($this->s->people['woreda_b1'][0]->id, $this->s->org['woreda_b1']->id, $today, 'returned_for_correction');

    $kpis = srRun('daily_operations')['daily_kpis'];

    expect(srItem($kpis, 'days_reported'))->toBe(4)
        ->and(srItem($kpis, 'people_reporting'))->toBe(3)
        ->and(srItem($kpis, 'reporting_rate'))->toBe(20.0) // 3 of 15
        ->and(srItem($kpis, 'late_days'))->toBe(1)
        ->and(srItem($kpis, 'awaiting_review'))->toBe(2)
        ->and(srItem($kpis, 'returned_for_correction'))->toBe(1);
});

it('counts field work in the field, past return and awaiting approval', function (): void {
    $type = DB::table('field_work_types')->insertGetId(['id' => $typeId = (string) Str::uuid7(), 'code' => 'SR-FW', 'name_en' => 'Inspection', 'name_am' => 'ቁጥጥር', 'created_at' => now(), 'updated_at' => now()]);
    $request = fn (string $org, string $status, string $return) => DB::table('field_work_requests')->insert([
        'id' => (string) Str::uuid7(), 'reference_number' => 'FW-'.Str::random(6), 'requester_employee_id' => $this->s->people[$org][0]->id,
        'organization_id' => $this->s->org[$org]->id, 'field_work_type_id' => $typeId, 'purpose' => 'Inspection', 'destination_type' => 'field_site',
        'starts_at' => now()->subHours(6), 'expected_return_at' => $return, 'schedule_type' => 'partial_day', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $request('woreda_a1', 'in_field', now()->subHours(3)->toDateTimeString());
    $request('woreda_a2', 'in_field', now()->addHours(3)->toDateTimeString());
    $request('woreda_b1', 'pending_supervisor_approval', now()->addDay()->toDateTimeString());

    $kpis = srRun('field_work')['field_kpis'];

    expect(srItem($kpis, 'in_field_now'))->toBe(2)
        ->and(srItem($kpis, 'past_expected_return'))->toBe(1)
        ->and(srItem($kpis, 'awaiting_approval'))->toBe(1);
});

it('splits transfers into inbound, outbound and internal from the viewer\'s scope', function (): void {
    $transfer = function (string $from, string $to): void {
        $employee = $this->s->people[$from][0];
        DB::table('employee_transfers')->insert([
            'id' => (string) Str::uuid7(), 'employee_id' => $employee->id, 'from_organization_id' => $this->s->org[$from]->id,
            'to_organization_id' => $this->s->org[$to]->id, 'current_assignment_id' => $employee->current_assignment_id, 'requested_by' => test()->viewer->id,
            'effective_date' => now()->toDateString(), 'status' => 'completed', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $transfer('woreda_a1', 'woreda_b1'); // out of Subcity A
    $transfer('woreda_b1', 'woreda_a2'); // into Subcity A
    $transfer('woreda_a1', 'woreda_a2'); // within Subcity A
    $this->viewer = $this->s->viewer([SituationPermissions::WORKFORCE], 'subcity_a');

    $kpis = srRun('movement')['movement_kpis'];

    expect(srItem($kpis, 'transfers_inbound'))->toBe(1)
        ->and(srItem($kpis, 'transfers_outbound'))->toBe(1)
        ->and(srItem($kpis, 'transfers_internal'))->toBe(1)
        ->and(srItem($kpis, 'transfers_completed'))->toBe(3);
});

it('reports workforce composition from current, active assignments', function (): void {
    $panels = srRun('workforce');
    $gender = $panels['gender']['data']['series'][0]['values'];

    expect(srItem($panels['workforce_kpis'], 'headcount'))->toBe(15)
        ->and($gender)->toBe([8, 7, 0])
        ->and(srItem($panels['workforce_kpis'], 'female_share'))->toBe(46.7)
        // Six positions without an establishment hold one person each; the rest are above capacity.
        ->and(srItem($panels['workforce_kpis'], 'capacity'))->toBe(6)
        ->and(srItem($panels['workforce_kpis'], 'over_capacity'))->toBe(9);
});

it('hides small disciplinary counts behind "fewer than k"', function (): void {
    foreach (range(1, 2) as $i) {
        DB::table('disciplinary_cases')->insert(['id' => (string) Str::uuid7(), 'accused_employee_id' => $this->s->people['woreda_a1'][$i - 1]->id, 'organization_id' => $this->s->org['woreda_a1']->id, 'track' => 'formal', 'severity' => 'serious', 'status' => 'charged', 'charged_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    $panels = srRun('cases');
    $status = $panels['discipline_status']['data']['series'][0]['values'];

    expect($panels['discipline_status']['type'])->toBe('bar') // withheld, not "nothing recorded"
        ->and($status[0])->toBeNull() // 2 charged cases < k = 5
        ->and(json_encode($panels))->not->toContain('Person ');
});

it('counts nothing for an attention rule until a threshold is configured', function (): void {
    expect(srRun('critical_attention')['attention_complaint_open_days']['reason'])->toBe('CONFIGURATION_REQUIRED');

    DB::table('employee_service_feedback')->where('organization_id', $this->s->org['woreda_b1']->id)->update(['submitted_at' => now()->subDays(20)]);
    app(SystemSettingsService::class)->clearCache();
    SystemSetting::query()->create(['group' => 'situation_room', 'key' => 'complaint_open_days', 'value' => '14', 'type' => 'integer']);
    app(SystemSettingsService::class)->clearCache();
    Cache::flush();

    $panel = srRun('critical_attention')['attention_complaint_open_days'];
    expect($panel['type'])->toBe('hbar')
        ->and(array_sum($panel['data']['series'][0]['values']))->toBe(4);
});

it('never writes to a source record while reading', function (): void {
    $before = [
        DB::table('employee_service_feedback')->orderBy('id')->get()->toJson(),
        DB::table('employees')->orderBy('id')->get()->toJson(),
        DB::table('employee_assignments')->orderBy('id')->get()->toJson(),
    ];

    foreach (SituationCategory::cases() as $category) {
        srRun($category->value);
    }

    expect([
        DB::table('employee_service_feedback')->orderBy('id')->get()->toJson(),
        DB::table('employees')->orderBy('id')->get()->toJson(),
        DB::table('employee_assignments')->orderBy('id')->get()->toJson(),
    ])->toBe($before)->and(SituationRoomAction::query()->count())->toBe(0);
});
