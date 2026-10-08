import { useLocale } from '@/hooks/useLocale';
import { StatusBadge, type Tone } from '@euisis/ui';

/** Stored bilingual content: the configured language first, the other as fallback. Never machine-translated. */
export function usePick() {
    const { locale } = useLocale();
    return (en?: string | null, am?: string | null): string => ((locale === 'am' ? am || en : en || am) ?? '').trim();
}

const RESPONSE_TONES: Record<string, Tone> = { not_started: 'neutral', in_progress: 'info', submitted: 'success', returned: 'warning', conflict_declared: 'warning', cancelled: 'neutral' };
const RECORD_TONES: Record<string, Tone> = { pending_assignment: 'warning', assigned: 'info', submitted: 'info', reviewed: 'success', acknowledged: 'success', unassessed: 'neutral', cancelled: 'neutral' };
const DEADLINE_TONES: Record<string, Tone> = { on_track: 'success', due_soon: 'warning', overdue: 'danger' };

export function ResponseStatus({ value }: { value: string }) {
    const { t } = useLocale();
    return <StatusBadge tone={RESPONSE_TONES[value] ?? 'neutral'}>{t(`assessmentWorkspace.statuses.${value}`)}</StatusBadge>;
}

export function RecordStatusBadge({ value }: { value: string }) {
    const { t } = useLocale();
    return <StatusBadge tone={RECORD_TONES[value] ?? 'neutral'}>{t(`assessmentWorkspace.recordStatuses.${value}`)}</StatusBadge>;
}

export function DeadlineBadge({ value }: { value: string | null | undefined }) {
    const { t } = useLocale();
    if (!value) return null;
    return <StatusBadge tone={DEADLINE_TONES[value] ?? 'neutral'}>{t(`assessmentWorkspace.deadline.${value}`)}</StatusBadge>;
}

export function ProgressMeter({ answered, required }: { answered: number; required: number }) {
    const { t } = useLocale();
    const width = required === 0 ? 0 : Math.round((answered / required) * 100);
    return (
        <div className="min-w-24">
            <div className="h-1.5 rounded-full bg-gray-100 dark:bg-slate-800" role="presentation"><div className="h-1.5 rounded-full bg-[color:var(--color-primary)]" style={{ width: `${width}%` }} /></div>
            <span className="mt-1 block text-xs tabular-nums text-gray-500 dark:text-slate-400">{t('assessmentWorkspace.progressOf').replace(':answered', String(answered)).replace(':required', String(required))}</span>
        </div>
    );
}

export const fill = (text: string, values: Record<string, string | number>) => Object.entries(values).reduce((out, [k, v]) => out.split(`:${k}`).join(String(v)), text);
