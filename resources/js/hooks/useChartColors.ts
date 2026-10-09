import { useEffect, useState } from 'react';

/**
 * Shared blue/orange chart palette with dark-mode-aware gridlines.
 * Re-reads when the `dark` class on <html> changes.
 */
export interface ChartColors {
    primary: string;
    accent: string;
    /** Grid / axis lines — muted and dark-mode aware. */
    grid: string;
    series: string[];
}

function readColors(): ChartColors {
    const isDark = typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
    const primary = isDark ? '#60a5fa' : '#2563eb';
    const accent = isDark ? '#fb923c' : '#ea580c';

    return {
        primary,
        accent,
        // Lighter slate in light mode, dim slate in dark mode for legible-but-subtle gridlines.
        grid: isDark ? '#334155' : '#e2e8f0',
        series: isDark
            ? [primary, accent, '#93c5fd', '#fdba74', '#3b82f6', '#f97316', '#bfdbfe', '#fed7aa']
            : [primary, accent, '#60a5fa', '#fb923c', '#1e40af', '#9a3412', '#93c5fd', '#fdba74'],
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
