@php
    /** The certificate exactly as it was frozen when the request was accepted (ADR 0040). */
    $s = $language === 'en' ? 'en' : 'am';
    $organization = $certificate['organization'] ?? [];
    $employee = $certificate['employee'] ?? [];
    $signatory = $certificate['signatory'] ?? [];
    $lines = $certificate['lines'] ?? [];
    $withSalary = (bool) ($certificate['include_salary'] ?? false);
    $serving = ($certificate['last_day'] ?? null) === null;
    $opening = $t($serving ? 'opening_serving' : 'opening_served', [
        'name' => $employee["name_{$s}"] ?? '',
        'number' => $employee['employee_number'] ?? '',
        'organization' => $organization["name_{$s}"] ?? '',
        'from' => $date($certificate['first_day'] ?? null),
        'to' => $date($certificate['last_day'] ?? null),
    ]);
@endphp
<!doctype html>
<html lang="{{ $s }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $t('title') }} {{ $request->certificate_number }}</title>
    <style>
        @page { size: A4; margin: 20mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Noto Sans Ethiopic', 'Nyala', 'Abyssinica SIL', 'DejaVu Sans', sans-serif; color: #111; margin: 0; background: #f3f4f6; font-size: 12.5pt; line-height: 1.7; }
        .toolbar { max-width: 210mm; margin: 16px auto 0; text-align: end; }
        .toolbar button { font: inherit; font-size: 11pt; padding: 8px 20px; border: 1px solid #1f2937; background: #1f2937; color: #fff; border-radius: 6px; cursor: pointer; }
        main { max-width: 210mm; min-height: 297mm; margin: 12px auto 24px; background: #fff; padding: 20mm 18mm; box-shadow: 0 1px 4px rgba(0, 0, 0, .15); display: flex; flex-direction: column; }
        header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 14px; }
        header img { max-height: 70px; max-width: 160px; margin-bottom: 6px; }
        header h1 { font-size: 15pt; margin: 0; }
        header p { margin: 0; font-size: 11pt; }
        .meta { display: flex; justify-content: flex-end; }
        .meta dl { margin: 0; }
        .meta div { display: flex; gap: 8px; }
        .meta dt { font-weight: 700; }
        .meta dd { margin: 0; }
        .to { font-weight: 700; margin: 14px 0 4px; }
        h2 { text-align: center; font-size: 14pt; text-decoration: underline; margin: 14px 0; }
        p { margin: 0 0 10px; text-align: justify; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; font-size: 10.5pt; line-height: 1.4; }
        th, td { border: 1px solid #333; padding: 5px 6px; text-align: start; vertical-align: top; }
        th { background: #f1f1f1; font-weight: 700; }
        td.num { text-align: end; white-space: nowrap; }
        .sign { margin-top: 28px; display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; }
        .sign .who { min-width: 55%; }
        .sign .line { border-bottom: 1px solid #111; height: 46px; margin-bottom: 4px; }
        .sign .seal { width: 38mm; height: 38mm; border: 1px dashed #888; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #888; font-size: 10pt; }
        footer { margin-top: auto; padding-top: 14px; border-top: 1px solid #999; font-size: 9pt; color: #333; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            main { margin: 0; padding: 0; box-shadow: none; min-height: 0; max-width: none; }
            .sign .seal { border-color: transparent; color: transparent; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" id="print-certificate">{{ $t('print') }}</button></div>
    <main>
        <header>
            @if (! empty($organization['logo_path']))
                <img src="{{ '/storage/'.$organization['logo_path'] }}" alt="">
            @endif
            <h1>{{ $organization["name_{$s}"] ?? '' }}</h1>
            @if ($s === 'am' && ! empty($organization['name_en']) && $organization['name_en'] !== ($organization['name_am'] ?? null))
                <p>{{ $organization['name_en'] }}</p>
            @endif
        </header>

        <div class="meta">
            <dl>
                <div><dt>{{ $t('number') }}:</dt><dd>{{ $request->certificate_number }}</dd></div>
                <div><dt>{{ $t('date') }}:</dt><dd>{{ $date($certificate['issued_on'] ?? null) }}</dd></div>
            </dl>
        </div>

        <div class="to">{{ filled($request->addressee) ? $t('to').' '.$request->addressee : $t('to_whom') }}</div>

        <h2>{{ $t('title') }}</h2>

        <p>{{ $opening }}</p>

        <table>
            <thead>
                <tr>
                    <th>{{ $t('no') }}</th>
                    <th>{{ $t('employer') }}</th>
                    <th>{{ $t('position') }}</th>
                    <th>{{ $t('from') }}</th>
                    <th>{{ $t('until') }}</th>
                    <th>{{ $t('duration') }}</th>
                    @if ($withSalary)
                        <th>{{ $t('salary') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $index => $line)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $line["employer_{$s}"] ?? '' }}</td>
                        <td>
                            {{ $line["position_{$s}"] ?? '' }}
                            @if (! empty($line["unit_{$s}"]))
                                <br><small>{{ $line["unit_{$s}"] }}</small>
                            @endif
                        </td>
                        <td>{{ $date($line['started_on'] ?? null) }}</td>
                        <td>{{ ($line['ended_on'] ?? null) === null ? $t('present') : $date($line['ended_on']) }}</td>
                        <td>{{ $length($line['duration'] ?? []) }}</td>
                        @if ($withSalary)
                            <td class="num">{{ ($line['monthly_salary'] ?? null) === null ? '—' : number_format((float) $line['monthly_salary'], 2) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p><strong>{{ $t('total', ['length' => $length($certificate['total'] ?? [])]) }}</strong></p>
        <p>{{ $t('purpose', ['purpose' => preg_replace('/[\s.።]+$/u', '', (string) $request->purpose)]) }}</p>
        <p>{{ $t('closing') }}</p>

        <div class="sign">
            <div class="who">
                <div class="line"></div>
                <div><strong>{{ $signatory["name_{$s}"] ?? '' }}</strong></div>
                <div>{{ $signatory["title_{$s}"] ?? '' }}</div>
                <div><small>{{ $t('signature') }}</small></div>
            </div>
            <div class="seal">{{ $t('seal') }}</div>
        </div>

        <footer>{{ $t('verify', ['code' => $request->verification_code]) }}</footer>
    </main>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.getElementById('print-certificate').addEventListener('click', function () { window.print(); });</script>
</body>
</html>
