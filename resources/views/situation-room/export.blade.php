<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $document['title'] }}</title>
@include('pdf.partials.typography', ['variant' => 'report'])
<style>
@page { size:A4 portrait; margin:10mm 10mm 12mm 10mm; }
*{box-sizing:border-box;}
body{font-weight:normal;font-size:8.5pt;color:#111827;margin:0;}
.rh{border-bottom:2px solid #1e3a8a;padding-bottom:5px;margin-bottom:8px;}
.rh h1{font-size:13pt;color:#1e3a8a;margin:0 0 2px;font-weight:normal;}
.rh p{font-size:8pt;color:#4b5563;margin:0;}
.sec{font-size:9.5pt;color:#1e3a8a;margin:12px 0 4px;border-left:3px solid #1e3a8a;padding-left:5px;page-break-after:avoid;}
.note{font-size:8pt;color:#6b7280;margin:0 0 6px;}
table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.5pt;}
th{background:#1e3a8a;color:#fff;font-weight:normal;padding:4px 3px;text-align:left;border:1px solid #1e3a8a;overflow-wrap:anywhere;}
td{border:1px solid #e5e7eb;padding:3px;vertical-align:top;overflow-wrap:anywhere;}
tr:nth-child(even) td{background:#f8fafc;}
.r{text-align:right;white-space:nowrap;}
.foot{margin-top:10px;border-top:1px solid #e5e7eb;padding-top:3px;font-size:7pt;color:#6b7280;}
</style>
</head>
<body>
<div class="rh">
    <h1>{{ $heading }} — {{ $document['title'] }}</h1>
    <p>{{ $labels['scope'] }}: {{ $document['scope'] }} · {{ $labels['period'] }}: {{ $document['period'] }} · {{ $labels['generated'] }}: {{ $document['generated_at'] }}</p>
</div>
@foreach ($document['blocks'] as $block)
    <div class="sec">{{ $block['title'] }}</div>
    @if ($block['notice'] !== null)
        <p class="note">{{ $block['notice'] }}</p>
    @else
        <table>
            <thead><tr>@foreach ($block['headings'] as $heading_)<th>{{ $heading_ }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($block['rows'] as $row)
                <tr>@foreach ($row as $i => $cell)<td class="{{ $i > 0 && is_numeric($cell) ? 'r' : '' }}">{{ $cell === null ? '—' : (is_float($cell) ? number_format($cell, 1) : $cell) }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endforeach
<div class="foot">{{ $labels['footer'] }}</div>
</body>
</html>
