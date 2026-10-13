import { getStatusStyle } from '@/lib/statusStyles';
import { useTranslator } from '@/hooks/useLocale';
import { StatusBadge as UiStatusBadge } from '@euisis/ui';

interface Props {
    status: string;
    /** Translated label. Without one, the badge shows `status.<code>` from the translations. */
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
    const { t } = useTranslator();
    const style = getStatusStyle(status);
    // The status map's English label is the last resort, never the Amharic page's label.
    const key = `status.${(status ?? '').toLowerCase().replace(/[\s-]+/g, '_')}`;
    const translated = t(key);
    const text = label ?? (translated && translated !== key ? translated : style.label);

    return <UiStatusBadge tone={style.tone === 'danger' ? 'danger' : style.tone} className={`badge ${className}`}>{text}</UiStatusBadge>;
}
