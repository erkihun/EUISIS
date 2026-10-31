{{-- resources/views/pdf/appeal-bundle.blade.php
     The record a party takes to the appellate court (Modules\Court\Application\Services\AppealRecordBundle,
     EUISIS ADR 0013 #14): the EUISIS proceeding, the case at this court, the decision,
     the appeal and the index of the annexed documents, in the order things happened. --}}
@php
    use Illuminate\Support\Carbon;
    use Modules\Court\Application\Support\Purifier;

    $t = fn (string $key, array $replace = []) => __('court::appeal_records.bundle.'.$key, $replace);
    $na = __('court::recordes.messages.not_available');
    $fmt = static function ($value, bool $withTime = false) use ($na) {
        if (empty($value)) return $na;
        $c = $value instanceof Carbon ? $value : Carbon::parse($value);
        $greg = $withTime ? $c->format('Y-m-d H:i') : $c->toDateString();
        $eth = class_exists(\Modules\Court\Application\Support\EthiopianDate::class) ? \Modules\Court\Application\Support\EthiopianDate::format($c, withTime: $withTime) : '';
        return $eth ? ($greg.' ('.$eth.')') : $greg;
    };
    $html = fn (?string $value) => \Modules\Court\Application\Support\Purifier::clean((string) $value, 'default');
    $section = 0;
    $next = function () use (&$section) { return ++$section; };
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $t('title') }} — {{ $case->case_number }}</title>
    <style>
        @page { margin: 26mm 16mm 20mm 16mm; }
        {{-- One face for every style: the font has no italic or bold of its own, and a missing style would
             fall back to DejaVu, which has no Ethiopic letters (they printed as boxes). --}}
        @foreach ([['normal', 'normal'], ['italic', 'normal'], ['normal', 'bold'], ['italic', 'bold']] as [$style, $weight])
        @font-face {
            font-family: 'Abyssinica';
            src: url('{{ public_path('fonts/AbyssinicaSIL-Regular.ttf') }}') format('truetype');
            font-style: {{ $style }}; font-weight: {{ $weight }};
        }
        @endforeach
        * { font-family: 'Abyssinica', 'DejaVu Serif', serif; }
        body { color: #0f172a; font-size: 10.5pt; line-height: 1.5; }
        .page-header { position: fixed; top: -18mm; left: 0; right: 0; height: 12mm; border-bottom: 1px solid #cbd5e1; font-size: 8.5pt; color: #475569; }
        .page-header .l { float: left; } .page-header .r { float: right; }
        .page-footer { position: fixed; bottom: -13mm; left: 0; right: 0; height: 9mm; border-top: 1px solid #cbd5e1; padding-top: 1.5mm; font-size: 8pt; color: #64748b; text-align: center; }
        .cover { text-align: center; padding-top: 30mm; }
        .cover h1 { font-size: 20pt; margin: 0 0 4mm; }
        .cover .number { font-size: 14pt; margin-bottom: 10mm; }
        .cover table { margin: 0 auto; text-align: left; font-size: 10.5pt; }
        .cover td { padding: 1.5mm 4mm; }
        h2 { font-size: 13pt; margin: 0 0 3mm; border-bottom: 2px solid #0f172a; padding-bottom: 1.5mm; }
        h3 { font-size: 11pt; margin: 3mm 0 1.5mm; }
        .section { margin: 0 0 6mm; }
        .break { page-break-before: always; }
        .card { border: 1px solid #e2e8f0; border-radius: 3px; padding: 2.5mm 3mm; margin-bottom: 2.5mm; background: #f8fafc; }
        .meta { font-size: 9pt; color: #475569; }
        .kv { width: 100%; border-collapse: collapse; }
        .kv td { padding: 1.2mm 2mm; vertical-align: top; border-bottom: 1px solid #eef2f7; }
        .kv td.k { width: 42mm; color: #475569; }
        .list { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        .list th, .list td { border: 1px solid #cbd5e1; padding: 1.5mm 2mm; text-align: left; vertical-align: top; }
        .list th { background: #f1f5f9; }
        .empty { font-size: 9.5pt; color: #64748b; }
        .badge { display: inline-block; border: 1px solid #94a3b8; border-radius: 3px; padding: 0 2mm; font-size: 8.5pt; }
    </style>
</head>
<body>
    <div class="page-header"><span class="l">{{ $case->case_number }}</span><span class="r">{{ $t('title') }}</span></div>
    <div class="page-footer">{{ $t('generated', ['date' => $fmt($generatedAt, true)]) }}</div>

    {{-- Cover --}}
    <div class="cover">
        <h1>{{ $t('title') }}</h1>
        <div class="number">{{ $case->case_number }}</div>
        <table>
            <tr><td>{{ $t('applicant') }}</td><td>{{ $parties->pluck('name')->join(', ') ?: ($case->title ?: $na) }}</td></tr>
            <tr><td>{{ $t('respondent') }}</td><td>{{ $case->respondent_name ?: $na }}</td></tr>
            <tr><td>{{ $t('case_type') }}</td><td>{{ $case->caseType?->name ?? $na }}</td></tr>
            <tr><td>{{ $t('filed') }}</td><td>{{ $fmt($case->filing_date) }}</td></tr>
            <tr><td>{{ $t('decided') }}</td><td>{{ $decision ? $fmt($decision->decision_date ?? $decision->created_at) : $na }}</td></tr>
        </table>
        <p class="meta" style="margin-top: 14mm">{{ $t('cover_note') }}</p>
    </div>

    {{-- 1. Where the case started: the EUISIS grievance or discipline proceeding --}}
    <div class="section break">
        <h2>{{ $next() }}. {{ $t('origin') }}</h2>
        @forelse($origins as $origin)
            <div class="card">
                <div><strong>{{ $t('origin_'.($origin->kind ?? 'grievance')) }}: {{ $origin->reference ?: $na }}</strong> — {{ $origin->subject }}</div>
                <div class="meta">
                    @if($origin->category){{ $origin->category }} · @endif
                    {{ $t('opened') }} {{ $fmt($origin->submitted_at) }} · {{ $t('closed') }} {{ $origin->closed_at ? $fmt($origin->closed_at) : $na }}
                    @if($origin->status) · {{ $origin->status }} @endif
                </div>
                @if($origin->description)<p style="white-space: pre-line">{{ $origin->description }}</p>@endif
                @if(! empty($origin->responses))
                    <h3>{{ $t('origin_responses') }}</h3>
                    @foreach($origin->responses as $response)
                        <p><span class="meta">{{ $response['at'] ?? '' }}</span><br><span style="white-space: pre-line">{{ $response['body'] ?? '' }}</span></p>
                    @endforeach
                @endif
                @if(! empty($origin->decisions))
                    <h3>{{ $t('origin_decisions') }}</h3>
                    @foreach($origin->decisions as $item)
                        <p><strong>{{ trim(($item['number'] ?? '').' '.($item['type'] ?? '')) }}</strong> <span class="meta">{{ $item['at'] ?? '' }}</span><br><span style="white-space: pre-line">{{ $item['text'] ?? '' }}</span></p>
                    @endforeach
                @endif
            </div>
        @empty
            <p class="empty">{{ $t('no_origin') }}</p>
        @endforelse
    </div>

    {{-- 2. The case at this court --}}
    <div class="section">
        <h2>{{ $next() }}. {{ $t('the_case') }}</h2>
        <table class="kv">
            <tr><td class="k">{{ $t('case_number') }}</td><td>{{ $case->case_number }}</td></tr>
            <tr><td class="k">{{ $t('case_type') }}</td><td>{{ $case->caseType?->name ?? $na }}</td></tr>
            <tr><td class="k">{{ $t('filed') }}</td><td>{{ $fmt($case->filing_date) }}</td></tr>
            <tr><td class="k">{{ $t('closed') }}</td><td>{{ $closedAt ? $fmt($closedAt) : $na }}</td></tr>
            <tr><td class="k">{{ $t('respondent') }}</td><td>{{ $case->respondent_name ?: $na }}@if($case->respondent_address), {{ $case->respondent_address }}@endif</td></tr>
        </table>
        <h3>{{ $t('parties') }}</h3>
        <table class="list">
            <tr><th>{{ $t('name') }}</th><th>{{ $t('role') }}</th><th>{{ $t('lawyer') }}</th></tr>
            @foreach($parties as $party)
                <tr><td>{{ $party->name }}</td><td>{{ __('court::case_parties.panel.roles.'.$party->role) }}</td><td>{{ implode(', ', $party->lawyers) ?: '—' }}</td></tr>
            @endforeach
        </table>
        @if($case->description)
            <h3>{{ $t('claim') }}</h3>
            <div>{!! $html($case->description) !!}</div>
        @endif
        @if($case->relief_requested)
            <h3>{{ $t('relief') }}</h3>
            <div>{!! $html($case->relief_requested) !!}</div>
        @endif
    </div>

    {{-- 3. The respondent's answers and the applicant's replies --}}
    <div class="section">
        <h2>{{ $next() }}. {{ $t('pleadings') }}</h2>
        @forelse($responses as $response)
            <div class="card">
                <div><strong>{{ $response->title ?: $t('response') }}</strong> <span class="meta">{{ $response->response_number }} · {{ $fmt($response->created_at) }}</span></div>
                @if($response->description)<div>{!! $html($response->description) !!}</div>@endif
                @foreach($replies->where('respondent_response_id', $response->id) as $reply)
                    <div class="card" style="margin: 2mm 0 0 6mm; background: #fff">
                        <div class="meta"><strong>{{ $t('reply') }}</strong> · {{ $fmt($reply->created_at) }}</div>
                        <div>{!! $html($reply->description) !!}</div>
                    </div>
                @endforeach
            </div>
        @empty
            <p class="empty">{{ $t('no_pleadings') }}</p>
        @endforelse
    </div>

    {{-- 4. Hearings and the bench's signed notes --}}
    <div class="section">
        <h2>{{ $next() }}. {{ $t('hearings') }}</h2>
        @forelse($hearings as $hearing)
            <div class="card">
                <div><strong>{{ $fmt($hearing->hearing_at, true) }}</strong> @if($hearing->type)· {{ $hearing->type }}@endif @if($hearing->location)· {{ $hearing->location }}@endif</div>
                @if($hearing->notes)<div>{!! $html($hearing->notes) !!}</div>@endif
            </div>
        @empty
            <p class="empty">{{ $t('no_hearings') }}</p>
        @endforelse
        @if($benchNotes->isNotEmpty())
            <h3>{{ $t('bench_notes') }}</h3>
            @foreach($benchNotes as $note)
                <div class="card">
                    <div><strong>{{ $note->title ?: $t('bench_note') }}</strong> <span class="meta">{{ $fmt($note->note_date ?? $note->created_at) }}</span></div>
                    <div class="meta">{{ collect([$note->judge_one_name, $note->judge_two_name, $note->judge_three_name])->filter()->join(' · ') }}</div>
                    <div>{!! $html($note->note) !!}</div>
                </div>
            @endforeach
        @endif
    </div>

    {{-- 5. Witnesses and letters --}}
    <div class="section">
        <h2>{{ $next() }}. {{ $t('witnesses_letters') }}</h2>
        @if($witnesses->isNotEmpty())
            <table class="list">
                <tr><th>{{ $t('name') }}</th><th>{{ $t('phone') }}</th><th>{{ $t('address') }}</th></tr>
                @foreach($witnesses as $witness)
                    <tr><td>{{ $witness->full_name }}</td><td>{{ $witness->phone ?: '—' }}</td><td>{{ $witness->address ?: '—' }}</td></tr>
                @endforeach
            </table>
        @else
            <p class="empty">{{ $t('no_witnesses') }}</p>
        @endif
        @foreach($letters as $letter)
            <div class="card">
                <div><strong>{{ $letter->subject ?: $letter->reference_number }}</strong> <span class="meta">{{ $letter->reference_number }} · {{ $fmt($letter->created_at) }}</span></div>
                <div>{!! $html($letter->body) !!}</div>
            </div>
        @endforeach
    </div>

    {{-- 6. The decision --}}
    <div class="section">
        <h2>{{ $next() }}. {{ $t('decision') }}</h2>
        @if($decision)
            <div class="meta">{{ $decision->name }} · {{ $fmt($decision->decision_date ?? $decision->created_at) }}</div>
            <div>{!! $html($decision->decision_content) !!}</div>
            @if(is_array($decision->panel_judges) && $decision->panel_judges !== [])
                <p class="meta">{{ $t('panel') }}: {{ collect($decision->panel_judges)->pluck('admin_user_name')->filter()->join(' · ') }}</p>
            @endif
        @else
            <p class="empty">{{ $t('no_decision') }}</p>
        @endif
    </div>

    {{-- 7. The appeal --}}
    <div class="section">
        <h2>{{ $next() }}. {{ $t('appeal') }}</h2>
        @forelse($appeals as $appeal)
            <table class="kv">
                <tr><td class="k">{{ $t('appeal_number') }}</td><td>{{ $appeal->appeal_number }}</td></tr>
                <tr><td class="k">{{ $t('appellant') }}</td><td>{{ $appeal->appellant_name }} ({{ __('court::appeal_records.sides.'.$appeal->appellant_type) }})</td></tr>
                <tr><td class="k">{{ $t('requested') }}</td><td>{{ $fmt($appeal->submitted_at) }}</td></tr>
                <tr><td class="k">{{ $t('released') }}</td><td>{{ $fmt($appeal->released_at) }} @if($appeal->released_by)· {{ $appeal->released_by }}@endif</td></tr>
                @if($appeal->grounds)<tr><td class="k">{{ $t('grounds') }}</td><td style="white-space: pre-line">{{ $appeal->grounds }}</td></tr>@endif
                @if($appeal->outcome)
                    <tr><td class="k">{{ $t('outcome') }}</td><td>{{ __('court::appeal_records.outcomes.'.$appeal->outcome) }} · {{ $fmt($appeal->outcome_date) }} @if($appeal->appellate_court)· {{ $appeal->appellate_court }}@endif @if($appeal->appellate_case_number)· {{ $appeal->appellate_case_number }}@endif</td></tr>
                @endif
            </table>
        @empty
            <p class="empty">{{ $t('no_appeal') }}</p>
        @endforelse
    </div>

    {{-- 8. The annexed documents --}}
    <div class="section break">
        <h2>{{ $next() }}. {{ $t('annexes') }}</h2>
        @if(count($annexes) > 0)
            <table class="list">
                <tr><th>#</th><th>{{ $t('document') }}</th><th>{{ $t('stage') }}</th><th>{{ $t('date') }}</th><th>{{ $t('page') }}</th></tr>
                @foreach($annexes as $i => $annex)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $annex['title'] }}</td>
                        <td>{{ $t('stages.'.$annex['stage']) }}</td>
                        <td>{{ $annex['date'] ?? '—' }}</td>
                        <td>{{ ($annex['page'] ?? null) ? trans_choice('court::appeal_records.bundle.from_page', (int) $annex['pages'], ['page' => $annex['page'], 'count' => $annex['pages']]) : $t('separately') }}</td>
                    </tr>
                @endforeach
            </table>
        @else
            <p class="empty">{{ $t('no_annexes') }}</p>
        @endif
    </div>
</body>
</html>
