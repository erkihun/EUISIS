@php
    /** @var list<array<string, mixed>> $rows */
    /** @var array<string, string> $images */
    /** @var array<string, string> $vars */
    /** @var string $language */
    $borderCss = function (array $row): string {
        $width = ['thin' => '1px solid', 'thick' => '2.5px solid', 'double' => '3px double'][$row['border_style']] ?? '1px solid';
        $line = $width.' '.$row['border_color'];
        return match ($row['border_position']) {
            'top' => "border-top: {$line}; padding-top: 3px;",
            'bottom' => "border-bottom: {$line}; padding-bottom: 3px;",
            'both' => "border-top: {$line}; border-bottom: {$line}; padding: 3px 0;",
            default => '',
        };
    };
@endphp
@foreach($rows as $row)
    @php
        $cells = $row['cells'];
        $fixed = array_sum(array_map(fn ($c) => (float) $c['width'], $cells));
        $auto = count(array_filter($cells, fn ($c) => (float) $c['width'] <= 0));
        $share = $auto > 0 ? max(0, 100 - $fixed) / $auto : 0;
    @endphp
    <table class="lh-row" style="{{ $borderCss($row) }} margin-bottom: {{ $row['space_after'] }}mm;">
        <tr>
            @foreach($cells as $cell)
                <td style="width: {{ round((float) $cell['width'] > 0 ? (float) $cell['width'] : $share, 2) }}%; text-align: {{ $cell['align'] }}; vertical-align: {{ $cell['valign'] }};">
                    @foreach($cell['items'] as $item)
                        @continue(! \Modules\Letters\Application\Services\LetterLayout::prints($item, $language, $vars))
                        @if($item['type'] === 'image')
                            @if(! empty($images[$item['image']]))
                                @if(! empty($item['full']))
                                    <div><img src="{{ $images[$item['image']] }}" alt="" style="width: 100%;"></div>
                                @else
                                    <div><img src="{{ $images[$item['image']] }}" alt="" style="width: {{ $item['width_mm'] }}mm; max-height: {{ $item['width_mm'] * 1.4 }}mm;"></div>
                                @endif
                            @endif
                        @else
                            <div style="{{ $item['font_family'] !== '' ? 'font-family: '.\Modules\Letters\Application\Services\LetterLayout::family($item['font_family']).';' : '' }} font-size: {{ $item['font_size'] }}pt; line-height: 1.3; color: {{ $item['color'] }};{{ $item['bold'] ? ' font-weight: bold;' : '' }}{{ $item['underline'] ? ' text-decoration: underline;' : '' }}">{!! \Modules\Letters\Application\Services\LetterLayout::fill($item['text'], $vars) !!}</div>
                        @endif
                    @endforeach
                </td>
            @endforeach
        </tr>
    </table>
@endforeach
