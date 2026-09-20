import { getStatusStyle } from '@/lib/statusStyles';
import { StatusBadge as UiStatusBadge } from '@euisis/ui';

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

    return <UiStatusBadge tone={style.tone === 'danger' ? 'danger' : style.tone} className={`badge ${className}`}>{label ?? style.label}</UiStatusBadge>;
}
