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
    /**
     * Axis tick labels. Recharts defaults these to a hardcoded `#666`, which
     * measures 3.09:1 against the dark card surface (#111827) — below the 4.5:1
     * WCAG AA needs for small text, so axis labels were genuinely hard to read
     * in dark mode. These values clear AA on both surfaces.
     */
    axisText: string;
    series: string[];
    /**
     * Five ordered points of a scale, low → high: orange through a neutral
     * slate to blue. Orange/blue rather than red/green so the two ends stay
     * apart for red–green colour blindness; the legend always names each point.
     */
    diverging: string[];
}

function readColors(): ChartColors {
    const isDark = typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
    const primary = isDark ? '#60a5fa' : '#2563eb';
    const accent = isDark ? '#fb923c' : '#ea580c';

    return {
        primary,
        accent,
        axisText: isDark ? '#cbd5e1' : '#475569',
        // Lighter slate in light mode, dim slate in dark mode for legible-but-subtle gridlines.
        grid: isDark ? '#334155' : '#e2e8f0',
        series: isDark
            ? [primary, accent, '#93c5fd', '#fdba74', '#3b82f6', '#f97316', '#bfdbfe', '#fed7aa']
            : [primary, accent, '#60a5fa', '#fb923c', '#1e40af', '#9a3412', '#93c5fd', '#fdba74'],
        diverging: isDark
            ? ['#fb923c', '#fdba74', '#94a3b8', '#93c5fd', '#3b82f6']
            : ['#c2410c', '#fb923c', '#94a3b8', '#60a5fa', '#1d4ed8'],
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
