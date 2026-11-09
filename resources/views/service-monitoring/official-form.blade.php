<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="ltr"><head><meta charset="utf-8"><title>{{ $title }}</title>
@include('pdf.partials.typography', ['variant' => 'formal'])
<style>
@page { margin: 12mm 9mm; }
body { font-size: 8px; color: #172033; }
.preamble { border-bottom: 3px solid #173b63; padding-bottom: 6px; margin-bottom: 8px; line-height: 1.6; }
.preamble .lead { font-size: 13px; color: #173b63; }
.seal { float: right; width: 70px; height: 70px; }
h2 { font-size: 11px; font-weight: normal; color: #173b63; margin: 12px 0 2px; }
h3 { font-size: 9.5px; font-weight: normal; margin: 0 0 4px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
thead { display: table-header-group; } tr { page-break-inside: avoid; }
th { background: #e8eef5; font-weight: normal; text-align: center; }
th, td { padding: 3px; border: 1px solid #9aa9bb; vertical-align: top; word-wrap: break-word; }
td.total { font-weight: bold; background: #f4f6f9; }
.notes { font-size: 7.5px; color: #3b4a5e; margin: 0 0 8px; }
.section { page-break-before: always; }
.section.first { page-break-before: auto; }
.footer { margin-top: 10px; font-size: 7.5px; color: #526176; }
</style></head><body>
@foreach ($sheets as $s => $sheet)
<div class="section {{ $s === 0 ? 'first' : '' }}">
    <div class="preamble">
        @if ($seal)<img class="seal" src="{{ $seal }}" alt="">@endif
        @foreach ($preamble as $i => $line)<div class="{{ $i === 0 ? 'lead' : '' }}">{{ $line }}</div>@endforeach
    </div>
    @foreach ($sheet['tables'] as $table)
        <h2>{{ $table['title'] }}</h2>
        @if (! empty($table['subtitle']))<h3>{{ $table['subtitle'] }}</h3>@endif
        <table>
            @if ($table['header'] !== [])
            <thead>
                @foreach ($table['header'] as $row)
                <tr>@foreach ($row as $cell)<th colspan="{{ $cell['colspan'] }}" rowspan="{{ $cell['rowspan'] }}">{{ $cell['text'] }}</th>@endforeach</tr>
                @endforeach
            </thead>
            @endif
            <tbody>
                @foreach ($table['body'] as $index => $row)
                <tr>@foreach ($row as $value)<td class="{{ in_array($index, $table['total_rows'], true) ? 'total' : '' }}">{{ $value }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
        @foreach ($table['notes'] as $note)<p class="notes">{{ $note }}</p>@endforeach
    @endforeach
</div>
@endforeach
<div class="section">
    <h2>{{ $notes_title }}</h2>
    <ol class="notes">@foreach ($general_notes as $note)<li>{{ $note }}</li>@endforeach</ol>
    @if ($footer)<div class="footer">{{ $footer }}</div>@endif
</div>
<script type="text/php">if (isset($pdf)) { $pdf->page_text(770, 570, "{PAGE_NUM} / {PAGE_COUNT}", null, 7); }</script>
</body></html>
