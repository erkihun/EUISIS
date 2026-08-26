<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Feedback and Suggestion QR</title>
    {{--
        Printed and placed at a service desk, so the sheet is deliberately
        sparse: a large scannable code, a short bilingual instruction, and the
        office context.

        It names NO employee. The sheet may sit in a public waiting area, and
        the page the code opens identifies the desk rather than the person, so
        printing a name here would defeat that.
    --}}
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; margin: 0; padding: 0; }
        .page { padding: 60px 50px; text-align: center; }
        .title { font-size: 22pt; font-weight: bold; margin: 0 0 4px; color: #1e40af; }
        .title-am { font-size: 15pt; margin: 0 0 28px; color: #1e40af; }
        .prompt { font-size: 13pt; margin: 0 0 4px; }
        .prompt-am { font-size: 12pt; color: #374151; margin: 0 0 28px; }
        .qr { margin: 0 auto 24px; width: 260px; height: 260px; }
        .qr img { width: 260px; height: 260px; }
        .context { margin: 8px auto 0; font-size: 11pt; color: #374151; }
        .context div { margin-bottom: 3px; }
        .url { margin-top: 26px; font-family: monospace; font-size: 8pt; color: #6b7280; word-break: break-all; }
        .footer { margin-top: 36px; padding-top: 14px; border-top: 1px solid #d1d5db; font-size: 9pt; color: #6b7280; }
    </style>
</head>
<body>
    <div class="page">
        {{-- Matches the public page heading so the printed sheet and the page
             it opens read as one thing to the client. --}}
        <div class="title">Feedback and Suggestion</div>
        <div class="title-am">ግብረመልስ እና አስተያየት</div>

        <div class="prompt">Scan this code with your phone camera to give feedback.</div>
        <div class="prompt-am">ግብረመልስ ለመስጠት ይህን ኮድ በስልክዎ ካሜራ ይስካኑ።</div>

        <div class="qr">
            @if ($qr !== '')
                <img src="{{ $qr }}" alt="Service feedback QR code">
            @endif
        </div>

        <div class="context">
            @if ($organization)
                <div><strong>{{ $organization }}</strong></div>
            @endif
            @if ($unit)
                <div>{{ $unit }}</div>
            @endif
            @if ($position)
                <div>{{ $position }}</div>
            @endif
        </div>

        <div class="url">{{ $url }}</div>

        <div class="footer">
            Addis Ababa City Administration — Employee Unified ID and Service Integration System
        </div>
    </div>
</body>
</html>
