<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="ltr"><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>
@font-face { font-family: NotoEth; src: url("{{ storage_path('fonts/NotoSansEthiopic-Regular.ttf') }}") format('truetype'); }
@page { margin: 14mm 10mm; }
body { font-family: NotoEth, 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #172033; }
h1 { font-size: 18px; font-weight: normal; margin: 0 0 6px; color: #173b63; }
.header { border-bottom: 3px solid #173b63; padding-bottom: 10px; }
.meta { line-height: 1.7; margin: 10px 0; }
table { width: 100%; border-collapse: collapse; table-layout: auto; }
thead { display: table-header-group; } tr { page-break-inside: avoid; }
th { background: #173b63; color: white; font-weight: normal; text-align: left; }
th,td { padding: 5px 4px; border: 1px solid #d6dde6; word-wrap: break-word; vertical-align: top; }
.note { font-size: 8.5px; color: #526176; line-height: 1.6; margin-top: 12px; }
</style></head><body>
<div class="header"><h1>{{ $title }}</h1>{{ config('app.name') }}</div>
<div class="meta">{{ $period }}<br>{{ $generated }}</div>
<table><thead><tr>@foreach ($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead><tbody>
@forelse ($rows as $row)
<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
@empty <tr><td colspan="{{ count($headings) }}">—</td></tr> @endforelse
</tbody></table>
<p class="note">{{ $note }}</p>
<script type="text/php">if (isset($pdf)) { $pdf->page_text(760, 570, "{PAGE_NUM} / {PAGE_COUNT}", null, 8); }</script>
</body></html>
