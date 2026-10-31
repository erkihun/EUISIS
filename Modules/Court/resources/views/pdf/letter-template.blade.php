@php
$caseNumber = $letter->case_number ?? $case?->case_number ?? '';
$caseTitle = $case->title ?? '';
$ref = $letter->reference_number ?? '';
$cc = $letter->cc ?? '';
$subject = $letter->subject ?? ($letter->template->subject_prefix ?? 'Letter');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Letter {{ $ref ?: $caseNumber }}</title>
    <style>
        @page { margin: 20mm 18mm 24mm 18mm; }
        @font-face {
            font-family: 'Abyssinica';
            src: url('{{ public_path('fonts/AbyssinicaSIL-Regular.ttf') }}') format('truetype');
            font-style: normal;
            font-weight: normal;
        }
        body { font-family: 'Abyssinica', 'Nyala', DejaVu Serif, serif; color: #1f2937; font-size: 12pt; }
        .header img, .footer img { width: 100%; height: auto; }
        .meta { margin: 12mm 0 8mm 0; }
        .meta-row { display: flex; justify-content: space-between; font-size: 11pt; margin-bottom: 3mm; }
        .meta strong { color: #111827; }
        .subject { text-align: center; font-weight: 700; font-size: 14pt; margin: 10mm 0 6mm 0; text-decoration: underline; }
        .body { line-height: 1.5; font-size: 12pt; }
        .cc { margin-top: 10mm; font-size: 11pt; }
        .cc ul { margin: 2mm 0 0 5mm; padding: 0; }
        .cc li { margin-bottom: 1.5mm; }
        .badge { display: inline-block; margin-top: 6mm; padding: 6px 10px; background: #eef2ff; color: #4338ca; border-radius: 8px; font-size: 10pt; font-weight: 700; }
        .footer { margin-top: 16mm; text-align: center; font-size: 10pt; color: #6b7280; }
    </style>
</head>
<body>
    <div class="header">
        @if($letter->template?->header_image_path)
        <img src="{{ public_path('storage/'.$letter->template->header_image_path) }}" alt="Header">
        @endif
    </div>

    <div class="meta">
        <div class="meta-row">
            <div><strong>Case:</strong> {{ $caseNumber ?: '—' }}</div>
            <div><strong>Date:</strong> {{ \Modules\Court\Application\Support\EthiopianDate::format($letter->created_at) }}</div>
        </div>
        <div class="meta-row">
            <div><strong>Case Title:</strong> {{ $caseTitle ?: '—' }}</div>
            <div><strong>Reference:</strong> {{ $ref ?: '—' }}</div>
        </div>
        <div class="meta-row">
            <div><strong>CC:</strong> {{ $cc ?: '—' }}</div>
        </div>
    </div>

    <div class="subject">{{ $subject }}</div>

    <div class="body">{!! $letter->body !!}</div>

    @if($cc)
    <div class="cc">
        <strong>CC recipients:</strong>
        <ul>
            <li>{{ $cc }}</li>
        </ul>
    </div>
    @endif

    <div class="badge">Approved Letter</div>

    @if($letter->template?->footer_image_path)
    <div class="footer">
        <img src="{{ public_path('storage/'.$letter->template->footer_image_path) }}" alt="Footer">
    </div>
    @else
    <div class="footer">
        Generated from court management system — {{ \Modules\Court\Application\Support\EthiopianDate::format(now(), withTime: true) }}
    </div>
    @endif
</body>
</html>
