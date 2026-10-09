import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{ts,tsx}',
        './packages/euisis-ui/src/**/*.{ts,tsx}',
    ],

    theme: {
        extend: {
            /*
             * Typography — see docs/ui-typography.md. Families and line
             * heights are CSS variables defined in resources/css/app.css and
             * switched by the `lang` attribute, so `font-sans` is Inter in
             * English and Noto Sans Ethiopic in Amharic without any
             * component knowing which language is active.
             */
            fontFamily: {
                sans: ['var(--font-ui)'],
                ui: ['var(--font-ui)'],
                document: ['var(--font-document)'],
                'id-card': ['var(--font-id-card)'],
            },
            fontSize: {
                xs: ['var(--fs-xs)', { lineHeight: 'var(--lh-xs)' }],
                sm: ['0.875rem', { lineHeight: 'var(--lh-sm)' }],
                base: ['1rem', { lineHeight: 'var(--lh-base)' }],
                // Semantic steps. Prefer these in shared components.
                'page-title': ['1.375rem', { lineHeight: 'var(--lh-title)', fontWeight: '700' }],
                'section-title': ['1.125rem', { lineHeight: 'var(--lh-section)', fontWeight: '600' }],
                body: ['0.875rem', { lineHeight: 'var(--lh-sm)' }],
                label: ['0.875rem', { lineHeight: 'var(--lh-sm)', fontWeight: '500' }],
                table: ['0.8125rem', { lineHeight: 'var(--lh-table)' }],
                helper: ['var(--fs-helper)', { lineHeight: 'var(--lh-helper)' }],
            },
            /*
             * Only 400–700 are shipped. 800/900 would be synthesised (or
             * snapped to 700 anyway) and heavy Ethiopic strokes fill in, so
             * the two heaviest utilities are pinned to the heaviest real face.
             */
            fontWeight: {
                extrabold: '700',
                black: '700',
            },
            colors: {
                /*
                 * `primary` is callable both as a flat colour (`bg-primary`)
                 * and as a scale (`bg-primary-100`), so a page never needs to
                 * reach for a stock Tailwind blue to get a lighter navy.
                 */
                primary: {
                    DEFAULT: 'var(--color-primary)',
                    50: 'var(--color-primary-50)',
                    100: 'var(--color-primary-100)',
                    200: 'var(--color-primary-200)',
                    300: 'var(--color-primary-300)',
                    400: 'var(--color-primary-400)',
                    500: 'var(--color-primary-500)',
                    600: 'var(--color-primary-600)',
                    700: 'var(--color-primary-700)',
                    800: 'var(--color-primary-800)',
                    900: 'var(--color-primary-900)',
                    950: 'var(--color-primary-950)',
                    hover: 'var(--color-primary-hover)',
                    light: 'var(--color-primary-light)',
                },
                'primary-hover': 'var(--color-primary-hover)',
                'primary-light': 'var(--color-primary-light)',
                accent: {
                    DEFAULT: 'var(--color-accent)',
                    hover: 'var(--color-accent-hover)',
                    light: 'var(--color-accent-light)',
                },
                'accent-hover': 'var(--color-accent-hover)',
                'accent-light': 'var(--color-accent-light)',

                /* Theme-aware surfaces — these remove the need for the
                   `bg-white dark:bg-slate-900` pair repeated on every card. */
                surface: {
                    DEFAULT: 'var(--app-surface)',
                    muted: 'var(--app-surface-muted)',
                },
                'app-bg': 'var(--app-background)',
                'app-border': 'var(--app-border)',
                'app-border-strong': 'var(--app-border-strong)',
                'muted-foreground': 'var(--app-muted-foreground)',

                /*
                 * Tailwind's stock blue is repointed at the institutional navy.
                 *
                 * Roughly 140 tint surfaces (`bg-blue-50` chips, `bg-blue-100`
                 * avatars, `dark:bg-blue-900/30` panels) had accumulated across
                 * the app as an informal "brand tint". Rewriting each call site
                 * would be a large diff for no behavioural gain, and would keep
                 * happening as new pages are written. Redefining the palette
                 * fixes every one of them at once and makes the stock blue
                 * unreachable by accident.
                 *
                 * Literal hex, not the CSS variables: Tailwind can only apply
                 * an opacity modifier (`/30`) to a colour it can parse, and the
                 * tints are all static anyway — only `--color-primary` itself
                 * follows the admin's `appearance.primary_color` setting.
                 *
                 * A genuinely different blue is still available as `sky`.
                 */
                blue: {
                    50: '#eef1fa',
                    100: '#dde3f5',
                    200: '#bcc7eb',
                    300: '#8f9fd8',
                    400: '#5d72bf',
                    500: '#33499f',
                    600: '#1d3084',
                    700: '#122170',
                    800: '#0f1c5c',
                    900: '#0d1749',
                    950: '#070d2c',
                },
            },
            borderRadius: {
                control: 'var(--radius-control)',
                card: 'var(--radius-card)',
                panel: 'var(--radius-panel)',
            },
            height: {
                'control-sm': 'var(--control-h-sm)',
                'control-md': 'var(--control-h-md)',
                'control-lg': 'var(--control-h-lg)',
            },
        },
    },

    plugins: [forms],
};
