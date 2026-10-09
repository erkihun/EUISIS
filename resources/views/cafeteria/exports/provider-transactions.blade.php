<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<title>{{ $paymentClaim ? __('provider-portal.cafeteria_payment_claim_export') : __('provider-portal.provider_transaction_export') }}</title>
@include('pdf.partials.typography', ['variant' => 'report'])
<style>
@page { size:A4 landscape; margin:8mm 8mm 10mm 8mm; }
*{box-sizing:border-box;}
body{font-weight:normal;font-size:8.5pt;color:#111827;margin:0;}
.rh{border-bottom:2px solid #1e3a8a;padding-bottom:5px;margin-bottom:8px;}
.rh h1{font-size:13pt;color:#1e3a8a;margin:0 0 2px;font-weight:normal;}
.rh p{font-size:8pt;color:#6b7280;margin:0;}
.meta{font-size:7.5pt;color:#374151;margin-bottom:6px;}
.lbl{color:#9ca3af;}
.sum{width:56%;border-collapse:collapse;margin:6px 0 10px;}
.sum td{border:1px solid #d1d5db;padding:4px 7px;font-size:8.5pt;}
.sum td:first-child{background:#f1f5f9;width:62%;}
.sum td:last-child{text-align:right;}
.sum .hl td{background:#fef3c7;}
.sum .tot td{background:#dbeafe;font-size:9pt;}
.sigs{margin-top:16px;width:90%;}
.sig-row{display:table;width:100%;}
.sig-cell{display:table-cell;width:33%;padding-right:12px;vertical-align:bottom;}
.sig-line{border-top:1px solid #374151;margin-top:26px;padding-top:3px;font-size:8pt;}
.sig-lbl{font-size:7.5pt;color:#6b7280;}
.sec{font-size:9pt;color:#1e3a8a;margin:10px 0 4px;border-left:3px solid #1e3a8a;padding-left:5px;}
.txn{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.5pt;margin-top:4px;}
.txn col.no{width:3%;} .txn col.num{width:11%;} .txn col.dt{width:9%;} .txn col.tm{width:6%;}
.txn col.en{width:6.5%;} .txn col.ename{width:13%;} .txn col.inst{width:12.5%;}
.txn col.mode{width:7%;} .txn col.days{width:4%;} .txn col.sub{width:7.5%;}
.txn col.epay{width:7.5%;} .txn col.stat{width:12%;}
.txn th{background:#1e3a8a;color:#fff;font-weight:normal;padding:4px 3px;text-align:left;
        border:1px solid #1e3a8a;word-wrap:break-word;overflow-wrap:anywhere;font-size:7pt;}
.txn td{border:1px solid #e5e7eb;padding:3px;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere;}
.txn tr:nth-child(even) td{background:#f8fafc;}
.r{text-align:right;white-space:nowrap;} .c{text-align:center;} .mn{font-size:7pt;color:#374151;}
.s-accepted{color:#065f46;} .s-reversed{color:#92400e;} .s-rejected{color:#6b7280;}
.foot{margin-top:5px;border-top:1px solid #e5e7eb;padding-top:3px;font-size:7pt;color:#9ca3af;text-align:center;}
</style>
</head>
<body>

<div class="rh">
  <h1>{{ $paymentClaim ? __('provider-portal.cafeteria_payment_claim_export') : __('provider-portal.provider_transaction_export') }}</h1>
  <p>{{ __('provider-portal.provider') }}: <strong>{{ $summary['provider_name'] }}</strong> &nbsp;|&nbsp; {{ __('provider-portal.assigned_institution') }}: {{ $summary['assigned_institution'] }}</p>
</div>

<div class="meta">
  <span><span class="lbl">{{ __('provider-portal.claim_period') }}:</span> {{ $periodStart }} &ndash; {{ $periodEnd }}</span>
  &nbsp;&nbsp;
  <span><span class="lbl">{{ __('provider-portal.generated_by') }}:</span> {{ $actor->name }}</span>
  &nbsp;&nbsp;
  <span><span class="lbl">{{ __('provider-portal.generated_at') }}:</span> {{ $generatedAt }}</span>
</div>

<table class="sum">
  <tr><td>{{ __('provider-portal.total_transactions') }}</td><td>{{ number_format($summary['total_transactions']) }}</td></tr>
  <tr><td>{{ __('provider-portal.accepted_transactions') }}</td><td>{{ number_format($summary['accepted_transactions']) }}</td></tr>
  <tr><td>{{ __('provider-portal.rejected_transactions') }}</td><td>{{ number_format($summary['rejected_transactions']) }}</td></tr>
  @if (($summary['total_consumed_days'] ?? 0) > 0)
  <tr><td>{{ __('provider-portal.total_consumed_days') }}</td><td>{{ number_format($summary['total_consumed_days']) }}</td></tr>
  @endif
  @if (($summary['total_food_orders_served'] ?? 0) > 0)
  <tr><td>{{ __('provider-portal.total_food_orders_served') }}</td><td>{{ number_format($summary['total_food_orders_served']) }}</td></tr>
  @endif
  <tr class="hl"><td>{{ __('provider-portal.total_subsidy_payable') }}</td><td>{{ number_format((float)$summary['total_subsidy_payable'],2) }}</td></tr>
  <tr><td>{{ __('provider-portal.employee_payable_amount') }}</td><td>{{ number_format((float)$summary['total_employee_payable'],2) }}</td></tr>
  @if ((float)($summary['reversal_amount'] ?? 0) > 0)
  <tr><td>{{ __('provider-portal.reversals_and_adjustments') }}</td><td>&minus; {{ number_format((float)$summary['reversal_amount'],2) }}</td></tr>
  @endif
  <tr class="tot"><td>{{ __('provider-portal.net_payable_amount') }}</td><td>{{ number_format((float)$summary['net_payable_amount'],2) }} ETB</td></tr>
</table>

@if ($paymentClaim)
<div class="sigs">
  <div class="sig-row">
    <div class="sig-cell"><div class="sig-line">{{ $actor->name }}</div><div class="sig-lbl">{{ __('provider-portal.prepared_by') }}</div></div>
    <div class="sig-cell"><div class="sig-line">&nbsp;</div><div class="sig-lbl">{{ __('provider-portal.checked_by') }}</div></div>
    <div class="sig-cell"><div class="sig-line">&nbsp;</div><div class="sig-lbl">{{ __('provider-portal.approved_by') }}</div></div>
  </div>
</div>
@endif

<p class="sec">{{ __('provider-portal.transaction_details') }}</p>
<table class="txn">
  <colgroup><col class="no"><col class="num"><col class="dt"><col class="tm"><col class="en"><col class="ename"><col class="inst"><col class="mode"><col class="days"><col class="sub"><col class="epay"><col class="stat"></colgroup>
  <thead><tr>
    <th>{{ __('provider-portal.export_columns_no') }}</th>
    <th>{{ __('provider-portal.export_columns')['transaction_number'] }}</th>
    <th>{{ __('provider-portal.export_columns')['date_display'] }}</th>
    <th>{{ __('provider-portal.export_columns')['scan_time'] }}</th>
    <th>{{ __('provider-portal.export_columns')['employee_number'] }}</th>
    <th>{{ __('provider-portal.export_columns')['employee_name'] }}</th>
    <th>{{ __('provider-portal.export_columns')['employee_institution'] }}</th>
    <th>{{ __('provider-portal.export_columns')['usage_mode'] }}</th>
    <th>{{ __('provider-portal.export_columns')['consumed_days_count'] }}</th>
    <th>{{ __('provider-portal.export_columns')['subsidy_amount_applied'] }}</th>
    <th>{{ __('provider-portal.export_columns')['employee_payable_amount'] }}</th>
    <th>{{ __('provider-portal.export_columns')['transaction_status'] }}</th>
  </tr></thead>
  <tbody>
  @forelse ($pdfRows as $i => $row)
  <tr>
    <td class="c">{{ $i + 1 }}</td>
    <td class="mn">{{ $row[0] }}</td>
    <td>{{ $row[2] }}</td>
    <td>{{ $row[3] }}</td>
    <td>{{ $row[4] }}</td>
    <td>{{ $row[5] }}</td>
    <td>{{ $row[6] }}</td>
    <td>{{ $row[11] }}</td>
    <td class="c">{{ $row[12] }}</td>
    <td class="r">{{ $row[14] }}</td>
    <td class="r">{{ $row[15] }}</td>
    @php $st = (string)($row[16] ?? ''); @endphp
    <td class="s-{{ $st }}">{{ __('provider-portal.status_'.($st ?: 'rejected')) }}</td>
  </tr>
  @empty
  <tr><td colspan="12" style="text-align:center;padding:10px;color:#6b7280">{{ __('provider-portal.no_transactions_found_for_export') }}</td></tr>
  @endforelse
  </tbody>
</table>

<div class="foot">{{ __('provider-portal.provider_export_scope_notice') }}</div>
</body>
</html>