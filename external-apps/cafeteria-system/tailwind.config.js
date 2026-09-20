export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.tsx',
        '../../packages/euisis-ui/src/**/*.{ts,tsx}',
    ],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                primary: 'var(--color-primary)',
                accent: 'var(--color-accent)',
                surface: 'var(--app-surface)',
                'app-bg': 'var(--app-background)',
                'app-border': 'var(--app-border)',
                'muted-foreground': 'var(--app-muted-foreground)',
            },
            borderRadius: {
                control: 'var(--radius-control)',
                card: 'var(--radius-card)',
                panel: 'var(--radius-panel)',
            },
        },
    },
    plugins: [],
};
