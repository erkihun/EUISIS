{{-- resources/views/pdf/appeal-record.blade.php
     Server-side (dompdf) consolidated appeal record: the entire court process. --}}
@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;
    use Modules\Court\Application\Support\Purifier;

    $na = __('court::recordes.messages.not_available');
    $fmt = static function ($value, bool $withTime = false) use ($na) {
        if (empty($value)) return $na;
        $c = $value instanceof Carbon ? $value : Carbon::parse($value);
        $greg = $withTime ? $c->toDayDateTimeString() : $c->toDateString();
        $eth = class_exists(\Modules\Court\Application\Support\EthiopianDate::class)
            ? \Modules\Court\Application\Support\EthiopianDate::format($c, withTime: $withTime)
            : '';
        return $eth ? ($greg . ' (' . $eth . ')') : $greg;
    };

    $applicantName = $case->applicant
        ? trim(($case->applicant->first_name ?? '') . ' ' . ($case->applicant->middle_name ?? '') . ' ' . ($case->applicant->last_name ?? ''))
        : ($case->title ?? $na);

    $generatedAt = $generatedAt ?? now();
@endphp
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <title>{{ __('court::recordes.titles.pdf') }} — {{ $case->case_number ?? $case->id }}</title>
    <style>
        @page { margin: 28mm 16mm 20mm 16mm; }
        @font-face {
            font-family: 'Abyssinica';
            src: url('{{ public_path('fonts/AbyssinicaSIL-Regular.ttf') }}') format('truetype');
            font-style: normal; font-weight: normal;
        }
        * { font-family: 'Abyssinica', 'DejaVu Serif', serif; }
        body { color: #0f172a; font-size: 11pt; line-height: 1.5; }

        .page-header {
            position: fixed; top: -20mm; left: 0; right: 0; height: 16mm;
            border-bottom: 1px solid #cbd5e1; padding-bottom: 2mm;
            font-size: 9pt; color: #475569;
        }
        .page-header .ph-left { float: left; }
        .page-header .ph-right { float: right; text-align: right; }
        .page-footer {
            position: fixed; bottom: -14mm; left: 0; right: 0; height: 10mm;
            border-top: 1px solid #cbd5e1; padding-top: 2mm;
            font-size: 8pt; color: #64748b; text-align: center;
        }

        h1.doc-title { font-size: 17pt; text-align: center; margin: 0 0 2mm; }
        .doc-sub { text-align: center; font-size: 9.5pt; color: #475569; margin-bottom: 6mm; }

        .section { margin: 0 0 6mm; }
        .section > h2 {
            font-size: 12.5pt; color: #0f172a; margin: 0 0 3mm;
            border-bottom: 2px solid #0f172a; padding-bottom: 1.5mm;
        }
        .section.break-before { page-break-before: always; }

        .card {
            border: 1px solid #e2e8f0; border-radius: 4px;
            padding: 3mm 3.5mm; margin-bottom: 3mm; background: #f8fafc;
        }
        .card strong { color: #0f172a; }
        .meta { font-size: 9.5pt; color: #475569; margin: 1mm 0; }
        .content { margin-top: 2mm; }
        .content table { width: 100%; border-collapse: collapse; }
        .content th, .content td { border: 1px solid #cbd5e1; padding: 3px 5px; }
        .empty { font-size: 9.5pt; color: #94a3b8; font-style: italic; }

        .kv { width: 100%; border-collapse: collapse; font-size: 10pt; }
        .kv td { padding: 1.5mm 2mm; vertical-align: top; border-bottom: 1px solid #eef2f7; }
        .kv td.k { width: 38mm; color: #475569; }

        .sig-row { width: 100%; margin-top: 6mm; }
        .sig { display: inline-block; width: 32%; text-align: center; vertical-align: bottom; font-size: 9pt; }
        .sig .line { border-top: 1px solid #0f172a; margin: 12mm 4mm 0; padding-top: 2px; }
        .sig img { max-height: 14mm; max-width: 40mm; }
    </style>
</head>
<body>
    {{-- Repeating header / footer on every page --}}
    <div class="page-header">
        <span class="ph-left">{{ __('court::recordes.labels.case_number') }} {{ $case->case_number ?? $na }}</span>
        <span class="ph-right">{{ __('court::recordes.titles.pdf') }}</span>
    </div>
    <div class="page-footer">
        {{ __('court::recordes.labels.generated') }} {{ $fmt($generatedAt, true) }}
    </div>

    {{-- Title --}}
    <h1 class="doc-title">{{ __('court::recordes.titles.record') }}</h1>
    <div class="doc-sub">
        {{ __('court::recordes.labels.case_number') }} {{ $case->case_number ?? $na }}
        @if(!empty($case->title)) &nbsp;|&nbsp; {{ $case->title }} @endif
    </div>

    {{-- 1. Case overview --}}
    <div class="section">
        <h2>{{ __('court::recordes.labels.case_submission') }}</h2>
        <table class="kv">
            <tr><td class="k">{{ __('court::recordes.labels.case_number') }}</td><td>{{ $case->case_number ?? $na }}</td></tr>
            <tr><td class="k">{{ __('court::recordes.labels.status_label') }}</td><td>{{ $case->status ?? $na }}</td></tr>
            <tr><td class="k">{{ __('court::recordes.labels.type') }}</td><td>{{ $case->caseType?->name ?? $na }}</td></tr>
            <tr><td class="k">{{ __('court::recordes.labels.filed') }}</td><td>{{ $fmt($case->filing_date) }}</td></tr>
            <tr><td class="k">{{ __('court::recordes.labels.closed') }}</td><td>{{ $closedAt ? $fmt($closedAt, true) : __('court::recordes.labels.not_closed') }}</td></tr>
            <tr><td class="k">{{ __('court::recordes.labels.assigned_to') }}</td><td>{{ $assignedUser->name ?? __('court::recordes.labels.unassigned') }}</td></tr>
            @if(($assignedTeams ?? collect())->isNotEmpty())
            <tr><td class="k">{{ __('court::recordes.labels.teams') }}</td><td>{{ $assignedTeams->join(', ') }}</td></tr>
            @endif
        </table>
    </div>

    {{-- 2. Parties --}}
    <div class="section">
        <h2>{{ __('court::recordes.labels.applicant') }} / {{ __('court::recordes.labels.respondent') }}</h2>
        <table class="kv">
            <tr><td class="k">{{ __('court::recordes.labels.applicant') }}</td><td>{{ $applicantName ?: $na }}</td></tr>
            <tr><td class="k">{{ __('court::recordes.labels.email') }}</td><td>{{ $case->applicant->email ?? $na }}</td></tr>
            <tr><td class="k">{{ __('court::recordes.labels.phone') }}</td><td>{{ $case->applicant->phone ?? $na }}</td></tr>
            <tr><td class="k">{{ __('court::recordes.labels.respondent') }}</td><td>{{ $case->respondent_name ?? $na }}</td></tr>
            <tr><td class="k">{{ __('court::recordes.labels.address') }}</td><td>{{ $case->respondent_address ?? $na }}</td></tr>
        </table>
    </div>

    @if(!empty($case->description))
    <div class="section">
        <h2>{{ __('court::recordes.labels.case_details') }}</h2>
        <div class="content">{!! \Modules\Court\Application\Support\Purifier::clean($case->description, 'default') !!}</div>
    </div>
    @endif

    @if(!empty($case->relief_requested))
    <div class="section">
        <h2>{{ __('court::recordes.labels.relief_requested') }}</h2>
        <div class="content">{!! \Modules\Court\Application\Support\Purifier::clean($case->relief_requested, 'default') !!}</div>
    </div>
    @endif

    {{-- 3. Witnesses --}}
    <div class="section">
        <h2>{{ __('court::recordes.labels.witnesses') }}</h2>
        @forelse($witnesses ?? [] as $wit)
        <div class="card">
            <div><strong>{{ $wit->full_name ?? __('court::recordes.labels.witness') }}</strong></div>
            <div class="meta">{{ __('court::recordes.labels.phone') }} {{ $wit->phone ?? $na }} | {{ __('court::recordes.labels.email') }} {{ $wit->email ?? $na }} | {{ __('court::recordes.labels.address') }} {{ $wit->address ?? $na }}</div>
        </div>
        @empty
        <div class="empty">{{ __('court::recordes.messages.no_witnesses') }}</div>
        @endforelse
    </div>

    {{-- 4. Submitted documents + evidence (listed, not embedded) --}}
    <div class="section">
        <h2>{{ __('court::recordes.labels.submitted_documents') }}</h2>
        @forelse($files ?? [] as $file)
        <div class="card">
            <div><strong>{{ $file->label ?? __('court::recordes.labels.document') }}</strong></div>
            <div class="meta">
                {{ $fmt($file->created_at, true) }}
                @if(!empty($file->mime)) | {{ __('court::recordes.labels.mime') }} {{ $file->mime }} @endif
                @if(!empty($file->size)) | {{ __('court::recordes.labels.size') }} {{ $file->size }} {{ __('court::recordes.labels.bytes') }} @endif
            </div>
        </div>
        @empty
        <div class="empty">{{ __('court::recordes.messages.no_files') }}</div>
        @endforelse
    </div>

    <div class="section">
        <h2>{{ __('court::recordes.labels.applicant_evidence') }}</h2>
        @forelse($evidences ?? [] as $ev)
        <div class="card">
            <div><strong>{{ $ev->title ?? __('court::recordes.labels.document') }}</strong></div>
            <div class="meta">{{ $fmt($ev->created_at, true) }}</div>
            @if(!empty($ev->description))<div class="content">{{ $ev->description }}</div>@endif
            <div class="meta">
                {{ __('court::recordes.labels.type') }} {{ $ev->type ?? 'document' }}
                @if(!empty($ev->mime)) | {{ __('court::recordes.labels.mime') }} {{ $ev->mime }} @endif
                @if(!empty($ev->size)) | {{ __('court::recordes.labels.size') }} {{ $ev->size }} {{ __('court::recordes.labels.bytes') }} @endif
            </div>
        </div>
        @empty
        <div class="empty">{{ __('court::recordes.messages.no_evidence') }}</div>
        @endforelse
    </div>

    {{-- 5. Letters --}}
    <div class="section break-before">
        <h2>{{ __('court::recordes.labels.letters_section') }}</h2>
        @forelse($letters ?? [] as $letter)
        <div class="card">
            <div><strong>{{ $letter->subject ?? $letter->reference_number ?? __('court::recordes.labels.document') }}</strong></div>
            <div class="meta">
                {{ $fmt($letter->created_at, true) }}
                @if(!empty($letter->author_name)) | {{ $letter->author_name }} @endif
                @if(!empty($letter->reference_number)) | {{ $letter->reference_number }} @endif
            </div>
            @if(!empty($letter->body))
            <div class="content">{!! \Modules\Court\Application\Support\Purifier::clean($letter->body, 'default') !!}</div>
            @endif
        </div>
        @empty
        <div class="empty">{{ __('court::recordes.messages.no_letters') }}</div>
        @endforelse
    </div>

    {{-- 6. Respondent responses --}}
    <div class="section">
        <h2>{{ __('court::recordes.labels.respondent_responses') }}</h2>
        @forelse($respondentResponses ?? [] as $resp)
        <div class="card">
            <div><strong>{{ $resp->title ?? __('court::recordes.labels.response') }}</strong></div>
            <div class="meta">{{ $fmt($resp->created_at, true) }}</div>
            @if(!empty($resp->description))<div class="content">{{ $resp->description }}</div>@endif
        </div>
        @empty
        <div class="empty">{{ __('court::recordes.messages.no_responses') }}</div>
        @endforelse
    </div>

    {{-- 7. Hearings --}}
    <div class="section break-before">
        <h2>{{ __('court::recordes.labels.hearings') }}</h2>
        @forelse($hearings ?? [] as $hearing)
        <div class="card">
            <div><strong>{{ __('court::recordes.labels.hearing_at') }} {{ $fmt($hearing->hearing_at, true) }}</strong></div>
            <div class="meta">{{ __('court::recordes.labels.location') }} {{ $hearing->location ?? $na }}</div>
            @if(!empty($hearing->notes))
            <div class="content"><strong>{{ __('court::recordes.labels.hearing_notes') }}</strong> {!! \Modules\Court\Application\Support\Purifier::clean($hearing->notes, 'default') !!}</div>
            @endif
            @if(!empty($hearing->judge_notes))
            <div class="content"><strong>{{ __('court::recordes.labels.judge_notes') }}</strong> {!! \Modules\Court\Application\Support\Purifier::clean($hearing->judge_notes, 'default') !!}</div>
            @endif
        </div>
        @empty
        <div class="empty">{{ __('court::recordes.messages.no_hearings') }}</div>
        @endforelse
    </div>

    {{-- 8. Bench notes --}}
    <div class="section">
        <h2>{{ __('court::recordes.labels.bench_notes') }}</h2>
        @forelse($benchNotes ?? [] as $note)
        @php
            $benchJudges = collect([
                ['name' => $note->judge_one_name ?? null,   'title' => $note->judge_one_title ?? null,   'sig' => $note->judge_one_signature ?? null],
                ['name' => $note->judge_two_name ?? null,   'title' => $note->judge_two_title ?? null,   'sig' => $note->judge_two_signature ?? null],
                ['name' => $note->judge_three_name ?? null, 'title' => $note->judge_three_title ?? null, 'sig' => $note->judge_three_signature ?? null],
            ])->filter(fn ($j) => !empty($j['name']))->values();
        @endphp
        <div class="card">
            <div class="meta"><strong>{{ $note->title ?? __('court::recordes.labels.bench_notes') }}</strong> — {{ __('court::recordes.labels.created') }} {{ $fmt($note->created_at, true) }}</div>
            @if($benchJudges->isNotEmpty())
            <div class="meta">
                @foreach($benchJudges as $i => $j){{ $i + 1 }}. {{ $j['name'] }}@if(!$loop->last) &nbsp;&nbsp; @endif @endforeach
            </div>
            @endif
            <div class="content">{!! \Modules\Court\Application\Support\Purifier::clean($note->note ?? $note->body ?? '', 'default') !!}</div>
        </div>
        @empty
        <div class="empty">{{ __('court::recordes.messages.no_bench_notes') }}</div>
        @endforelse
    </div>

    {{-- 9. Final decision --}}
    <div class="section break-before">
        <h2>{{ __('court::recordes.labels.final_judgment') }}</h2>
        @if(!empty($decision))
        <div class="card">
            <div class="meta"><strong>{{ $decision->name ?? $decision->title ?? __('court::recordes.labels.decision') }}</strong> — {{ __('court::recordes.labels.created') }} {{ $fmt($decision->decision_date ?? $decision->created_at, true) }}</div>
            <div class="content">{!! \Modules\Court\Application\Support\Purifier::clean($decision->decision_content ?? $decision->body ?? '', 'default') !!}</div>
            @php $panel = isset($decision->panel_judges) ? (json_decode($decision->panel_judges, true) ?: []) : []; @endphp
            @if(!empty($panel))
            <div class="sig-row">
                @foreach(array_slice(array_values($panel), 0, 3) as $i => $pj)
                @php $jname = $pj['admin_user_name'] ?? ''; @endphp
                @if($jname !== '')
                <div class="sig"><div class="line"><div>{{ $jname }}</div></div></div>
                @endif
                @endforeach
            </div>
            @endif
        </div>
        @else
        <div class="empty">{{ __('court::recordes.messages.no_decision') }}</div>
        @endif
    </div>
</body>
</html>
