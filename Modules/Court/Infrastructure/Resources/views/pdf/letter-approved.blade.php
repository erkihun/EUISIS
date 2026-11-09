@php
$bodyHtml = $letter->body ?? '';
$header = $letter->subject ?? 'Approved Letter';
$caseNo = $caseNumber ?: 'Case';
$ref = $letter->reference_number;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $header }}</title>
    <style>
        @page { margin: 28mm 20mm 28mm 20mm; }
        @font-face {
            font-family: 'Abyssinica';
            src: url('{{ public_path('fonts/AbyssinicaSIL-Regular.ttf') }}') format('truetype');
            font-style: normal;
            font-weight: normal;
        }
        body { font-family: 'Abyssinica', 'Nyala', DejaVu Serif, serif; color: #0f172a; font-size: 12pt; }
        h1 { font-size: 18pt; margin: 0 0 6mm 0; }
        .meta { font-size: 10pt; color: #475569; margin-bottom: 10mm; }
        .meta div { margin-bottom: 2mm; }
        .section { margin-bottom: 12mm; }
        .badge { display: inline-block; padding: 6px 10px; border-radius: 8px; background: #f1f5f9; color: #0f172a; font-size: 10pt; }
        .body { line-height: 1.5; }
        .footer { position: fixed; bottom: 10mm; left: 0; right: 0; text-align: center; font-size: 9pt; color: #64748b; }
    </style>
</head>
<body>
    <h1>{{ $header }}</h1>
    <div class="meta">
        <div><strong>Case:</strong> {{ $caseNo }}</div>
        @if($case?->title)
        <div><strong>Case Title:</strong> {{ $case->title }}</div>
        @endif
        @if($ref)
        <div><strong>Reference:</strong> {{ $ref }}</div>
        @endif
        @if($letter->cc)
        <div><strong>CC:</strong> {{ $letter->cc }}</div>
        @endif
    </div>

    <div class="section">
        <span class="badge">Approved Letter</span>
    </div>

    <div class="body">
        {!! $bodyHtml !!}
    </div>

    <div class="footer">
        Generated from court management system &mdash; {{ \Modules\Court\Application\Support\EthiopianDate::format(now(), withTime: true) }}
    </div>
</body>
</html>
