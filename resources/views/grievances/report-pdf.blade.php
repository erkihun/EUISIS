<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    @include('pdf.partials.typography', ['variant' => 'report'])
    <style>
        @page { margin: 18mm 14mm; }
        body { font-size: 9.5pt; color: #111; }
        h1 { font-size: 14pt; color: #122170; margin: 0 0 4px; }
        .meta { font-size: 8.5pt; color: #555; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #eef1f8; text-align: left; font-weight: bold; }
        th, td { border: 1px solid #d6dbe6; padding: 4px 6px; vertical-align: top; }
        .note { margin-top: 10px; font-size: 8pt; color: #666; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">{{ $period }} · {{ $generated }}</div>
    <table>
        <thead><tr>@foreach ($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>@foreach ($row as $cell)<td>{{ is_bool($cell) ? ($cell ? '✓' : '—') : $cell }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($headings) }}">—</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($suppressed > 0)
        <div class="note">{{ $suppressedNote }}</div>
    @endif
    <div class="note">{{ $note }}</div>
</body>
</html>
