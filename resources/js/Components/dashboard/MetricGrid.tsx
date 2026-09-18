import type { ReactNode } from 'react';

interface Props {
    children: ReactNode;
    /** Accepted for call-site compatibility; the track count is now fixed. */
    count?: number;
}

/**
 * The dashboard's tile grid.
 *
 * One track count for every row of tiles, so a group's own metrics line up
 * under the headline strip instead of each row picking its own width. Choosing
 * tracks per row — which this did briefly — meant a section with a single tile
 * rendered it at half the page width, floating out of alignment with the six
 * tiles directly above it.
 *
 * Six across on a wide screen keeps a full row readable at 1280px+ without the
 * tiles turning into billboards, which is what an operations console needs.
 */
export default function MetricGrid({ children }: Props) {
    return <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">{children}</div>;
}
