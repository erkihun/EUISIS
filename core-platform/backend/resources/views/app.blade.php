<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

@php
    // A copy of the EUISIS root view (resources/views/app.blade.php); only the branding and cookie sources differ.
    $brandingValues = app(App\Services\SharedSettings::class)->branding();
    $branding = new class($brandingValues) {
        public function __construct(private array $values) {}
        public function brandName(): string { return $this->values['brandName'] ?: 'EUISIS Core'; }
        public function faviconUrl(): ?string { return $this->values['faviconUrl']; }
    };
    // The language the client will render first (its cookie mirrors localStorage; 'am' is the client default).
    $initialLocale = in_array(request()->cookie('euisis_locale'), ['en', 'am'], true)
        ? request()->cookie('euisis_locale')
        : 'am';
    // First-paint fonts, fetched in parallel with the CSS instead of after it. Ethiopic only when it leads.
    $preloadFonts = [
        'node_modules/@fontsource/inter/files/inter-latin-400-normal.woff2',
        'node_modules/@fontsource/inter/files/inter-latin-600-normal.woff2',
    ];
    if ($initialLocale === 'am') {
        $preloadFonts[] = 'node_modules/@fontsource/abyssinica-sil/files/abyssinica-sil-ethiopic-400-normal.woff2';
    }
    $preloadFonts = array_filter(array_map(fn (string $font) => rescue(fn () => Illuminate\Support\Facades\Vite::asset($font), null, false), $preloadFonts));
    // The active language's dictionary is a separate chunk; preloading it removes a request round trip before first render.
    $localeChunk = rescue(fn () => Illuminate\Support\Facades\Vite::asset('resources/js/i18n/bundles/'.$initialLocale.'.ts'), null, false);
@endphp
        <title data-inertia>{{ $branding->brandName() }}</title>
        <meta name="application-name" content="{{ $branding->brandName() }}">
        <link rel="icon" href="{{ $branding->faviconUrl() ?? asset('favicon.ico') }}">

        <!-- Inline theme + initial loader — runs before any JS, prevents flash -->
        <style>
            #app-initial-loader {
                position: fixed;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-direction: column;
                gap: 14px;
                background: #f9fafb;
                z-index: 9999;
                transition: opacity 0.2s ease;
            }
            html.dark #app-initial-loader { background: #020617; }

            .app-loader-ring {
                position: relative;
                width: 44px;
                height: 44px;
            }
            .app-loader-ring::before,
            .app-loader-ring::after {
                content: '';
                position: absolute;
                inset: 0;
                border-radius: 50%;
                border: 3px solid transparent;
            }
            .app-loader-ring::before {
                border-color: #dde3f5;
            }
            html.dark .app-loader-ring::before {
                border-color: #1e293b;
            }
            .app-loader-ring::after {
                border-top-color: #122170;
                animation: app-spin 0.75s linear infinite;
            }
            html.dark .app-loader-ring::after {
                border-top-color: #8f9fd8;
            }
            .app-loader-name {
                /* Painted before app.css arrives; the token takes over once it does. */
                font-family: var(--font-ui, Inter, 'Abyssinica SIL', system-ui, sans-serif);
                font-size: 13px;
                font-weight: 600;
                letter-spacing: 0.08em;
                color: #94a3b8;
            }
            html.dark .app-loader-name { color: #64748b; }

            @keyframes app-spin {
                to { transform: rotate(360deg); }
            }
        </style>

        <script>
            (function () {
                try {
                    var storedTheme = localStorage.getItem('theme');
                    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    var theme = storedTheme === 'light' || storedTheme === 'dark'
                        ? storedTheme
                        : (prefersDark ? 'dark' : 'light');
                    document.documentElement.classList.toggle('dark', theme === 'dark');
                } catch (e) {
                    document.documentElement.classList.remove('dark');
                }
            })();
        </script>

        @foreach ($preloadFonts as $fontUrl)
        <link rel="preload" as="font" type="font/woff2" crossorigin href="{{ $fontUrl }}">
        @endforeach
        @if ($localeChunk)
        <link rel="modulepreload" href="{{ $localeChunk }}">
        @endif

        <!-- Scripts. Routes are a cached asset (resources/js/ziggy.js), not inlined here. -->
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        @inertiaHead
    </head>
    <body class="bg-gray-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
        <!-- Native loader: visible immediately, before any JS executes -->
        <div id="app-initial-loader" aria-label="Loading" role="status">
            <div class="app-loader-ring"></div>
            <span class="app-loader-name">{{ $branding->brandName() }}</span>
        </div>

        @inertia
    </body>
</html>
