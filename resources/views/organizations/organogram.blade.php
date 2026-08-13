@php
    /**
     * Printable organogram. Rendered by dompdf, which supports only a limited
     * CSS subset — hence tables/inline styles rather than flex or grid.
     */
    $name = fn (?string $en, ?string $am) => $locale === 'am' ? ($am ?: $en) : ($en ?: $am);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('organizations.organizationOrganogram') }} — {{ $tree['organization']['code'] }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #0f172a; margin: 0; padding: 16px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #64748b; }
        .meta { font-size: 9px; margin-bottom: 12px; }
        .org { border: 1px solid #1d4ed8; background: #eff6ff; padding: 8px; margin-bottom: 10px; }
        .unit { border: 1px solid #cbd5e1; border-left: 3px solid #7c3aed; padding: 6px 8px; margin: 6px 0; }
        .unit-name { font-weight: bold; }
        .pos { border-bottom: 1px solid #f1f5f9; padding: 3px 0; }
        .badge { font-size: 8px; padding: 1px 4px; border: 1px solid #cbd5e1; }
        .occupied { background: #fef3c7; border-color: #f59e0b; }
        .vacant { background: #dcfce7; border-color: #16a34a; }
        .emp { color: #334155; font-size: 9px; }
        .code { font-family: DejaVu Sans Mono, monospace; font-size: 8px; color: #64748b; }
    </style>
</head>
<body>
@php $org = $tree['organization']; @endphp

<h1>{{ $name($org['name_en'], $org['name_am']) }}</h1>
<div class="meta muted">
    <span class="code">{{ $org['code'] }}</span>
    @if (! empty($org['type'])) · {{ $name($org['type']['name_en'] ?? null, $org['type']['name_am'] ?? null) }} @endif
    · {{ __('common.'.$org['status']) }}
    · {{ __('organizations.organizationOrganogram') }}
    · {{ $generatedAt->toDayDateTimeString() }}
</div>

<div class="org">
    <strong>{{ __('organizations.organizationUnits') }}:</strong> {{ $tree['counters']['units'] }} ·
    <strong>{{ __('organizations.positions') }}:</strong> {{ $tree['counters']['positions'] }} ·
    <strong>{{ __('common.occupied') }}:</strong> {{ $tree['counters']['occupied_positions'] }} ·
    <strong>{{ __('common.vacant') }}:</strong> {{ $tree['counters']['vacant_positions'] }}
</div>

{{-- Recursive unit rendering; indentation conveys depth for dompdf. --}}
@php
    $renderPositions = function (array $positions) use ($name) {
        foreach ($positions as $position) {
            echo '<div class="pos">';
            echo '<span class="code">'.e($position['code'] ?? '').'</span> ';
            echo e($name($position['name_en'], $position['name_am']));
            if (! empty($position['old_code'])) {
                echo ' <span class="code">('.e($position['old_code']).')</span>';
            }
            if (! empty($position['bpr_name'])) {
                echo ' <span class="muted">· '.e($position['bpr_name']).'</span>';
            }
            $isOccupied = $position['occupancy'] === 'occupied';
            echo ' <span class="badge '.($isOccupied ? 'occupied' : 'vacant').'">'
                .e(__('common.'.$position['occupancy'])).'</span>';
            if ($isOccupied && ! empty($position['assignment']['employee'])) {
                $employee = $position['assignment']['employee'];
                echo '<div class="emp">'.e($employee['employee_number'] ?? '').' — '
                    .e($employee['full_name'] ?? '').' ('.e(__('common.'.$employee['status'])).')</div>';
            }
            echo '</div>';
        }
    };

    $renderUnits = function (array $units, int $depth = 0) use (&$renderUnits, $renderPositions, $name) {
        foreach ($units as $unit) {
            echo '<div class="unit" style="margin-left:'.($depth * 14).'px">';
            echo '<div class="unit-name"><span class="code">'.e($unit['code'] ?? '').'</span> '
                .e($name($unit['name_en'], $unit['name_am']));
            if (! empty($unit['unit_type']['name_en'])) {
                echo ' <span class="muted">· '.e($name($unit['unit_type']['name_en'], $unit['unit_type']['name_am'] ?? null)).'</span>';
            }
            echo ' <span class="badge">'.e(__('common.'.$unit['status'])).'</span></div>';

            $renderPositions($unit['positions']);
            echo '</div>';

            $renderUnits($unit['children'], $depth + 1);
        }
    };
@endphp

@if (empty($tree['units']) && empty($tree['direct_positions']))
    <p class="muted">{{ __('organizations.noStructureFound') }}</p>
@else
    @if (! empty($tree['direct_positions']))
        <div class="unit">
            <div class="unit-name">{{ __('organizations.positions') }}</div>
            @php $renderPositions($tree['direct_positions']); @endphp
        </div>
    @endif

    @php $renderUnits($tree['units']); @endphp
@endif

</body>
</html>
