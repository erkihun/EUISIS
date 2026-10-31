import type { BenchNoteJudge } from '@/court/types/benchNotes';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import { useLocale } from '@/hooks/useLocale';

/** The three seats of the panel, by name, as the Blade pages listed them above a note. */
export function JudgePanel({ judges, className }: { judges: (BenchNoteJudge | null)[]; className?: string }) {
    const { t } = useLocale();

    return (
        <div className={className ?? 'space-y-1 text-center text-sm text-(--app-foreground)'}>
            {judges.map((judge, index) => <div key={index}>{judge?.name ?? t('cm.bench.meta.unknown')}</div>)}
        </div>
    );
}

/** The first judge's name and signature, as the Blade pages closed a note. */
export function JudgeSignature({ judge }: { judge: BenchNoteJudge | null }) {
    const { t, text } = useInspectionPagesText();

    return (
        <div className="text-center text-sm text-(--app-foreground)">
            <div className="font-medium">{judge?.name ?? t('cm.bench.meta.unknown')}</div>
            {judge?.signature_url
                ? <img src={judge.signature_url} alt={judge.name} className="mx-auto mt-2 max-h-16 w-auto" />
                : <div className="mt-2 text-(--app-muted-foreground)">{text('judge_signature', { n: 1 })}</div>}
        </div>
    );
}

/** The time of day of an ISO timestamp in the viewer's language (the Blade list's "Created Time" column). */
export function timeOfDay(iso: string | null, locale: string): string {
    if (!iso) return '-';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '-';
    return new Intl.DateTimeFormat(locale === 'am' ? 'am-ET' : 'en-US', { hour: 'numeric', minute: '2-digit' }).format(date);
}
