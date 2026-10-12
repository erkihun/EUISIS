@php
    // Rendered when `php artisan down` snapshots this view, so it must not
    // depend on the database. The brand comes from config (populated from
    // System Settings at boot when they were reachable).
    $brand = (string) config('app.name', config('branding.name', 'Addis One'));
    $short = (string) config('branding.short_name', 'AA-IEMSS');
    $officialEn = (string) config('branding.official_name_en');
    $officialAm = (string) config('branding.official_name_am');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="application-name" content="{{ $brand }}">
    <meta name="robots" content="noindex">
    <title>Maintenance | {{ $brand }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8fafc; color: #0f172a; font-family: system-ui, "Noto Sans Ethiopic", "Abyssinica SIL", sans-serif; }
        main { max-width: 36rem; padding: 2.5rem 1.5rem; text-align: center; }
        .brand { font-size: 1.5rem; font-weight: 800; letter-spacing: 0.01em; }
        .short { color: #475569; font-size: 0.85rem; margin-top: 0.15rem; }
        .official { color: #334155; font-size: 0.9rem; margin: 1.25rem 0 0; line-height: 1.5; }
        h1 { font-size: 1.15rem; margin: 2rem 0 0.5rem; }
        p { color: #475569; margin: 0.25rem 0; line-height: 1.5; }
    </style>
</head>
<body>
    <main>
        <div class="brand">{{ $brand }}</div>
        <div class="short">{{ $short }}</div>
        <p class="official">{{ $officialEn }}<br>{{ $officialAm }}</p>
        <h1>Scheduled maintenance · የተያዘ ጥገና</h1>
        <p>The service is temporarily unavailable while maintenance is carried out. Please try again shortly.</p>
        <p>ጥገና እየተካሄደ ስለሆነ አገልግሎቱ ለጊዜው አይገኝም። እባክዎ ከጥቂት ጊዜ በኋላ እንደገና ይሞክሩ።</p>
    </main>
</body>
</html>
