@php
    /** @var \App\Models\JobDescription $jd */
    $t = fn (string $key, array $replace = []) => __('organization-design.jd_pdf.'.$key, $replace, $locale);
    $name = fn ($model, string $en = 'name_en', string $am = 'name_am') => $model ? (($am && $locale === 'am' && $model->{$am}) ? $model->{$am} : $model->{$en}) : null;
    $position = $jd->position;
    $isPublished = $jd->status->value === 'published' || $jd->status->value === 'superseded';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $jd->reference_number }}</title>
    {{-- Abyssinica SIL / Noto Sans Ethiopic embedded server-side (formal variant); see config/typography.php. --}}
    @include('pdf.partials.typography', ['variant' => 'formal'])
    <style>
        @page { margin: 22mm 18mm 20mm 18mm; }
        body { font-size: 11pt; color: #111; line-height: 1.5; }
        table { border-collapse: collapse; width: 100%; }
        .head { border-bottom: 2px solid #122170; padding-bottom: 6px; margin-bottom: 12px; }
        .head .org { font-size: 10pt; color: #333; }
        .head .title { font-size: 16pt; font-weight: bold; color: #122170; margin: 2px 0; }
        .head .ref { font-size: 9.5pt; color: #444; }
        .meta td { font-size: 9.5pt; padding: 2px 6px 2px 0; vertical-align: top; }
        .meta td.k { color: #555; width: 32%; }
        .draft { border: 1px solid #b45309; color: #b45309; padding: 6px 8px; margin: 10px 0; font-size: 9.5pt; }
        h2 { font-size: 11.5pt; color: #122170; margin: 14px 0 4px; border-bottom: 1px solid #c7cbe0; padding-bottom: 2px; }
        .body p { margin: 0 0 5px; }
        @include('pdf.partials.rich-text-styles')
        .rows th, .rows td { border: 1px solid #c7cbe0; padding: 3px 5px; font-size: 9.5pt; text-align: left; vertical-align: top; }
        .rows th { background: #eef0f8; }
        .muted { color: #666; }
        .foot { margin-top: 18px; font-size: 8.5pt; color: #555; border-top: 1px solid #c7cbe0; padding-top: 4px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="org">{{ $name($position->organization) }}{{ $position->organizationUnit ? ' · '.$position->organizationUnit->code.' · '.$name($position->organizationUnit) : '' }}</div>
        <div class="title">{{ $t('title') }} — {{ $locale === 'am' && $position->title_am ? $position->title_am : $position->title_en }}</div>
        <div class="ref">{{ $t('reference') }}: {{ $jd->reference_number }} · {{ $t('version') }} {{ $jd->version_no }} · {{ $t('status') }}: {{ __('organization-design.status.'.$jd->status->value, [], $locale) }}</div>
    </div>

    @unless ($isPublished)
        <div class="draft">{{ $t('draft_notice') }}</div>
    @endunless

    <table class="meta">
        <tr><td class="k">{{ $t('position') }}</td><td>{{ $position->job_position_code }} · {{ $locale === 'am' && $position->title_am ? $position->title_am : $position->title_en }}</td></tr>
        <tr><td class="k">{{ $t('organization') }}</td><td>{{ $name($position->organization) }}</td></tr>
        <tr><td class="k">{{ $t('unit') }}</td><td>{{ $position->organizationUnit ? $position->organizationUnit->code.' · '.$name($position->organizationUnit) : $t('empty') }}</td></tr>
        <tr><td class="k">{{ $t('grade') }}</td><td>{{ $position->grade_level ?? $t('empty') }}</td></tr>
        <tr><td class="k">{{ $t('occupation') }}</td><td>{{ $position->occupation ? ($position->occupation->isco_code ?? $position->occupation->code).' · '.$name($position->occupation) : $t('empty') }}</td></tr>
        <tr><td class="k">{{ $t('effective') }}</td><td>{{ $t('from') }} {{ $effectiveFrom }}{{ $effectiveTo ? ' '.$t('to').' '.$effectiveTo : '' }}</td></tr>
        <tr><td class="k">{{ $t('prepared_by') }}</td><td>{{ $jd->preparer?->name ?? $t('empty') }}</td></tr>
        <tr><td class="k">{{ $t('approved_by') }}</td><td>{{ $jd->approver?->name ?? $t('empty') }}</td></tr>
        <tr><td class="k">{{ $t('published_by') }}</td><td>{{ $jd->publisher?->name ?? $t('empty') }}{{ $publishedAt ? ' · '.$t('published_at').' '.$publishedAt : '' }}</td></tr>
    </table>

    @foreach ($sections as $section)
        @php
            $title = $locale === 'am' && $section->title_am ? $section->title_am : $section->title_en;
            $content = $locale === 'am' && $section->content_am ? $section->content_am : $section->content_en;
            $payload = $section->payload ?? [];
        @endphp
        <h2>{{ $title }}</h2>
        @if ($section->section_key === 'competencies' && is_array($payload) && $payload !== [])
            <table class="rows">
                <tr><th>{{ __('organization-design.reports.columns.competencies', [], $locale) }}</th><th>{{ __('organization-design.fields.minimum_level', [], $locale) }}</th></tr>
                @foreach ($payload as $row)
                    <tr><td>{{ ($row['competency']['code'] ?? '').' · '.(($locale === 'am' ? ($row['competency']['name_am'] ?? null) : null) ?? ($row['competency']['name_en'] ?? '')) }}</td><td>{{ ($locale === 'am' ? ($row['level']['name_am'] ?? null) : null) ?? ($row['level']['name_en'] ?? '') }}</td></tr>
                @endforeach
            </table>
        @elseif ($section->section_key === 'qualifications' && is_array($payload) && $payload !== [])
            <table class="rows">
                <tr><th>{{ __('organization-design.reports.columns.qualifications', [], $locale) }}</th><th>{{ __('organization-design.fields.field_of_study', [], $locale) }}</th><th>{{ __('organization-design.fields.experience_years', [], $locale) }}</th></tr>
                @foreach ($payload as $row)
                    <tr><td>{{ ($locale === 'am' ? ($row['education_level']['name_am'] ?? null) : null) ?? ($row['education_level']['name_en'] ?? '') }}</td><td>{{ $row['field_of_study'] ?? $t('empty') }}</td><td>{{ $row['experience_years'] ?? 0 }}</td></tr>
                @endforeach
            </table>
        @elseif ($content !== null && $content !== '')
            {{-- Custom sections are formatted text; derived ones are plain text, shown as paragraphs. --}}
            <div class="body rich-text">@richtext($content)</div>
        @else
            <div class="muted">{{ $t('empty') }}</div>
        @endif
    @endforeach

    <div class="foot">{{ $t('generated', ['at' => $generatedAt, 'user' => $generatedBy]) }}</div>
</body>
</html>
