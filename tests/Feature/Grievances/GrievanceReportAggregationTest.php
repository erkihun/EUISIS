<?php

declare(strict_types=1);

use App\Models\Grievance;
use App\Models\GrievanceCaseStage;
use App\Models\User;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Grievances\GrievanceReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\GrievanceScenario;

// Risk register SCL-10: resolution time, SLA compliance and trend are
// aggregated in SQL, not by loading every case or stage into PHP.

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    Storage::fake('local');
    $this->s = GrievanceScenario::build();

    $this->actingAs($this->s->complainant)->post(route('employee.grievances.store'), [
        'subject' => 'Overtime not paid',
        'description' => 'Narrative.',
        'category_id' => $this->s->category->id,
        'submit' => true,
        'files' => [UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')],
        'file_types' => ['document'],
        ...GrievanceScenario::application(),
    ])->assertRedirect();
    $this->base = Grievance::query()->latest('created_at')->firstOrFail();
    app(GrievanceCaseService::class)->intakeAccept($this->base, $this->s->intake, null);
    $this->base->refresh();

    Role::findOrCreate('Super Admin', 'web');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

afterEach(fn () => Carbon::setTestNow());

/** A copy of the filed case with its own reference and dates. */
function reportCase(Grievance $base, string $submitted, ?string $resolved, bool $systemic = false): Grievance
{
    static $n = 0;
    $n++;
    $case = $base->replicate();
    $case->forceFill([
        'reference_number' => 'RPT-'.$n,
        'submitted_at' => Carbon::parse($submitted),
        'resolved_at' => $resolved ? Carbon::parse($resolved) : null,
        'systemic_issue_flag' => $systemic,
    ])->save();

    return $case;
}

it('reports resolution time with an exact median from SQL', function (): void {
    $this->base->forceFill(['submitted_at' => Carbon::parse('2026-08-01 09:00'), 'resolved_at' => Carbon::parse('2026-08-03 09:00')])->save();
    reportCase($this->base, '2026-08-10 09:00', '2026-08-14 09:00');
    reportCase($this->base, '2026-09-02 09:00', '2026-09-12 09:00');
    reportCase($this->base, '2026-09-05 09:00', '2026-09-08 21:00');
    reportCase($this->base, '2026-09-06 09:00', null);

    $odd = app(GrievanceReportService::class)->run('resolution_time', $this->admin, ['to' => '2026-09-03']);
    expect($odd['rows'][0])->toBe(['resolved_cases' => 3, 'average_days' => 5.3, 'median_days' => 4.0, 'max_days' => 10.0]);

    $even = app(GrievanceReportService::class)->run('resolution_time', $this->admin, []);
    expect($even['rows'][0])->toBe(['resolved_cases' => 4, 'average_days' => 4.9, 'median_days' => 3.8, 'max_days' => 10.0]);
});

it('reports SLA compliance per handler type from SQL', function (): void {
    GrievanceCaseStage::query()->where('grievance_id', $this->base->id)->update(['due_at' => null]);
    $stage = GrievanceCaseStage::query()->where('grievance_id', $this->base->id)->firstOrFail();
    $no = 10;
    $make = function (string $status, string $due, string $completed) use ($stage, &$no): void {
        $copy = $stage->replicate();
        $copy->forceFill(['stage_no' => ++$no, 'is_current' => false, 'status' => $status, 'due_at' => Carbon::parse($due), 'completed_at' => Carbon::parse($completed)])->save();
    };
    $make('resolved', '2026-08-05 17:00', '2026-08-03 10:00');
    $make('resolved', '2026-08-05 17:00', '2026-08-07 10:00');
    $make('escalated', '2026-08-05 17:00', '2026-08-06 10:00');

    $result = app(GrievanceReportService::class)->run('sla_compliance', $this->admin, []);

    expect($result['rows'])->toBe([[
        'handler_type' => $stage->handler_type->value, 'stages' => 3, 'on_time' => 1,
        'late_or_escalated' => 2, 'compliance_percent' => 33.3,
    ]]);
});

it('reports the monthly trend per category from SQL and suppresses small groups', function (): void {
    $this->base->forceFill(['submitted_at' => Carbon::parse('2026-08-01 09:00')])->save();
    foreach (range(1, 4) as $i) {
        reportCase($this->base, '2026-08-1'.$i.' 09:00', null, $i <= 2);
    }
    foreach (range(1, 2) as $i) {
        reportCase($this->base, '2026-09-1'.$i.' 09:00', null);
    }

    $result = app(GrievanceReportService::class)->run('trend', $this->admin, []);

    expect($result['rows'])->toBe([[
        'month' => '2026-08', 'category' => $this->s->category->name_en, 'category_am' => $this->s->category->name_am,
        'total' => 5, 'systemic_flagged' => 2,
    ]])->and($result['suppressed'])->toBe(1);
});
