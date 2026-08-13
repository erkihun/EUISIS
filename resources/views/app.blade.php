<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

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
                border-color: #dbeafe;
            }
            html.dark .app-loader-ring::before {
                border-color: #1e293b;
            }
            .app-loader-ring::after {
                border-top-color: #2563eb;
                animation: app-spin 0.75s linear infinite;
            }
            html.dark .app-loader-ring::after {
                border-top-color: #60a5fa;
            }
            .app-loader-name {
                font-family: Figtree, ui-sans-serif, system-ui, sans-serif;
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

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        @inertiaHead
    </head>
    <body class="bg-gray-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
        <!-- Native loader: visible immediately, before any JS executes -->
        <div id="app-initial-loader" aria-label="Loading" role="status">
            <div class="app-loader-ring"></div>
            <span class="app-loader-name">{{ config('app.name', 'EUISIS') }}</span>
        </div>

        @inertia
    </body>
</html>
