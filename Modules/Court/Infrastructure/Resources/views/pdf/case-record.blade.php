{{-- The case record as a PDF (RecordController::pdf, dompdf): the record page's eight sections in
     the same order and with the same data, laid out as an A4 court document. dompdf has no flexbox
     or grid, so the layout is tables only. "Page x of y" is stamped by the controller. --}}
@php
    use Illuminate\Support\Carbon;
    use Modules\Court\Application\Support\EthiopianDate;

    $b = fn (string $key, array $replace = []) => __('court::appeal_records.bundle.'.$key, $replace);
    $r = fn (string $key, array $replace = []) => __('court::recordes.pdf.'.$key, $replace);
    $na = __('court::recordes.messages.not_available');
    $dash = '—';
    // Gregorian first, the Ethiopian date after it, as on the appeal record.
    $fmt = static function ($value, bool $withTime = false) use ($dash) {
        if (empty($value)) return $dash;
        $c = $value instanceof Carbon ? $value : Carbon::parse($value);
        $greg = $withTime ? $c->format('Y-m-d H:i') : $c->toDateString();
        $eth = EthiopianDate::format($c, withTime: $withTime);
        return $eth ? $greg.' ('.$eth.')' : $greg;
    };
    // The same, with the Ethiopian date on its own muted line: for the narrow cells of tables.
    $fmtCell = static function ($value, bool $withTime = false) use ($dash) {
        if (empty($value)) return e($dash);
        $c = $value instanceof Carbon ? $value : Carbon::parse($value);
        $greg = $withTime ? $c->format('Y-m-d H:i') : $c->toDateString();
        $eth = EthiopianDate::format($c, withTime: $withTime);
        return e($greg).($eth ? '<br><span class="small muted">'.e($eth).'</span>' : '');
    };
    $status = fn (?string $value) => $value ? __('court::cases.status.'.$value) : $na;
    $sections = [
        'origin' => $b('origin'),
        'case' => $b('the_case'),
        'pleadings' => $b('pleadings'),
        'hearings' => $b('hearings'),
        'witnesses' => $b('witnesses_letters'),
        'decision' => $b('decision'),
        'appeal' => $b('appeal'),
        'documents' => $b('annexes'),
    ];
    $number = array_flip(array_keys($sections));
    $documents = collect($evidences)->map(fn ($e) => ['title' => $e['title'], 'at' => $e['created_at'], 'stage' => 'filing'])
        ->concat(collect($submittedDocuments)->map(fn ($d) => ['title' => $d['label'], 'at' => $d['created_at'], 'stage' => 'filing']))
        ->concat(collect($otherDocuments)->map(fn ($d) => ['title' => $d['label'], 'at' => $d['created_at'], 'stage' => 'proceedings']))
        ->values();
    $repliesFor = fn (int $responseId) => collect($replies)->where('response_id', $responseId);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('court::recordes.titles.record') }} — {{ $case['case_number'] }}</title>
    {{-- The platform's embedded document fonts (Abyssinica SIL for every style, so Ethiopic never falls back to DejaVu). --}}
    @include('pdf.partials.typography', ['variant' => 'formal'])
    <style>
        @page { margin: 24mm 16mm 22mm 16mm; }
        body { color: #0f172a; font-size: 10pt; line-height: 1.45; margin: 0; }
        p { margin: 0 0 1.6mm; }

        .running-head { position: fixed; top: -15mm; left: 0; right: 0; height: 9mm; border-bottom: 0.6pt solid #cbd5e1; font-size: 8pt; color: #64748b; }
        .running-head td { padding: 0; vertical-align: bottom; padding-bottom: 1.5mm; }
        .running-foot { position: fixed; bottom: -13mm; left: 0; right: 0; height: 8mm; border-top: 0.6pt solid #cbd5e1; padding-top: 1.6mm; font-size: 8pt; color: #64748b; }

        table { border-collapse: collapse; width: 100%; }
        .w-full { width: 100%; }
        .right { text-align: right; }
        .muted { color: #64748b; }
        .small { font-size: 8.5pt; }
        .mono { letter-spacing: 0.2pt; }

        .title-block { border-top: 3pt solid #1e3a8a; padding-top: 4mm; margin-bottom: 5mm; }
        .eyebrow { font-size: 8.5pt; color: #1e3a8a; letter-spacing: 1pt; text-transform: uppercase; }
        .case-number { font-size: 19pt; font-weight: bold; margin: 1mm 0 0.5mm; }
        .case-title { font-size: 11pt; color: #334155; }
        .chip { display: inline-block; border: 0.6pt solid #94a3b8; border-radius: 2mm; padding: 0.4mm 2.4mm; font-size: 8.5pt; color: #334155; background: #f8fafc; }
        .chip-warn { border-color: #d97706; color: #92400e; background: #fffbeb; }

        .facts td { width: 25%; vertical-align: top; padding: 2.2mm 2.6mm; border: 0.6pt solid #e2e8f0; }
        .facts .k { font-size: 7.5pt; color: #64748b; text-transform: uppercase; letter-spacing: 0.4pt; }
        .facts .v { font-size: 9.5pt; margin-top: 0.6mm; }

        .contents { margin: 5mm 0 2mm; border: 0.6pt solid #e2e8f0; background: #f8fafc; }
        .contents td { padding: 1.2mm 3mm; font-size: 9pt; }
        .contents .head { font-size: 7.5pt; color: #64748b; text-transform: uppercase; letter-spacing: 0.6pt; padding-top: 2.4mm; }
        .contents .n { width: 7mm; color: #1e3a8a; font-weight: bold; }

        .section { margin-top: 7mm; }
        .section-head td { padding: 1.2mm 0; border-bottom: 1.2pt solid #1e3a8a; vertical-align: middle; }
        .section-head td.n { width: 7mm; background: #1e3a8a; color: #fff; text-align: center; font-family: "DejaVu Sans", sans-serif; font-size: 9pt; font-weight: bold; }
        .section-head td.gap { width: 3mm; }
        .section-head .t { font-size: 13pt; color: #1e3a8a; }
        .section-body { padding-top: 3mm; }
        .break { page-break-before: always; }

        h3 { font-size: 10.5pt; margin: 3.5mm 0 1.5mm; color: #1e3a8a; font-weight: normal; }
        .entry { border-left: 1.6pt solid #cbd5e1; padding: 0.6mm 0 0.6mm 3mm; margin: 0 0 3.5mm; }
        .entry.accent { border-left-color: #1e3a8a; }
        .entry .head td { padding: 0; vertical-align: top; }
        .entry .head .when { width: 48mm; text-align: right; font-size: 8.5pt; color: #64748b; }
        .entry .title { font-weight: bold; }
        .reply { margin: 2mm 0 0 5mm; padding: 1.6mm 2.6mm; background: #f8fafc; border: 0.6pt solid #e2e8f0; }
        .rich table { width: 100%; border-collapse: collapse; }
        .rich th, .rich td { border: 0.6pt solid #cbd5e1; padding: 1mm 1.6mm; }
        .rich p { margin: 0 0 1.6mm; }

        .grid th { background: #f1f5f9; font-size: 8pt; color: #475569; text-transform: uppercase; letter-spacing: 0.3pt; text-align: left; font-weight: bold; }
        .grid th, .grid td { border: 0.6pt solid #e2e8f0; padding: 1.6mm 2.2mm; vertical-align: top; }
        .grid td.num { width: 7mm; color: #64748b; text-align: right; }
        .kv td { padding: 1.4mm 2mm; border-bottom: 0.6pt solid #eef2f7; vertical-align: top; }
        .kv td.k { width: 40mm; color: #64748b; }

        .empty { font-size: 9pt; color: #94a3b8; font-style: italic; }
        .signatures td { width: 33%; padding: 14mm 4mm 0; text-align: center; vertical-align: bottom; font-size: 9pt; }
        .signatures .line { border-top: 0.8pt solid #0f172a; padding-top: 1.2mm; }
        .closing { margin-top: 9mm; padding-top: 2.4mm; border-top: 0.6pt solid #e2e8f0; font-size: 8.5pt; color: #64748b; }
    </style>
</head>
<body>
    <div class="running-head">
        <table><tr>
            <td>{{ __('court::recordes.titles.record') }}</td>
            <td class="right mono">{{ $case['case_number'] }}</td>
        </tr></table>
    </div>
    <div class="running-foot">{{ $b('generated', ['date' => $fmt($generatedAt, true)]) }}</div>

    {{-- Title, the case at a glance, contents --}}
    <div class="title-block">
        <table><tr>
            <td style="vertical-align: top">
                <div class="eyebrow">{{ $r('eyebrow') }}</div>
                <div class="case-number mono">{{ $case['case_number'] ?? $na }}</div>
                @if($case['title'])<div class="case-title">{{ $case['title'] }}</div>@endif
            </td>
            <td class="right" style="vertical-align: top; width: 60mm">
                <span class="chip">{{ $status($case['status']) }}</span>
                @if($appeal['appealedAt'])
                    <br><span class="chip chip-warn" style="margin-top: 1.5mm">{{ __('court::appeal_records.register.appealed') }}@if($appeal['outcome']) · {{ __('court::appeal_records.outcomes.'.$appeal['outcome']) }}@endif</span>
                @endif
            </td>
        </tr></table>
    </div>

    <table class="facts">
        <tr>
            <td><div class="k">{{ $b('applicant') }}</div><div class="v">{{ $case['applicant_name'] ?? $na }}</div></td>
            <td><div class="k">{{ $b('respondent') }}</div><div class="v">{{ $case['respondent_name'] ?? $na }}</div></td>
            <td><div class="k">{{ $b('case_type') }}</div><div class="v">{{ $case['case_type'] ?? $na }}</div></td>
            <td><div class="k">{{ $r('assigned_to') }}</div><div class="v">{{ $assignedName ?? __('court::recordes.labels.unassigned') }}@if(! empty($teamNames))<br><span class="small muted">{{ implode(', ', $teamNames) }}</span>@endif</div></td>
        </tr>
        <tr>
            <td><div class="k">{{ $b('filed') }}</div><div class="v">{!! $fmtCell($case['filing_date']) !!}</div></td>
            <td><div class="k">{{ $r('first_hearing') }}</div><div class="v">{!! $fmtCell($case['first_hearing_date']) !!}</div></td>
            <td><div class="k">{{ $b('decided') }}</div><div class="v">{!! $fmtCell($decisionDate) !!}</div></td>
            <td><div class="k">{{ $b('closed') }}</div><div class="v">{!! $closedAt ? $fmtCell($closedAt) : e(__('court::recordes.labels.not_closed')) !!}</div></td>
        </tr>
    </table>

    <table class="contents">
        <tr><td class="head" colspan="2">{{ $r('contents') }}</td></tr>
        @foreach($sections as $key => $label)
            <tr><td class="n">{{ $number[$key] + 1 }}</td><td>{{ $label }}</td></tr>
        @endforeach
        <tr><td colspan="2" style="padding-bottom: 2.4mm"></td></tr>
    </table>

    {{-- 1. Where the case started in EUISIS --}}
    @include('court::pdf.partials.case-record-heading', ['n' => $number['origin'] + 1, 'title' => $sections['origin']])
    <div class="section-body">
        @forelse($origins as $origin)
            <div class="entry accent">
                <table class="head"><tr>
                    <td><span class="title">{{ $b('origin_'.$origin['kind']) }} {{ $origin['reference'] ?? '' }}</span> — {{ $origin['subject'] }}</td>
                    <td class="when">{{ $origin['status'] ?? '' }}</td>
                </tr></table>
                <p class="small muted">@if($origin['category']){{ $origin['category'] }} · @endif{{ $b('opened') }} {{ $fmt($origin['submitted_at']) }} · {{ $b('closed') }} {{ $fmt($origin['closed_at']) }}</p>
                @if($origin['description'])<p style="white-space: pre-line">{{ $origin['description'] }}</p>@endif
                @if(! empty($origin['responses']))
                    <h3>{{ $b('origin_responses') }}</h3>
                    @foreach($origin['responses'] as $response)
                        <p><span class="small muted">{{ $response['at'] ?? '' }}</span><br><span style="white-space: pre-line">{{ $response['body'] ?? '' }}</span></p>
                    @endforeach
                @endif
                @if(! empty($origin['decisions']))
                    <h3>{{ $b('origin_decisions') }}</h3>
                    @foreach($origin['decisions'] as $item)
                        <p><strong>{{ trim(($item['number'] ?? '').' '.($item['type'] ?? '')) }}</strong> <span class="small muted">{{ $item['at'] ?? '' }}</span><br><span style="white-space: pre-line">{{ $item['text'] ?? '' }}</span></p>
                    @endforeach
                @endif
            </div>
        @empty
            <p class="empty">{{ $b('no_origin') }}</p>
        @endforelse
    </div>

    {{-- 2. The case at this court --}}
    @include('court::pdf.partials.case-record-heading', ['n' => $number['case'] + 1, 'title' => $sections['case']])
    <div class="section-body">
        <table class="grid">
            <tr><th>{{ $b('name') }}</th><th>{{ $b('role') }}</th><th>{{ $b('lawyer') }}</th></tr>
            @foreach($parties as $party)
                <tr>
                    <td>{{ $party['name'] }}</td>
                    <td>{{ __('court::case_parties.panel.roles.'.$party['role']) }}</td>
                    <td>{{ collect($party['lawyers'])->map(fn ($l) => $l['name'].($l['license_number'] ? ' ('.$l['license_number'].')' : ''))->join(', ') ?: $dash }}</td>
                </tr>
            @endforeach
            <tr>
                <td>{{ $case['respondent_name'] ?? $na }}</td>
                <td>{{ $b('respondent') }}</td>
                <td class="muted">{{ $case['respondent_address'] ?? $dash }}</td>
            </tr>
        </table>
        @if($case['applicant_email'] || $case['applicant_phone'])
            <p class="small muted" style="margin-top: 1.6mm">{{ $b('applicant') }}: {{ collect([$case['applicant_email'], $case['applicant_phone']])->filter()->join(' · ') }}</p>
        @endif
        <h3>{{ $b('claim') }}</h3>
        @if($case['description'])<div class="rich">{!! $case['description'] !!}</div>@else<p class="empty">{{ __('court::recordes.messages.no_details_provided') }}</p>@endif
        @if($case['relief_requested'])
            <h3>{{ $b('relief') }}</h3>
            <div class="rich">{!! $case['relief_requested'] !!}</div>
        @endif
    </div>

    {{-- 3. The respondent's answers and the applicant's replies --}}
    @include('court::pdf.partials.case-record-heading', ['n' => $number['pleadings'] + 1, 'title' => $sections['pleadings']])
    <div class="section-body">
        @forelse($respondentResponses as $response)
            <div class="entry">
                <table class="head"><tr>
                    <td class="title">{{ $response['title'] ?? $b('response') }}</td>
                    <td class="when">{!! $fmtCell($response['created_at']) !!}</td>
                </tr></table>
                @if($response['description'])<p>{{ $response['description'] }}</p>@endif
                @foreach($repliesFor($response['id']) as $reply)
                    <div class="reply">
                        <p class="small muted"><strong>{{ $b('reply') }}</strong> · {{ $fmt($reply['created_at']) }}</p>
                        <div class="rich">{!! $reply['description'] !!}</div>
                    </div>
                @endforeach
            </div>
        @empty
            <p class="empty">{{ $b('no_pleadings') }}</p>
        @endforelse
    </div>

    {{-- 4. Hearings and the bench's notes --}}
    @include('court::pdf.partials.case-record-heading', ['n' => $number['hearings'] + 1, 'title' => $sections['hearings']])
    <div class="section-body">
        @if(count($hearings) > 0)
            <table class="grid">
                <tr><th style="width: 7mm">#</th><th style="width: 52mm">{{ $b('date') }}</th><th>{{ $r('type_location') }}</th><th>{{ $r('notes') }}</th></tr>
                @foreach($hearings as $i => $hearing)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>{!! $fmtCell($hearing['hearing_at'], true) !!}</td>
                        <td>{{ collect([$hearing['type'], $hearing['location']])->filter()->join(' · ') ?: $dash }}</td>
                        <td>
                            @if($hearing['judge_notes'])<strong>{{ __('court::recordes.labels.judge_notes') }}</strong> {{ $hearing['judge_notes'] }}<br>@endif
                            @if($hearing['notes']){{ $hearing['notes'] }}@endif
                            @if(! $hearing['judge_notes'] && ! $hearing['notes'])<span class="muted">{{ $dash }}</span>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @else
            <p class="empty">{{ $b('no_hearings') }}</p>
        @endif
        @if(count($benchNotes) > 0)
            <h3>{{ $b('bench_notes') }}</h3>
            @foreach($benchNotes as $note)
                <div class="entry">
                    <table class="head"><tr>
                        <td class="title">{{ $note['title'] ?? $b('bench_note') }}</td>
                        <td class="when">{!! $fmtCell($note['created_at'], true) !!}</td>
                    </tr></table>
                    <div class="rich">{!! $note['note'] !!}</div>
                    @if($note['author'])<p class="small muted">{{ $note['author'] }}</p>@endif
                </div>
            @endforeach
        @endif
    </div>

    {{-- 5. Witnesses and the court's letters --}}
    @include('court::pdf.partials.case-record-heading', ['n' => $number['witnesses'] + 1, 'title' => $sections['witnesses']])
    <div class="section-body">
        @if(count($witnesses) > 0)
            <table class="grid">
                <tr><th style="width: 7mm">#</th><th>{{ $b('name') }}</th><th>{{ $b('phone') }}</th><th>{{ $r('email') }}</th><th>{{ $b('address') }}</th></tr>
                @foreach($witnesses as $i => $witness)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>{{ $witness['full_name'] ?? $na }}</td>
                        <td>{{ $witness['phone'] ?: $dash }}</td>
                        <td>{{ $witness['email'] ?: $dash }}</td>
                        <td>{{ $witness['address'] ?: $dash }}</td>
                    </tr>
                @endforeach
            </table>
        @else
            <p class="empty">{{ $b('no_witnesses') }}</p>
        @endif
        @if(count($letters) > 0)
            <h3>{{ __('court::recordes.labels.letters_section') }}</h3>
            @foreach($letters as $letter)
                <div class="entry">
                    <table class="head"><tr>
                        <td><span class="title">{{ $letter['subject'] ?? __('court::recordes.labels.letter') }}</span><br><span class="small muted">{{ collect([$letter['reference_number'], $letter['author_name']])->filter()->join(' · ') }}</span></td>
                        <td class="when">{!! $fmtCell($letter['created_at']) !!}</td>
                    </tr></table>
                    @if($letter['body'])<div class="rich">{!! $letter['body'] !!}</div>@endif
                </div>
            @endforeach
        @endif
    </div>

    {{-- 6. The decision, with the panel's signature lines --}}
    @include('court::pdf.partials.case-record-heading', ['n' => $number['decision'] + 1, 'title' => $sections['decision']])
    <div class="section-body">
        @if($decision)
            <table class="head" style="margin-bottom: 2mm"><tr>
                <td style="font-size: 11pt; font-weight: bold">{{ $decision['title'] ?? __('court::recordes.labels.decision') }}</td>
                <td class="right small muted">{{ $fmt($decisionDate) }}</td>
            </tr></table>
            @if($decision['content'])<div class="rich">{!! $decision['content'] !!}</div>@endif
            @if(! empty($panelJudges))
                <table class="signatures"><tr>
                    @foreach($panelJudges as $judge)
                        <td><div class="line">{{ $judge }}<br><span class="small muted">{{ __('court::recordes.labels.judge') }}</span></div></td>
                    @endforeach
                    @for($i = count($panelJudges); $i < 3; $i++)<td></td>@endfor
                </tr></table>
            @endif
        @else
            <p class="empty">{{ $b('no_decision') }}</p>
        @endif
    </div>

    {{-- 7. The appeal --}}
    @include('court::pdf.partials.case-record-heading', ['n' => $number['appeal'] + 1, 'title' => $sections['appeal']])
    <div class="section-body">
        @if($appeal['appealedAt'])
            <p>{{ $r('appealed_since', ['date' => $fmt($appeal['appealedAt'])]) }}@if($appeal['outcome']) · {{ $b('outcome') }}: <strong>{{ __('court::appeal_records.outcomes.'.$appeal['outcome']) }}</strong>@endif</p>
        @endif
        @forelse($appeal['rows'] as $row)
            <div class="entry {{ $row['outcome'] ? 'accent' : '' }}">
                <table class="head"><tr>
                    <td><span class="title mono">{{ $row['appeal_number'] }}</span>@if($row['appellant']) — {{ $row['appellant'] }}@endif @if($row['side'])<span class="muted">({{ __('court::appeal_records.sides.'.$row['side']) }})</span>@endif</td>
                    <td class="when">{{ __('court::appeal_records.statuses.'.$row['status']) }}</td>
                </tr></table>
                <table class="kv">
                    <tr><td class="k">{{ $b('requested') }}</td><td>{{ $fmt($row['requested_at']) }}@if($row['via']) <span class="muted">{{ __('court::appeal_records.via.'.$row['via']) }}</span>@endif</td></tr>
                    @if($row['released_at'])<tr><td class="k">{{ $b('released') }}</td><td>{{ $fmt($row['released_at']) }}@if($row['released_by']) · {{ $row['released_by'] }}@endif</td></tr>@endif
                    @if($row['grounds'])<tr><td class="k">{{ $b('grounds') }}</td><td style="white-space: pre-line">{{ $row['grounds'] }}</td></tr>@endif
                    @if($row['status'] === 'rejected' && $row['note'])<tr><td class="k">{{ $r('refused') }}</td><td>{{ $row['note'] }}</td></tr>@endif
                    @if($row['outcome'])
                        <tr><td class="k">{{ $b('outcome') }}</td><td><strong>{{ __('court::appeal_records.outcomes.'.$row['outcome']) }}</strong> · {{ collect([$row['appellate_court'], $row['appellate_case_number'], $row['outcome_date']])->filter()->join(' · ') }}@if($row['outcome_notes'])<br><span class="muted">{{ $row['outcome_notes'] }}</span>@endif</td></tr>
                    @endif
                </table>
            </div>
        @empty
            <p class="empty">{{ $b('no_appeal') }}</p>
        @endforelse
    </div>

    {{-- 8. Documents on file --}}
    @include('court::pdf.partials.case-record-heading', ['n' => $number['documents'] + 1, 'title' => $sections['documents']])
    <div class="section-body">
        @if($documents->isNotEmpty())
            <table class="grid">
                <tr><th style="width: 7mm">#</th><th>{{ $b('document') }}</th><th style="width: 28mm">{{ $b('stage') }}</th><th style="width: 46mm">{{ $b('date') }}</th></tr>
                @foreach($documents as $i => $document)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>{{ $document['title'] ?? $b('document') }}</td>
                        <td>{{ $b('stages.'.$document['stage']) }}</td>
                        <td>{!! $fmtCell($document['at']) !!}</td>
                    </tr>
                @endforeach
            </table>
            <p class="small muted" style="margin-top: 1.6mm">{{ $r('documents_note') }}</p>
        @else
            <p class="empty">{{ $b('no_annexes') }}</p>
        @endif
    </div>

    <div class="closing">{{ $r('closing', ['date' => $fmt($generatedAt, true)]) }}</div>
</body>
</html>
