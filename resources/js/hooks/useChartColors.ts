import { useEffect, useState } from 'react';

/**
 * Resolved colors for recharts elements, read from the live theme tokens so
 * charts follow the org's primary/accent palette and dark-mode toggles instead
 * of hard-coding hex values. Re-reads when the `dark` class on <html> changes.
 */
export interface ChartColors {
    primary: string;
    accent: string;
    /** Grid / axis lines — muted and dark-mode aware. */
    grid: string;
    series: string[];
}

function readColors(): ChartColors {
    if (typeof window === 'undefined') {
        // SSR-safe defaults mirroring the default theme tokens.
        return { primary: '#2563eb', accent: '#ea580c', grid: '#cbd5e1', series: [] };
    }

    const styles = getComputedStyle(document.documentElement);
    const read = (name: string, fallback: string) => {
        const value = styles.getPropertyValue(name).trim();
        return value || fallback;
    };

    const isDark = document.documentElement.classList.contains('dark');
    const primary = read('--color-primary', '#2563eb');
    const accent = read('--color-accent', '#ea580c');

    return {
        primary,
        accent,
        // Lighter slate in light mode, dim slate in dark mode for legible-but-subtle gridlines.
        grid: isDark ? '#334155' : '#e2e8f0',
        series: [primary, accent, '#16a34a', '#64748b', '#dc2626', '#7c3aed'],
    };
}

export function useChartColors(): ChartColors {
    const [colors, setColors] = useState<ChartColors>(readColors);

    useEffect(() => {
        const update = () => setColors(readColors());
        update();

        const observer = new MutationObserver(update);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'style'],
        });

        return () => observer.disconnect();
    }, []);

    return colors;
}
