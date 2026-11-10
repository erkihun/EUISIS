@php
    /** @var string $code */
    /** @var string $locale */
    /** @var \Modules\Letters\Domain\Models\Letter|null $letter */
    /** @var string|null $number */
    $t = fn (string $key, array $replace = []) => __('letters.verify.'.$key, $replace, $locale);
    $name = fn ($model) => $model ? (($locale === 'am' && $model->name_am) ? $model->name_am : $model->name_en) : null;
    $other = $locale === 'am' ? 'en' : 'am';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $t('title') }}</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: "Noto Sans Ethiopic", "Abyssinica SIL", system-ui, sans-serif; background: #f4f5fa; color: #111; }
        main { max-width: 560px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #c7cbe0; border-radius: 10px; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #122170; }
        .sub { color: #555; font-size: 14px; margin: 0 0 18px; }
        .ok { border-inline-start: 5px solid #15803d; padding-inline-start: 12px; }
        .bad { border-inline-start: 5px solid #b91c1c; padding-inline-start: 12px; }
        dl { display: grid; grid-template-columns: 1fr 2fr; gap: 6px 12px; font-size: 15px; margin: 16px 0 0; }
        dt { color: #555; }
        dd { margin: 0; font-weight: 600; }
        .code { font-family: ui-monospace, monospace; letter-spacing: 1px; }
        .note { font-size: 13px; color: #555; margin-top: 18px; }
        a { color: #122170; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>{{ $t('title') }}</h1>
        <p class="sub">{{ $t('intro') }} · <a href="{{ route('letters.verify', ['code' => $code, 'lang' => $other]) }}">{{ $other === 'am' ? 'አማርኛ' : 'English' }}</a></p>
        @if($letter !== null)
            <div class="ok">
                <strong>{{ $t('genuine') }}</strong>
                <dl>
                    <dt>{{ $t('institution') }}</dt><dd>{{ $name($letter->organization) }}</dd>
                    <dt>{{ $t('number') }}</dt><dd>{{ $number ?? '—' }}</dd>
                    <dt>{{ $t('date') }}</dt><dd>{{ \App\Support\EthiopianCalendar::format($letter->letter_date, 'dd/mm/yyyy') }} {{ $t('ec') }} · {{ $letter->letter_date->format('d/m/Y') }}</dd>
                    <dt>{{ $t('dispatched') }}</dt><dd>{{ $letter->dispatched_at?->timezone('Africa/Addis_Ababa')->format('d/m/Y H:i') }}</dd>
                    <dt>{{ $t('language') }}</dt><dd>{{ __('letters.enums.language.'.$letter->language, [], $locale) }}</dd>
                    <dt>{{ $t('code') }}</dt><dd class="code">{{ $code }}</dd>
                </dl>
            </div>
        @else
            <div class="bad">
                <strong>{{ $t('not_found') }}</strong>
                <p>{{ $t('not_found_help') }}</p>
                <p class="code">{{ $code }}</p>
            </div>
        @endif
        <p class="note">{{ $t('note') }}</p>
    </div>
</main>
</body>
</html>
