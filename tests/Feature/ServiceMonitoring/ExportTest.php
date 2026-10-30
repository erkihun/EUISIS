<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\ServiceMonitoring\Application\Actions\SaveSection1Entry;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Domain\Enums\FormType;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Models\MonitoringExport;
use Modules\ServiceMonitoring\Infrastructure\Exports\OfficialFormLayout;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ServiceMonitoringScenario;

/*
 * Exports (D25): the official layouts of Form A and Form B, masking without
 * export_named, the queued job, the download and the audit trail.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->s = ServiceMonitoringScenario::build();
    $this->s->at(1);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function smWithEntry(ServiceMonitoringScenario $s, string $key): void
{
    $submission = $s->start($key);
    app(SaveSection1Entry::class)->execute($submission, [
        'subject_type' => 'customer', 'full_name' => 'Named Customer', 'role_category' => 'customer', 'reason_category' => 'theft_corruption',
        'reason_detail' => 'Offered a bribe at the counter.', 'final_action' => 'legal_action', 'action_detail' => 'Handed to the police.', 'action_date' => '2026-08-15',
        'office_name' => 'Land office',
    ], $s->head($key));
    $s->accept($s->submit($s->complete($submission, nothing1: false)));
}

it('lays out Form B with the woreda table, its remark heading, the I.2 groups and totals', function (): void {
    smWithEntry($this->s, 'wA1');
    $this->s->accepted('subA', 1, ['led_count' => 4]);
    $report = app(ConsolidationService::class)->report($this->s->year, ReportRange::Quarter, 1, null, false);
    $sections = OfficialFormLayout::sections(FormType::B, 'fy2019', 2019, $report, false, 'am');

    [$subCityTable, $woredaTable] = $sections['section_1'];
    expect($subCityTable['subtitle'])->toBe('በክ/ከተማ ደረጃ ብቻ')
        ->and($woredaTable['subtitle'])->toBe('በወረዳ ደረጃ ብቻ')
        ->and(array_column($woredaTable['header'][0], 'text'))->toContain('ተ/ቁ', 'ወረዳ', 'ምርመራ/ጽ/ቤት ይጠቀስ/', 'ተጠያቂ የተደረገው ስራ ድርሻ (X)')
        ->and(array_column($woredaTable['header'][1], 'text'))->toBe(['ሹመኛ አመራር', 'ሜሪት አመራር /ዳይሬክተር ቡድን መሪ/', 'ባለሙያ /ሰራተኛ/', 'ተገልጋይ'])
        // Masked: the role label instead of the name, the categories instead of the details; X under the role.
        ->and($woredaTable['body'][0][2])->toBe('ተገልጋይ')
        ->and($woredaTable['body'][0][3])->toBe('ሌብነት')
        ->and($woredaTable['body'][0][8])->toBe('X')
        ->and($subCityTable['body'][0][1])->toBe('የለም');

    $summary = $sections['summary'][0];
    expect(array_column($summary['header'][0], 'text'))->toBe(['ተ/ቁ', 'የተወሰደ እርምጃ', 'ክ/ከተማ', 'ወረዳ', 'ምርመራ'])
        ->and($summary['header'][0][2]['colspan'])->toBe(5)
        ->and($summary['header'][1][0]['text'])->toBe('ሹመኛ አመራር')
        ->and(count($summary['body']))->toBe(8)
        ->and($summary['body'][5][1])->toBe('የህግ እርምጃ/ፍርድ የተሰጠበት')
        ->and($summary['body'][5][10])->toBe(1)
        ->and($summary['body'][7][1])->toBe('ድምር')
        ->and($summary['total_rows'])->toBe([7]);

    $section2 = $sections['section_2'][0];
    expect(collect($section2['header'][0])->firstWhere('text', 'የባለጉዳይ ማረፍያ')['colspan'])->toBe(2)
        // Sub-city rows, their subtotal, woreda rows, their subtotal, then the total.
        ->and(array_column($section2['body'], 1))->toBe(['ቦሌ ክ/ከተማ', 'ድምር — ክ/ከተማ', 'ቦሌ ወረዳ 1', 'ድምር — ወረዳ', 'ድምር'])
        ->and($section2['notes'][0])->toContain('ካፍቴሪያ፣ ሸማችና ህጻናት ማቆያ');
});

it('prints the Form A summary header and no newly-organized note', function (): void {
    $this->s->accepted('center');
    $report = app(ConsolidationService::class)->report($this->s->year, ReportRange::Quarter, 1, null, false);
    $sections = OfficialFormLayout::sections(FormType::A, 'fy2019', 2019, $report, false, 'am');

    expect($sections['section_1'])->toHaveCount(1)
        ->and($sections['section_1'][0]['subtitle'])->toBe('በማዕከል ደረጃ ብቻ')
        ->and($sections['summary'][0]['header'][1][0]['text'])->toBe('ሹመኛ/ሜሪት+ሹመት/')
        ->and(array_column($sections['summary'][0]['header'][0], 'text'))->toBe(['ተ/ቁ', 'የተወሰደ እርምጃ', 'ማዕከል', 'ምርመራ'])
        ->and($sections['section_2'][0]['notes'])->toBe([])
        ->and($sections['section_4'][0]['body'][0][1])->toBe('ንግድ ቢሮ');
});

it('builds the XLSX on the queue with names only under export_named, and audits request, build and download', function (): void {
    smWithEntry($this->s, 'wA1');
    $coordinator = $this->s->coordinator();
    app()->setLocale('am');

    $this->actingAs($coordinator)->post(route('service-monitoring.exports.store'), [
        'scope_type' => 'city', 'fiscal_year_id' => $this->s->year->id, 'quarter' => 1, 'range' => 'quarter', 'format' => 'xlsx', 'named' => true,
    ])->assertSessionHasNoErrors();
    $named = MonitoringExport::query()->sole();
    expect($named->status->value)->toBe('ready', (string) $named->error)->and($named->named)->toBeTrue();

    $sheet = IOFactory::load(Storage::disk('local')->path($named->path))->getSheet(0);
    $text = collect($sheet->toArray())->flatten()->filter()->implode('|');
    expect($text)->toContain('Named Customer')->toContain('በወረዳ ደረጃ ብቻ');
    expect(IOFactory::load(Storage::disk('local')->path($named->path))->getSheetCount())->toBe(6);

    // Without export_named the request's flag is dropped.
    $reviewer = $this->s->reviewer('subA');
    $this->actingAs($reviewer)->post(route('service-monitoring.exports.store'), [
        'scope_type' => 'sub_city', 'scope_id' => $this->s->p['subA']->id, 'fiscal_year_id' => $this->s->year->id, 'quarter' => 1, 'range' => 'quarter', 'format' => 'xlsx', 'named' => true,
    ])->assertSessionHasNoErrors();
    $masked = MonitoringExport::query()->where('requested_by', $reviewer->id)->sole();
    $maskedText = collect(IOFactory::load(Storage::disk('local')->path($masked->path))->getSheet(0)->toArray())->flatten()->filter()->implode('|');
    expect($masked->named)->toBeFalse()->and($maskedText)->not->toContain('Named Customer')->toContain('ተገልጋይ');

    // Only the requester downloads, as an attachment; every step is audited.
    $this->actingAs($reviewer)->get(route('service-monitoring.exports.download', $named))->assertForbidden();
    $this->actingAs($coordinator)->get(route('service-monitoring.exports.download', $named))->assertOk()->assertHeader('content-disposition');
    foreach (['service_monitoring.export_requested', 'service_monitoring.exported', 'service_monitoring.export_downloaded'] as $event) {
        expect(AuditLog::query()->where('event_type', $event)->exists())->toBeTrue($event);
    }
});

it('renders the PDF of a single submission', function (): void {
    $submission = $this->s->accepted('center');
    $this->actingAs($this->s->coordinator())->post(route('service-monitoring.exports.store'), [
        'scope_type' => 'submission', 'scope_id' => $submission->id, 'fiscal_year_id' => $this->s->year->id, 'quarter' => 1, 'range' => 'quarter', 'format' => 'pdf',
    ])->assertSessionHasNoErrors();

    $export = MonitoringExport::query()->sole();
    expect($export->status->value)->toBe('ready')
        ->and(substr((string) Storage::disk('local')->get($export->path), 0, 4))->toBe('%PDF');
});
