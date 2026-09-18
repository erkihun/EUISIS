import { getStatusStyle } from '@/lib/statusStyles';

interface Props {
    status: string;
    /** Translated label. Falls back to the English label in the status map. */
    label?: string;
    className?: string;
}

/**
 * The one status badge in the system. Tone and label both come from
 * `lib/statusStyles` so the same status never renders two ways on two pages.
 *
 * Squared off to `rounded-control` rather than a pill: at table density a row
 * of pills reads as decoration, while a chip that shares the radius of the
 * inputs and buttons around it reads as part of the same interface.
 */
export default function StatusBadge({ status, label, className = '' }: Props) {
    const style = getStatusStyle(status);

    return (
        <span
            className={`badge inline-flex items-center rounded-control px-2 py-0.5 text-xs font-medium ${style.className} ${className}`}
        >
            {label ?? style.label}
        </span>
    );
}
