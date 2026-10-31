@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;
    use Modules\Court\Application\Support\Purifier;

    $applicantFullName = trim(($case->first_name ?? '') . ' ' . ($case->middle_name ?? '') . ' ' . ($case->last_name ?? '')) ?: '—';
    $applicantEmail = $case->email ?? '—';
    $applicantPhone = $case->phone ?? '—';
    $respondentName = $case->respondent_name ?? '—';
    $respondentAddress = $case->respondent_address ?? '—';
    $formattedFiled = \Modules\Court\Application\Support\EthiopianDate::format($case->filing_date ?? $case->created_at, withTime: true);
    $generatedAt = \Modules\Court\Application\Support\EthiopianDate::format(now(), withTime: true);
    $safeCaseDetails = \Modules\Court\Application\Support\Purifier::clean($case->description ?? '', 'default');
    $safeReliefRequested = \Modules\Court\Application\Support\Purifier::clean($case->relief_requested ?? '', 'default');
@endphp
<!doctype html>
<html lang="am">

<head>
    <meta charset="utf-8">
    <title>{{ __('Applicant Filing Receipt') }} — {{ $case->case_number }}</title>
    <style>
        @page {
            margin: 22mm 16mm;
        }

        @font-face {
            font-family: 'Noto Sans Ethiopic';
            src: url('{{ public_path('fonts/NotoSansEthiopic-Regular.ttf') }}') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        @font-face {
            font-family: 'Abyssinica';
            src: url('{{ public_path('fonts/AbyssinicaSIL-Regular.ttf') }}') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        body {
            font-family: 'Noto Sans Ethiopic', 'Abyssinica', 'Nyala', 'DejaVu Serif', serif;
            color: #0f172a;
            font-size: 12px;
            line-height: 1.6;
        }

        h1,
        h2,
        h3 {
            margin: 0 0 8px;
            font-weight: 700;
        }

        .muted {
            color: #64748b;
            font-size: 11px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 12px;
            margin-bottom: 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .brand {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .section {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 18px;
        }

        .section-title {
            font-size: 13px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 10px;
        }

        .bilingual {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #94a3b8;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 18px;
        }

        .info-block {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #94a3b8;
            letter-spacing: 0.06em;
        }

        .info-value {
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #312e81;
            font-size: 11px;
            text-transform: capitalize;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        .table th,
        .table td {
            border: 1px solid #e2e8f0;
            padding: 8px;
            vertical-align: top;
        }

        .table th {
            background: #f8fafc;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .body-copy {
            font-size: 12px;
            color: #0f172a;
        }
    </style>
</head>

<body>
    <div class="header">
        <div>
            <div class="brand">{{ config('app.name', 'Court-MS') }}</div>
            <div class="muted">{{ __('Generated / የተፈጠረ') }}: {{ $generatedAt }}</div>
        </div>
        <div style="text-align:right;">
            <div class="muted">{{ __('Case Number / የክስ ቁጥር') }}</div>
            <div class="info-value">{{ $case->case_number }}</div>
            @if(!empty($case->code))
            <div class="muted">Access Code</div>
            <div class="info-value">{{ $case->code }}</div>
            @endif
            <div class="muted">{{ __('Receipt ID / የደረሰኝ መለያ') }}: {{ strtoupper(Str::random(6)) }}</div>
        </div>
    </div>

    <h1 style="margin-bottom:6px;">{{ __('Case Filing Receipt') }} / የክስ መግቢያ ደረሰኝ</h1>
    <p class="muted" style="margin-bottom:18px;">
        {{ __('court::cases.receipt.summary_notice') }}
        / ይህ ሰነድ ስለክስዎ የቀረበውን መረጃ ይዘረዝራል። እባክዎ ቅጂ ይጠብቁ።
    </p>

    <div class="section">
        <div class="section-title">{{ __('Case Overview') }} / የክስ አጠቃላይ መረጃ</div>
        <div class="grid">
            <div class="info-block">
                <span class="info-label">{{ __('Title') }} / አርእስት</span>
                <span class="info-value">{{ $case->title ?? '—' }}</span>
            </div>
            <div class="info-block">
                <span class="info-label">{{ __('Case Type') }} / ዓይነት</span>
                <span class="info-value">{{ $case->case_type ?? '—' }}</span>
            </div>
            <div class="info-block">
                <span class="info-label">{{ __('Filed On') }} / የቀረበበት ቀን</span>
                <span class="info-value">{{ $formattedFiled }}</span>
            </div>
            <div class="info-block">
                <span class="info-label">{{ __('Status') }} / ሁኔታ</span>
                <span class="info-value"><span class="badge">{{ $case->status ?? '—' }}</span></span>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">{{ __('Parties') }} / ተከራካሪዎች</div>
        <div class="grid">
            <div class="info-block">
                <span class="info-label">{{ __('Applicant Details') }} / የማመልከቻ መረጃ</span>
                <span class="info-value">{{ $applicantFullName }}</span>
                <span class="body-copy">{{ __('Email') }} / ኢሜይል: {{ $applicantEmail }}</span>
                <span class="body-copy">{{ __('Phone') }} / ስልክ: {{ $applicantPhone }}</span>
            </div>
            <div class="info-block">
                <span class="info-label">{{ __('Respondent Details') }} / የተከሳሹ መረጃ</span>
                <span class="info-value">{{ $respondentName }}</span>
                <span class="body-copy">{{ __('Address') }} / አድራሻ: {{ $respondentAddress }}</span>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">{{ __('Case Details') }} / የክስ ዝርዝር</div>
        <div class="body-copy" style="text-align:justify;">{!! $safeCaseDetails ?: '—' !!}</div>
    </div>

    @if(!empty($case->relief_requested))
        <div class="section">
            <div class="section-title">{{ __('Relief Requested') }} / የተጠየቀው ማገዶ</div>
            <div class="body-copy">{!! $safeReliefRequested ?: '—' !!}</div>
        </div>
    @endif

    @if(($evidenceDocs ?? collect())->isNotEmpty())
        <div class="section">
            <div class="section-title">{{ __('Submitted Documents') }} / የቀረቡ ሰነዶች</div>
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:28px;">#</th>
                        <th>{{ __('Title/Label') }} / ርዕስ</th>
                        <th>{{ __('Type') }} / አይነት</th>
                        <th>{{ __('Uploaded On') }} / ካለቀረበበት ቀን</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($evidenceDocs as $index => $doc)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $doc->title ?? '—' }}</td>
                            <td>{{ $doc->type ?? 'document' }}</td>
                            <td>{{ \Modules\Court\Application\Support\EthiopianDate::format($doc->created_at, withTime: true, fallback: '—') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(($witnesses ?? collect())->isNotEmpty())
        <div class="section">
            <div class="section-title">{{ __('Witnesses') }} / ምስክሮች</div>
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:28px;">#</th>
                        <th>{{ __('Name') }} / ስም</th>
                        <th>{{ __('Phone') }} / ስልክ</th>
                        <th>{{ __('Email') }} / ኢሜይል</th>
                        <th>{{ __('Address') }} / አድራሻ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($witnesses as $index => $witness)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $witness->full_name ?? $witness->title ?? '—' }}</td>
                            <td>{{ $witness->phone ?? '—' }}</td>
                            <td>{{ $witness->email ?? '—' }}</td>
                            <td>{{ $witness->address ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(($hearings ?? collect())->isNotEmpty())
        <div class="section">
            <div class="section-title">{{ __('Hearings') }} / ስብሰባዎች</div>
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Date & Time') }} / ቀን እና ሰዓት</th>
                        <th>{{ __('Type') }} / አይነት</th>
                        <th>{{ __('Location') }} / ቦታ</th>
                        <th>{{ __('Notes') }} / ማብራሪያ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($hearings as $hearing)
                        <tr>
                            <td>{{ \Modules\Court\Application\Support\EthiopianDate::format($hearing->hearing_at, withTime: true, fallback: '—') }}</td>
                            <td>{{ $hearing->type ?? '—' }}</td>
                            <td>{{ $hearing->location ?? '—' }}</td>
                            <td>{{ strip_tags($hearing->notes ?? '') ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="muted" style="margin-top:20px;">
        {{ __('court::cases.receipt.generated_electronically_by', ['app' => config('app.name', 'Court-MS')]) }}
        / ይህ ደረሰኝ በኢሌክትሮኒክስ በ {{ config('app.name','Court-MS') }} ተፈጠረ።
    </div>
</body>

</html>

