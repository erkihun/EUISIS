import { useLocale } from '@/hooks/useLocale';
import { StatusBadge, type Tone } from '@euisis/ui';

const TONES: Record<string, Tone> = { acknowledged: 'success', reviewed: 'success', submitted: 'info', assigned: 'neutral', unassessed: 'warning' };

/** Assessment record status on the shared status badge. */
export function RecordStatus({ value }: { value: string }) {
    const { t } = useLocale();
    return <StatusBadge tone={TONES[value] ?? 'neutral'}>{t(`assessmentOversight.recordStatuses.${value}`)}</StatusBadge>;
}
