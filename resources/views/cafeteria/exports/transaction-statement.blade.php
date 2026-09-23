<!doctype html>
<html lang="{{ $locale ?? app()->getLocale() }}" dir="ltr"><head><meta charset="utf-8"><title>{{ __('cafeteria-statement.title') }}</title>
<style>
@font-face { font-family: NotoEth; src: url("{{ storage_path('fonts/NotoSansEthiopic-Regular.ttf') }}") format('truetype'); }
@page { margin: 14mm 10mm; }
body { font-family: NotoEth, 'DejaVu Sans', sans-serif; font-size: 9px; color: #172033; }
h1 { font-size: 22px; font-weight: normal; margin: 0 0 8px; color: #173b63; }
.header { border-bottom: 3px solid #173b63; padding-bottom: 12px; }
.meta { line-height: 1.8; margin: 12px 0; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
thead { display: table-header-group; } tr { page-break-inside: avoid; }
th { background: #173b63; color: white; font-weight: normal; }
th,td { padding: 7px 4px; border: 1px solid #d6dde6; word-wrap: break-word; vertical-align: top; }
.amount { text-align: right; } .total td { background: #eaf0f7; }
.claim { text-align: right; font-size: 16px; padding: 14px; background: #eaf0f7; margin: 16px 0; }
.note { font-size: 9px; color: #526176; line-height: 1.6; }
.signatures { margin-top: 35px; } .signatures td { border: 0; border-top: 1px solid #64748b; padding-top: 8px; }
</style></head><body>
<div class="header"><h1>{{ __('cafeteria-statement.title') }}</h1>{{ config('app.name') }}</div>
<div class="meta">
{{ __('cafeteria-statement.provider') }}: {{ $providerName }}<br>
{{ __('cafeteria-statement.period') }}: {{ $periodLabel }}<br>
{{ __('cafeteria-statement.prepared') }}: {{ $actor }} — {{ $generatedAt }}<br>
{{ __('cafeteria-statement.filters') }}: {{ $statusLabel }}
{{ $filters['extra_only'] === '1' ? ' / '.__('cafeteria-statement.extra_only') : '' }}
</div>
<table><thead><tr>@foreach (['number','date','employee','provider','status','meal','subsidy','employee_payable','deduction'] as $key)<th>{{ __('cafeteria-statement.'.$key) }}</th>@endforeach</tr></thead><tbody>
@forelse ($rows as $row)
<tr><td>{{ $row['number'] }}</td><td>{{ $row['date'] }}</td><td>{{ $row['employee_name'] }}<br>{{ $row['employee_number'] }}</td><td>{{ $row['provider'] }}</td><td>{{ $row['status'] }}</td>
@foreach (['meal_amount','subsidy_amount_applied','employee_payable_amount','deduction_amount'] as $field)<td class="amount">{{ number_format((float) $row[$field], 2) }}</td>@endforeach</tr>
@empty <tr><td colspan="9">{{ __('cafeteria-statement.empty') }}</td></tr> @endforelse
<tr class="total"><td colspan="5">{{ __('cafeteria-statement.accepted_totals') }} ({{ $summary['accepted'] }})</td>@foreach (['meals','subsidy','employee_payable','deductions'] as $field)<td class="amount">{{ number_format((float) $summary[$field], 2) }}</td>@endforeach</tr>
</tbody></table>
<div class="claim">{{ __('cafeteria-statement.claim') }}: {{ number_format((float) $summary['subsidy'], 2) }}</div>
<p class="note">{{ __('cafeteria-statement.note') }}</p>
<table class="signatures"><tr><td>{{ __('cafeteria-statement.prepared') }}: {{ $actor }}</td><td>{{ __('cafeteria-statement.checked') }}: __________________</td><td>{{ __('cafeteria-statement.approved') }}: __________________</td></tr></table>
<script type="text/php">if (isset($pdf)) { $pdf->page_text(740, 570, "{PAGE_NUM} / {PAGE_COUNT}", null, 8); }</script>
</body></html>
