{{--
    Typography check sheet for the PDF renderer. Development/test only — the
    route that renders it is registered outside production (routes/web.php).
    Everything here is fixed sample text; nothing is read from the database.
--}}
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="utf-8">
    <title>Typography test — {{ $variant }}</title>
    @include('pdf.partials.typography', ['variant' => $variant])
    <style>
        @page { size: A4; margin: 16mm 14mm; }
        body { font-size: 10.5pt; color: #0f172a; line-height: 1.6; }
        h1 { font-size: 18pt; margin: 0 0 2px; }
        h2 { font-size: 13pt; margin: 18px 0 6px; color: #122170; }
        .meta { color: #5b6472; font-size: 9pt; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #cbd2dc; padding: 5px 7px; text-align: left; vertical-align: top; }
        th { background: #eef1fa; font-weight: bold; }
        .num { text-align: right; }
        .code { font-family: monospace; }
    </style>
</head>
<body>
    <h1>EUISIS Typography Test</h1>
    <h1>የሰራተኞች አስተዳደር</h1>
    <div class="meta">Variant: {{ $variant }} · Font: {{ $family }}</div>

    <h2>የተቋም መዋቅር — Organization structure</h2>
    <p>
        ሰራተኛ ቁጥር AAC-00123456 · የሰራተኛ መታወቂያ ID-2026-000781 ·
        የካፍቴሪያ አገልግሎት ETB 1,250.00 · የአፈጻጸም አስተዳደር 2018 ዓ.ም. (2026-09-28)።
        Mixed sentence: The employee (ሰራተኛ) completed 12 of 15 tasks — 80% on time.
    </p>

    <p><strong>Bold / ደማቅ: የሰራተኞች አስተዳደር — Employee Management</strong></p>
    <p>Regular / መደበኛ: የተቋም መዋቅር — Organization Structure</p>
    <p><em>Italic request (maps to the regular face): የሰራተኛ መታወቂያ</em></p>

    <h2>Table / ሰንጠረዥ</h2>
    <table>
        <thead>
            <tr>
                <th>ቁጥር / No.</th>
                <th>መግለጫ / Description</th>
                <th>ቀን / Date</th>
                <th class="num">መጠን / Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="code">POS-0001</td>
                <td>የሰራተኞች አስተዳደር</td>
                <td>28/09/2026</td>
                <td class="num">1,250.00</td>
            </tr>
            <tr>
                <td class="code">POS-0002</td>
                <td>የካፍቴሪያ አገልግሎት — Cafeteria service</td>
                <td>መስከረም 18 ቀን 2019</td>
                <td class="num">45.00</td>
            </tr>
            <tr>
                <td class="code">POS-0003</td>
                <td>የአፈጻጸም አስተዳደር</td>
                <td>2026-09-28</td>
                <td class="num">0.00</td>
            </tr>
        </tbody>
    </table>

    <h2>Punctuation / ሥርዓተ ነጥብ</h2>
    <p>፡ ። ፣ ፤ ፥ ፦ ፧ ፨ · ፩ ፪ ፫ ፬ ፭ ፮ ፯ ፰ ፱ ፲ · 0123456789</p>
</body>
</html>
