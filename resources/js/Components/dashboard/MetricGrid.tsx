import { Children, type ReactNode } from 'react';

interface Props {
    children: ReactNode;
    count?: number;
    variant?: 'standard' | 'featured';
}

/**
 * Responsive KPI card layout.
 *
 * One track count for every row of tiles, so a group's own metrics line up
 * under the headline strip instead of each row picking its own width. Choosing
 * tracks per row — which this did briefly — meant a section with a single tile
 * rendered it at half the page width, floating out of alignment with the six
 * tiles directly above it.
 *
 * The featured overview leads with its first metric through the card's accent
 * border, not a wider track: with up to four tiles every tile gets an equal
 * share, because narrower tracks wrapped labels and misaligned the values.
 * Mixed widths remain only where a row would otherwise leave a gap.
 *
 * Track counts follow the width the grid actually gets (container queries,
 * `@container` + `@lg:`/`@4xl:`…), not the window: with the sidebar open or
 * collapsed, or inside a narrower column, tiles keep roughly the same minimum
 * width (~240px) instead of squeezing at a viewport breakpoint.
 */
function featuredSpan(count: number, index: number): string {
    const patterns: Record<number, string[]> = {
        1: ['@4xl:col-span-12'],
        2: ['@4xl:col-span-6', '@4xl:col-span-6'],
        3: ['@4xl:col-span-4', '@4xl:col-span-4', '@4xl:col-span-4'],
        4: ['@4xl:col-span-3', '@4xl:col-span-3', '@4xl:col-span-3', '@4xl:col-span-3'],
        5: [
            '@4xl:col-span-6',
            '@4xl:col-span-3',
            '@4xl:col-span-3',
            '@4xl:col-span-6',
            '@4xl:col-span-6',
        ],
        6: [
            '@4xl:col-span-6',
            '@4xl:col-span-3',
            '@4xl:col-span-3',
            '@4xl:col-span-4',
            '@4xl:col-span-4',
            '@4xl:col-span-4',
        ],
        7: [
            '@4xl:col-span-6',
            '@4xl:col-span-3',
            '@4xl:col-span-3',
            '@4xl:col-span-3',
            '@4xl:col-span-3',
            '@4xl:col-span-3',
            '@4xl:col-span-3',
        ],
    };

    return patterns[count]?.[index] ?? '@4xl:col-span-3';
}

export default function MetricGrid({
    children,
    count,
    variant = 'standard',
}: Props) {
    const itemCount = count ?? Children.count(children);

    if (variant === 'featured' && itemCount <= 7) {
        return (
            <div className="@container w-full">
                <div className="grid grid-cols-1 gap-3 @xs:grid-cols-2 @4xl:grid-cols-12">
                    {Children.map(children, (child, index) => (
                        <div className={`min-w-0 *:h-full ${featuredSpan(itemCount, index)}`}>
                            {child}
                        </div>
                    ))}
                </div>
            </div>
        );
    }

    const columns =
        itemCount === 1
            ? 'grid-cols-1 @lg:max-w-sm'
            : itemCount === 2
              ? 'grid-cols-1 @lg:grid-cols-2 @3xl:max-w-2xl'
              : itemCount === 3
                ? 'grid-cols-1 @lg:grid-cols-2 @3xl:grid-cols-3'
                : 'grid-cols-1 @lg:grid-cols-2 @3xl:grid-cols-3 @5xl:grid-cols-4';

    return (
        <div className="@container w-full">
            <div className={`grid gap-4 @3xl:gap-5 ${columns}`}>{children}</div>
        </div>
    );
}
