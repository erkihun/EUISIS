<!doctype html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
<meta charset="utf-8">
<title>{{ __('transport.statement.title') }} {{ $settlement->settlement_number }}</title>
@include('pdf.partials.typography', ['variant' => 'report'])
<style>
@page { size:A4 portrait; margin:12mm 12mm 14mm 12mm; }
*{box-sizing:border-box;}
body{font-weight:normal;font-size:9pt;color:#111827;margin:0;}
.rh{border-bottom:2px solid #1e3a8a;padding-bottom:6px;margin-bottom:8px;}
.rh h1{font-size:14pt;color:#1e3a8a;margin:0 0 2px;font-weight:normal;}
.rh p{font-size:8.5pt;color:#6b7280;margin:0;}
.meta{font-size:8pt;color:#374151;margin-bottom:8px;}
.lbl{color:#9ca3af;}
table{width:100%;border-collapse:collapse;margin:6px 0 10px;}
td,th{border:1px solid #d1d5db;padding:4px 6px;font-size:8.5pt;vertical-align:top;}
th{background:#f1f5f9;text-align:left;font-weight:normal;}
.r{text-align:right;white-space:nowrap;}
.hl td{background:#fef3c7;} .tot td{background:#dbeafe;font-size:9.5pt;}
.sec{font-size:10pt;color:#1e3a8a;margin:12px 0 4px;border-left:3px solid #1e3a8a;padding-left:6px;}
.gap{color:#92400e;font-size:8pt;}
.sigs{margin-top:18px;width:100%;}
.sig-cell{display:inline-block;width:32%;vertical-align:bottom;margin-right:1%;}
.sig-line{border-top:1px solid #374151;margin-top:28px;padding-top:3px;font-size:8pt;}
.sig-lbl{font-size:7.5pt;color:#6b7280;}
.foot{margin-top:8px;border-top:1px solid #e5e7eb;padding-top:3px;font-size:7pt;color:#9ca3af;text-align:center;}
</style>
</head>
<body>
<div class="rh">
  <h1>{{ __('transport.statement.title') }} — {{ $settlement->settlement_number }}</h1>
  <p>{{ __('transport.provider') }}: <strong>{{ $locale === 'am' ? ($settlement->provider?->name_am ?: $settlement->provider?->name_en) : $settlement->provider?->name_en }}</strong>
     &nbsp;|&nbsp; {{ __('transport.statement.contract') }}: {{ $settlement->contract_snapshot['contract_reference'] ?? '—' }} v{{ $settlement->contract_snapshot['version_no'] ?? '—' }}
     &nbsp;|&nbsp; {{ __('transport.statement.model') }}: {{ __('transport.settlementModels.'.($settlement->contract_snapshot['settlement_model'] ?? 'other_approved_model')) }}</p>
</div>
<div class="meta">
  <span><span class="lbl">{{ __('transport.statement.period') }}:</span> {{ $periodStart }} &ndash; {{ $periodEnd }}</span> &nbsp;&nbsp;
  <span><span class="lbl">{{ __('transport.status') }}:</span> {{ __('transport.statuses.'.$settlement->status->value) }}</span> &nbsp;&nbsp;
  <span><span class="lbl">{{ __('transport.statement.generated_at') }}:</span> {{ $generatedAt }}</span>
</div>

<div class="sec">{{ __('transport.statement.verified_basis') }}</div>
<table>
  <tr><th>{{ __('transport.export.columns.verified_boardings') }}</th><td class="r">{{ number_format($settlement->verified_boardings) }}</td>
      <th>{{ __('transport.export.columns.unique_riders') }}</th><td class="r">{{ number_format($settlement->unique_riders) }}</td></tr>
  <tr><th>{{ __('transport.export.columns.employee_days') }}</th><td class="r">{{ number_format($settlement->employee_days) }}</td>
      <th>{{ __('transport.export.columns.completed_trips') }}</th><td class="r">{{ number_format($settlement->completed_trips) }}</td></tr>
  <tr><th>{{ __('transport.export.columns.inbound') }}</th><td class="r">{{ number_format($settlement->basis['inbound_boardings'] ?? 0) }}</td>
      <th>{{ __('transport.export.columns.outbound') }}</th><td class="r">{{ number_format($settlement->basis['outbound_boardings'] ?? 0) }}</td></tr>
  <tr><th>{{ __('transport.export.columns.cancelled_trips') }}</th><td class="r">{{ number_format($settlement->cancelled_trips) }}</td>
      <th>{{ __('transport.export.columns.manual') }}</th><td class="r">{{ number_format($settlement->basis['manual_boardings'] ?? 0) }}</td></tr>
</table>

<div class="sec">{{ __('transport.statement.calculation') }}</div>
<table>
  <tr><th>{{ __('transport.statement.line') }}</th><th class="r">{{ __('transport.statement.quantity') }}</th><th class="r">{{ __('transport.statement.rate') }}</th><th class="r">{{ __('transport.statement.amount') }} ({{ $settlement->currency_code }})</th></tr>
  @forelse (($settlement->basis['lines'] ?? []) as $line)
  <tr><td>{{ __('transport.statement.lines.'.$line['label']) }}</td><td class="r">{{ $line['quantity'] !== null ? number_format($line['quantity']) : '—' }}</td><td class="r">{{ $line['rate'] ?? '—' }}</td><td class="r">{{ $line['amount'] }}</td></tr>
  @empty
  <tr><td colspan="4" class="gap">{{ __('transport.statement.no_amount') }}</td></tr>
  @endforelse
  <tr class="hl"><td colspan="3">{{ __('transport.statement.calculated_amount') }}</td><td class="r">{{ $reconciliation['calculated_amount'] ?? '—' }}</td></tr>
  <tr><td colspan="3">{{ __('transport.statement.claimed_amount') }}</td><td class="r">{{ $reconciliation['claimed_amount'] ?? '—' }}</td></tr>
  <tr><td colspan="3">{{ __('transport.statement.difference') }}</td><td class="r">{{ $reconciliation['difference'] ?? '—' }}</td></tr>
  <tr><td colspan="3">{{ __('transport.statement.adjustments') }}</td><td class="r">{{ $reconciliation['adjustment_total'] }}</td></tr>
  <tr class="tot"><td colspan="3">{{ __('transport.statement.approved_amount') }}</td><td class="r">{{ $reconciliation['approved_amount'] ?? '—' }}</td></tr>
</table>
@if (!empty($settlement->policy_gaps))
<p class="gap">{{ __('transport.statement.policy_gaps') }}: {{ implode(', ', $settlement->policy_gaps) }}</p>
@endif

@if ($settlement->adjustments->isNotEmpty())
<div class="sec">{{ __('transport.statement.adjustments') }}</div>
<table>
  <tr><th>{{ __('transport.statement.origin') }}</th><th>{{ __('transport.statement.reason') }}</th><th>{{ __('transport.status') }}</th><th class="r">{{ __('transport.statement.amount') }}</th></tr>
  @foreach ($settlement->adjustments as $adjustment)
  <tr><td>{{ __('transport.adjustmentOrigins.'.$adjustment->origin->value) }}</td><td>{{ $adjustment->reason }}</td><td>{{ __('transport.statuses.'.$adjustment->status->value) }}</td><td class="r">{{ $adjustment->amount }}</td></tr>
  @endforeach
</table>
@endif

@if (!empty($settlement->basis['by_route']))
<div class="sec">{{ __('transport.byRoute') }}</div>
<table>
  <tr><th>{{ __('transport.route') }}</th><th class="r">{{ __('transport.export.columns.boardings') }}</th><th class="r">{{ __('transport.export.columns.unique_riders') }}</th></tr>
  @foreach ($settlement->basis['by_route'] as $row)
  @php($route = $routes->get($row['route_id']))
  <tr><td>{{ $route?->route_code }} {{ $locale === 'am' ? ($route?->name_am ?: $route?->name_en) : $route?->name_en }}</td><td class="r">{{ number_format($row['boardings']) }}</td><td class="r">{{ number_format($row['riders']) }}</td></tr>
  @endforeach
</table>
@endif

<div class="sigs">
  <div class="sig-cell"><div class="sig-line">{{ $settlement->computedBy?->name ?? '&nbsp;' }}</div><div class="sig-lbl">{{ __('transport.statement.computed_by') }}</div></div>
  <div class="sig-cell"><div class="sig-line">{{ $settlement->reviewedBy?->name ?? '&nbsp;' }}</div><div class="sig-lbl">{{ __('transport.statement.reviewed_by') }}</div></div>
  <div class="sig-cell"><div class="sig-line">{{ $settlement->approvedBy?->name ?? '&nbsp;' }}</div><div class="sig-lbl">{{ __('transport.statement.approved_by') }}</div></div>
</div>
@if ($settlement->payment_reference)
<p class="meta">{{ __('transport.statement.payment_reference') }}: {{ $settlement->payment_reference }}</p>
@endif
<div class="foot">{{ __('transport.statement.footer') }}</div>
</body>
</html>
