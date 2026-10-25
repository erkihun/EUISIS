import { StatusBadge as UiStatusBadge, type Tone } from '../ui';
import { useLocale } from '../i18n';

/*
 * EUISIS lib/statusStyles: a status earns a colour only by what the operator
 * should do about it. Resting or historical states stay neutral.
 */
const TONES: Record<string, Tone> = {
    active: 'success',
    issued: 'info',
    printed: 'info',
    pending_print: 'info',
    pending_transfer: 'info',
    suspended: 'warning',
    expired: 'warning',
    revoked: 'danger',
};

export default function StatusBadge({ status, label, className = '' }: { status: string | null | undefined; label?: string; className?: string }) {
    const { t } = useLocale();
    const code = (status ?? '').toLowerCase();
    const key = `platform.status.${code}`;
    const translated = t(key);
    const text = label ?? (translated !== key ? translated : code.replace(/_/g, ' ') || '—');

    return <UiStatusBadge tone={TONES[code] ?? 'neutral'} className={`badge ${className}`}>{text}</UiStatusBadge>;
}
