<!doctype html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
<meta charset="utf-8">
<title>{{ __('transport.manifest.title') }} {{ $trip->trip_number }}</title>
@include('pdf.partials.typography', ['variant' => 'report'])
<style>
@page { size:A4 portrait; margin:12mm; }
*{box-sizing:border-box;}
body{font-weight:normal;font-size:9pt;color:#111827;margin:0;}
.rh{border-bottom:2px solid #1e3a8a;padding-bottom:6px;margin-bottom:8px;}
.rh h1{font-size:14pt;color:#1e3a8a;margin:0 0 2px;font-weight:normal;}
.meta{font-size:8pt;color:#374151;margin-bottom:8px;} .lbl{color:#9ca3af;}
table{width:100%;border-collapse:collapse;margin-top:6px;}
th{background:#1e3a8a;color:#fff;font-weight:normal;padding:4px 5px;text-align:left;font-size:8pt;}
td{border:1px solid #e5e7eb;padding:3px 5px;font-size:8pt;vertical-align:top;}
tr:nth-child(even) td{background:#f8fafc;}
.c{text-align:center;} .r{text-align:right;}
.s-rejected{color:#6b7280;} .s-voided{color:#92400e;text-decoration:line-through;}
.foot{margin-top:8px;border-top:1px solid #e5e7eb;padding-top:3px;font-size:7pt;color:#9ca3af;text-align:center;}
</style>
</head>
<body>
<div class="rh">
  <h1>{{ __('transport.manifest.title') }} — {{ $trip->trip_number }}</h1>
</div>
<div class="meta">
  <span><span class="lbl">{{ __('transport.route') }}:</span> {{ $trip->route?->route_code }} {{ $locale === 'am' ? ($trip->route?->name_am ?: $trip->route?->name_en) : $trip->route?->name_en }}</span> &nbsp;|&nbsp;
  <span><span class="lbl">{{ __('transport.direction') }}:</span> {{ $trip->direction ? __('transport.directions.'.$trip->direction->value) : '—' }}</span> &nbsp;|&nbsp;
  <span><span class="lbl">{{ __('transport.tripDate') }}:</span> {{ $tripDate }} {{ $trip->departure_time ? substr((string) $trip->departure_time, 0, 5) : '' }}</span> &nbsp;|&nbsp;
  <span><span class="lbl">{{ __('transport.vehicle') }}:</span> {{ $trip->vehicle?->plate_number ?? '—' }}</span> &nbsp;|&nbsp;
  <span><span class="lbl">{{ __('transport.provider') }}:</span> {{ $locale === 'am' ? ($trip->provider?->name_am ?: $trip->provider?->name_en) : $trip->provider?->name_en }}</span>
  <br>
  <span><span class="lbl">{{ __('transport.manifest.boarded') }}:</span> {{ $totals['accepted'] }}</span> &nbsp;|&nbsp;
  <span><span class="lbl">{{ __('transport.manifest.expected') }}:</span> {{ $totals['expected_riders'] }}</span> &nbsp;|&nbsp;
  <span><span class="lbl">{{ __('transport.capacity') }}:</span> {{ $trip->effectiveCapacity() ?? '—' }}</span> &nbsp;|&nbsp;
  <span><span class="lbl">{{ __('transport.manifest.manual') }}:</span> {{ $totals['manual'] }}</span> &nbsp;|&nbsp;
  <span><span class="lbl">{{ __('transport.statement.generated_at') }}:</span> {{ $generatedAt }}</span>
</div>
<table>
  <tr>
    <th class="c">#</th><th>{{ __('transport.export.columns.employee_number') }}</th><th>{{ __('transport.export.columns.employee_name') }}</th>
    @if ($staffTier)<th>{{ __('transport.export.columns.organization') }}</th>@endif
    <th>{{ __('transport.export.columns.time') }}</th><th>{{ __('transport.export.columns.stop') }}</th><th>{{ __('transport.export.columns.method') }}</th><th>{{ __('transport.export.columns.status') }}</th>
  </tr>
  @foreach ($rows as $i => $row)
  <tr class="s-{{ $row['status'] }}">
    <td class="c">{{ $i + 1 }}</td><td>{{ $row['employee_number'] }}</td><td>{{ $row['full_name'] }}</td>
    @if ($staffTier)<td>{{ $locale === 'am' ? ($row['organization_am'] ?: $row['organization']) : $row['organization'] }}</td>@endif
    <td>{{ $row['boarded_at'] ? \Illuminate\Support\Carbon::parse($row['boarded_at'])->format('H:i') : '' }}</td>
    <td>{{ $row['stop'] ? ($locale === 'am' ? ($row['stop']['name_am'] ?: $row['stop']['name_en']) : $row['stop']['name_en']) : '—' }}</td>
    <td>{{ __('transport.methods.'.$row['verification_method']) }}</td>
    <td>{{ __('transport.statuses.'.$row['status']) }}@if ($row['status'] !== 'accepted' && $row['result_code']) — {{ __('transport.reasons.'.$row['result_code']) }}@endif</td>
  </tr>
  @endforeach
</table>
<div class="foot">{{ __('transport.manifest.footer') }}</div>
</body>
</html>
