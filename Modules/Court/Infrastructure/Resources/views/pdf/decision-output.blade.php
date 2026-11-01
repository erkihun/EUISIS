{{-- resources/views/pdf/decision-output.blade.php --}}
@php
    $caseNumber = $decision->case_number ?? '';
    $panel = $displayPanel ?? $decision->panelJudgesAuthorFirst();
    $judgeSignatures = $signaturePaths ?? [];
    $judgeNames = [];
    for ($i = 0; $i < 3; $i++) {
        $name = trim((string) ($panel[$i]['admin_user_name'] ?? ''));
        if ($name !== '') {
            $judgeNames[$i] = $name;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Decision {{ $caseNumber ?: $decision->id }}</title>
    <style>
        /* Reserve space at the top and bottom of every page for the fixed
           header and footer bands. */
        @page { margin: 36mm 18mm 32mm 18mm; }
        @font-face {
            font-family: 'Abyssinica';
            src: url('{{ public_path('fonts/AbyssinicaSIL-Regular.ttf') }}') format('truetype');
            font-style: normal;
            font-weight: normal;
        }
        @font-face {
            font-family: 'Abyssinica';
            src: url('{{ public_path('fonts/AbyssinicaSIL-Regular.ttf') }}') format('truetype');
            font-style: normal;
            font-weight: bold;
        }
        * { font-family: 'Abyssinica', serif; }
        body { font-family: 'Abyssinica', serif; color: #1f2937; font-size: 12pt; }

        /* Fixed header/footer repeat on every page (dompdf honours position:fixed). */
        .page-header {
            position: fixed;
            top: -30mm;
            left: 0;
            right: 0;
            height: 28mm;
            text-align: center;
        }
        .page-footer {
            position: fixed;
            bottom: -28mm;
            left: 0;
            right: 0;
            height: 26mm;
            text-align: center;
        }
        .page-header img { max-width: 100%; max-height: 28mm; }
        .page-footer img { max-width: 100%; max-height: 26mm; }

        .date-line { text-align: right; font-size: 11pt; margin: 8mm 0 4mm 0; }
        .date-line strong { color: #111827; }

        .info { text-align: left; font-size: 11pt; margin-bottom: 6mm; }
        .info .row { margin-bottom: 2mm; }
        .info strong { color: #111827; }

        .judges-heading { text-align: center; font-size: 11pt; margin: 6mm 0 2mm 0; }
        .judges-heading .jlabel { margin-bottom: 2.5mm; }
        .judges-heading .jlist { display: inline-block; text-align: left; }
        .judges-heading .jrow { margin-bottom: 1.5mm; }
        .judges-heading .jrow .jnum { display: inline-block; width: 8mm; text-align: right; }
        .judges-heading strong { color: #111827; }

        .body { line-height: 1.6; font-size: 12pt; text-align: justify; margin-bottom: 10mm; }
        .body table { width: 100%; border-collapse: collapse; }
        .body th, .body td { border: 1px solid #cbd5e1; padding: 4px 6px; }

        .signatures { width: 100%; margin-top: 14mm; text-align: center; }
        .signatures .sig {
            display: inline-block;
            width: 32%;
            text-align: center;
            vertical-align: bottom;
            font-size: 10pt;
        }
        .signatures .sig .signature-mark { height: 18mm; line-height: 18mm; }
        .signatures .sig .signature-mark img { max-width: 44mm; max-height: 16mm; vertical-align: bottom; }
        .signatures .sig .line { border-top: 1px solid #111827; margin: 0 6mm; padding-top: 3px; }
        .signatures .sig .name { font-weight: 600; }

        /* Fixed seal repeats on every page (dompdf honours position:fixed). */
        .page-seal {
            position: fixed;
            bottom: 2mm;
            right: 0;
            text-align: right;
        }
        .page-seal img { width: 30mm; height: auto; opacity: 0.95; }
    </style>
</head>
<body>
    {{-- Fixed header (repeats at the top of every page) --}}
    @if($template?->header_image_path)
    <div class="page-header">
        <img src="{{ public_path('storage/'.$template->header_image_path) }}" alt="Header">
    </div>
    @endif

    {{-- Fixed footer (repeats at the bottom of every page) --}}
    @if($template?->footer_image_path)
    <div class="page-footer">
        <img src="{{ public_path('storage/'.$template->footer_image_path) }}" alt="Footer">
    </div>
    @endif

    {{-- Fixed official seal — repeats on every page, only when approved --}}
    @if(!empty($sealPath))
    <div class="page-seal">
        <img src="{{ $sealPath }}" alt="Official Seal">
    </div>
    @endif

    {{-- 1. Decision date — top right --}}
    <div class="date-line">
        <strong>{{ __('court::decision_templates.pdf.date') }}:</strong>
        {{ \Modules\Court\Application\Support\EthiopianDate::format($decision->decision_date, fallback: '—') }}
    </div>

    {{-- 2. Case number, applicant, respondent — left, line by line --}}
    <div class="info">
        <div class="row"><strong>{{ __('court::decision_templates.pdf.case_number') }}:</strong> {{ $caseNumber ?: '—' }}</div>
        <div class="row"><strong>{{ __('court::decision_templates.pdf.applicant') }}:</strong> {{ $decision->applicant_full_name ?: '—' }}</div>
        <div class="row"><strong>{{ __('court::decision_templates.pdf.respondent') }}:</strong> {{ $decision->respondent_full_name ?: '—' }}</div>
    </div>

    {{-- 3. Three judges (numbered) — centered --}}
    <div class="judges-heading">
        <div class="jlabel"><strong>{{ __('court::decision_templates.pdf.judges') }}:</strong></div>
        <div class="jlist">
            @for($i = 0; $i < 3; $i++)
            <div class="jrow"><span class="jnum">{{ $i + 1 }}.</span> {{ $judgeNames[$i] ?? '—' }}</div>
            @endfor
        </div>
    </div>

    {{-- 4. Decision content --}}
    <div class="body">{!! $body !!}</div>

    {{-- 5. Three judges' signatures — one row, no table --}}
    <div class="signatures">
        @for($i = 0; $i < 3; $i++)
        <div class="sig">
            <div class="signature-mark">
                @if(!empty($judgeSignatures[$i]))
                <img src="{{ $judgeSignatures[$i] }}" alt="{{ $judgeNames[$i] ?? 'Judge signature' }}">
                @endif
            </div>
            <div class="line">
                <div class="name">{{ $judgeNames[$i] ?? '' }}</div>
            </div>
        </div>
        @endfor
    </div>
</body>
</html>
