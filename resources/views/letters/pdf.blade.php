@php
    /** @var \Modules\Letters\Domain\Models\Letter $letter */
    /** @var string $locale */
    /** @var string $body */
    /** @var string $subject */
    /** @var array<string, string> $context */
    /** @var string|null $qr */
    /** @var string|null $verifyUrl */
    /** @var array<string, string|null>|null $letterhead */
    $t = fn (string $key, array $replace = []) => __('letters.pdf.'.$key, $replace, $locale);
    $name = fn ($model) => $model ? (($locale === 'am' && $model->name_am) ? $model->name_am : $model->name_en) : null;
    $organization = $letter->organization;
    $lh = $letterhead ?? [];
    $page = $lh['page'] ?? \Modules\Letters\Application\Services\LetterLayout::page([]);
    $headName = $name($organization);
    $vars = [
        'institution.name' => (string) $headName,
        'institution.name_am' => (string) ($organization->name_am ?? $organization->name_en ?? ''),
        'institution.name_en' => (string) ($organization->name_en ?? $organization->name_am ?? ''),
        'institution.code' => (string) ($organization->code ?? ''),
        'unit.name' => (string) ($letter->unit ? $name($letter->unit) : ''),
        'letter.number' => $context['letter.number'] !== '' ? $context['letter.number'] : $t('draft_short'),
        'letter.date_ec' => trim($context['letter.date_ec'].' '.$t('ec')),
        'letter.date_gc' => (string) $context['letter.date_gc'],
        'today.date_ec' => trim(($context['today.date_ec'] ?? '').' '.$t('ec')),
    ];
    $recipient = $letter->recipientOrganization;
    $isDraft = ! in_array($letter->status->value, ['dispatched'], true);
    $stamp = (! $isDraft && ! empty($lh['stamp'])) ? $lh['stamp'] : null;
    $footerOffset = max(0, (float) $page['margin_bottom'] - 6);
    $priority = $letter->priority->value;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $context['letter.number'] !== '' ? $context['letter.number'] : $subject }}</title>
    {{-- Abyssinica SIL / Noto Sans Ethiopic embedded server-side; the layout picks the family, size and colour. --}}
    <style>
        {!! $fontCss ?? '' !!}
        @page { margin: {{ $page['margin_top'] }}mm {{ $page['margin_right'] }}mm {{ $page['margin_bottom'] }}mm {{ $page['margin_left'] }}mm; }
        html, body { font-family: {!! \Modules\Letters\Application\Services\LetterLayout::family($page['font_family']) !!}; }
        body { font-size: {{ $page['font_size'] }}pt; color: {{ $page['text_color'] }}; line-height: 1.55; }
        table { border-collapse: collapse; width: 100%; }
        .meta { margin: 6px 0 14px; }
        .meta td { font-size: 10.5pt; padding: 1px 0; }
        .meta td.k { color: #555; width: 22%; }
        .meta td.v { font-weight: bold; }
        .to { margin: 12px 0 14px; }
        .to .label { color: #555; font-size: 10pt; }
        .to .name { font-weight: bold; }
        .subject { margin: 12px 0 14px; font-weight: bold; text-decoration: underline; }
        .body p { margin: 0 0 7px; }
        @include('pdf.partials.rich-text-styles')
        .sign { margin-top: 28px; }
        .sign .name { font-weight: bold; margin-top: 34px; }
        .sign .role { color: #444; font-size: 10pt; }
        .draft { border: 1px solid #b45309; color: #b45309; padding: 5px 8px; margin: 8px 0 12px; font-size: 9.5pt; }
        .priority { display: inline-block; border: 1px solid #991b1b; color: #991b1b; padding: 1px 6px; font-size: 9.5pt; font-weight: bold; }
        .annex { margin-top: 18px; font-size: 10pt; }
        .annex li { margin: 0 0 2px; }
        .classification { display: inline-block; border: 1.5px solid #7f1d1d; color: #7f1d1d; padding: 1px 8px; font-size: 9.5pt; font-weight: bold; letter-spacing: 0.5px; margin-top: 4px; }
        .copies { margin-top: 16px; font-size: 10.5pt; }
        .copies .label { font-weight: bold; }
        .copies li { margin: 0 0 2px; }
        .verify { margin-top: 14px; font-size: 8.5pt; color: #555; }
        .verify td { vertical-align: middle; padding: 0; }
        .verify img { width: 22mm; height: 22mm; }
        .lh-row { width: 100%; border-collapse: collapse; }
        .lh-row td { padding: 0 2px; }
        .lh-page:before { content: counter(page); }
        .foot { position: fixed; bottom: -{{ $footerOffset }}mm; left: 0; right: 0; }
        .sign table { width: 100%; }
        .sign td { vertical-align: bottom; }
        .sign td.stamp { width: 36mm; text-align: center; }
        .sign td.stamp img { width: 32mm; max-height: 32mm; opacity: 0.9; }
    </style>
</head>
<body>
    <div class="head">
        @include('letters.partials.layout-section', ['rows' => $lh['header']['rows'] ?? [], 'images' => $lh['images'] ?? [], 'vars' => $vars, 'language' => $locale])
    </div>
    @if($priority !== 'normal' || $letter->isRestricted())
        <p style="text-align: right; margin: 0 0 8px;">
            @if($priority !== 'normal')<span class="priority">{{ __('letters.enums.priority.'.$priority, [], $locale) }}</span>@endif
            @if($letter->isRestricted())<span class="classification">{{ __('letters.enums.classification.'.$letter->classification->value, [], $locale) }}</span>@endif
        </p>
    @endif

    @if($isDraft)
        <div class="draft">{{ $t('draft_notice', ['status' => __('letters.enums.letter_status.'.$letter->status->value, [], $locale)]) }}</div>
    @endif

    <table class="meta" @if($page['reference_align'] === 'right') style="width: 55%; margin-left: 45%;" @endif>
        <tr><td class="k">{{ $t('number') }}</td><td class="v">{{ $context['letter.number'] !== '' ? $context['letter.number'] : $t('number_pending') }}</td></tr>
        <tr><td class="k">{{ $t('date') }}</td><td class="v">{{ $context['letter.date_ec'] }} {{ $t('ec') }} &nbsp;·&nbsp; {{ $context['letter.date_gc'] }}</td></tr>
        @if($letter->file_code)
            <tr><td class="k">{{ $t('file') }}</td><td>{{ $letter->file_code }}</td></tr>
        @endif
        @if($letter->in_reply_to_letter_id && $letter->inReplyTo)
            <tr><td class="k">{{ $t('in_reply_to') }}</td><td>{{ $letter->inReplyTo->referenceNumber() ?? $letter->inReplyTo->external_reference ?? $letter->inReplyTo->subject }}</td></tr>
        @endif
    </table>

    @if($letter->direction->value !== 'internal')
        <div class="to">
            <div class="label">{{ $t('to') }}</div>
            <div class="name">{{ $recipient ? $name($recipient) : $letter->external_party_name }}</div>
            @if(! $recipient && $letter->external_party_address)
                <div>{{ $letter->external_party_address }}</div>
            @endif
            <div>{{ $t('city') }}</div>
        </div>
    @else
        <div class="to">
            <div class="label">{{ $t('to') }}</div>
            <div class="name">{{ $letter->unit ? $name($letter->unit) : $name($organization) }}</div>
        </div>
    @endif

    <div class="subject">{{ $t('subject') }}፦ {{ $subject }}</div>

    <div class="body rich-text">{!! $body !!}</div>

    <div class="sign">
        <table>
            <tr>
                <td>
                    <div>{{ $t('regards') }}</div>
                    <div class="name">{{ $context['signatory.name'] !== '' ? $context['signatory.name'] : '______________________' }}</div>
                    <div class="role">{{ $headName }}</div>
                </td>
                <td class="stamp">@if($stamp)<img src="{{ $stamp }}" alt="">@endif</td>
            </tr>
        </table>
    </div>

    @if($letter->copies->isNotEmpty())
        <div class="copies">
            <span class="label">{{ $t('copies') }}፦</span>
            <ul>
                @foreach($letter->copies as $copy)
                    <li>{{ $copy->organization ? $name($copy->organization) : $copy->external_name }}{{ ! $copy->organization && $copy->external_address ? ' · '.$copy->external_address : '' }}</li>
                @endforeach
                @if($letter->direction->value === 'outgoing' && $letter->recipientOrganization)
                    <li>{{ $t('copy_file') }}</li>
                @endif
            </ul>
        </div>
    @endif

    @if($qr && $verifyUrl)
        <table class="verify">
            <tr>
                <td style="width: 24mm;"><img src="{{ $qr }}" alt=""></td>
                <td>{{ $t('verify_help') }}<br>{{ $verifyUrl }}<br>{{ $t('verify_code') }}: <strong>{{ $letter->verification_code }}</strong></td>
            </tr>
        </table>
    @endif

    @php $annexes = $letter->attachments->filter(fn ($a) => $a->kind->value !== 'rendered'); @endphp
    @if($annexes->isNotEmpty())
        <div class="annex">
            <strong>{{ $t('annexes') }}</strong>
            <ul>
                @foreach($annexes as $annex)
                    <li>{{ $annex->original_name }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="foot">
        @include('letters.partials.layout-section', ['rows' => $lh['footer']['rows'] ?? [], 'images' => $lh['images'] ?? [], 'vars' => $vars, 'language' => $locale])
    </div>
</body>
</html>
