<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Public Feedback QR</title>
    {{--
        The institutional sheet (brief §70): the organization's name and logo,
        a large scannable code, one bilingual instruction and the URL in text.
        It carries no secret beyond the public URL the code itself encodes.
    --}}
    @include('pdf.partials.typography', ['variant' => 'report'])
    <style>
        body { color: #1a1a1a; margin: 0; padding: 0; }
        .page { padding: 60px 50px; text-align: center; }
        .logo { margin: 0 auto 14px; height: 72px; }
        .logo img { height: 72px; }
        .org { font-size: 16pt; font-weight: bold; margin: 0 0 2px; color: #111827; }
        .org-am { font-size: 14pt; margin: 0 0 22px; color: #111827; }
        .title { font-size: 22pt; font-weight: bold; margin: 0 0 4px; color: #1e40af; }
        .title-am { font-size: 15pt; margin: 0 0 24px; color: #1e40af; }
        .prompt { font-size: 13pt; margin: 0 0 4px; }
        .prompt-am { font-size: 12pt; color: #374151; margin: 0 0 24px; }
        .qr { margin: 0 auto 24px; width: 260px; height: 260px; }
        .qr img { width: 260px; height: 260px; }
        .options { font-size: 11pt; color: #374151; margin: 0 0 8px; }
        .url { margin-top: 22px; font-family: monospace; font-size: 8pt; color: #6b7280; word-break: break-all; }
        .footer { margin-top: 36px; padding-top: 14px; border-top: 1px solid #d1d5db; font-size: 9pt; color: #6b7280; }
    </style>
</head>
<body>
    <div class="page">
        @if ($logo)
            <div class="logo"><img src="{{ $logo }}" alt=""></div>
        @endif
        <div class="org">{{ $organization_en }}</div>
        @if ($organization_am)
            <div class="org-am">{{ $organization_am }}</div>
        @endif

        <div class="title">Public Feedback</div>
        <div class="title-am">የህዝብ አስተያየት</div>

        <div class="prompt">Scan this code with your phone camera to send feedback, a suggestion or a complaint about this institution.</div>
        <div class="prompt-am">ስለዚህ ተቋም አስተያየት፣ ጥቆማ ወይም ቅሬታ ለመላክ ይህን ኮድ በስልክዎ ካሜራ ይስካኑ።</div>

        <div class="qr">
            @if ($qr !== '')
                <img src="{{ $qr }}" alt="Public feedback QR code">
            @endif
        </div>

        <div class="options">Feedback · አስተያየት &nbsp;|&nbsp; Suggestion · ጥቆማ &nbsp;|&nbsp; Complaint · ቅሬታ</div>

        <div class="url">{{ $url }}</div>

        <div class="footer">
            Addis Ababa City Administration — Employee Unified ID and Service Integration System
        </div>
    </div>
</body>
</html>
