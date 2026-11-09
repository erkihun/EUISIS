import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import type { CaseAudit, CaseTimelineEntry } from '@/court/types/caseDetail';
import SectionCard, { EmptyBox } from './SectionCard';
import { useCaseText } from './useCaseText';
import { AuditIcon, ClockIcon } from './icons';

const humanize = (action: string): string => {
    const text = action.replace(/_/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
};

/** The case audit trail: when, what happened, who did it and the recorded details as labelled lines. */
export function AuditSection({ audits }: { audits: CaseAudit[] }) {
    const { t, tx } = useCaseText();
    const actor = (audit: CaseAudit): string => {
        const role = tx(`cm.case_audit.actors.${audit.actor_type ?? 'system'}`, audit.actor_type ?? 'system');
        return audit.actor_name ? `${audit.actor_name} · ${role}` : role;
    };

    return (
        <SectionCard id="audits" title={t('cm.cases.case_audit_trail')} icon={<AuditIcon />} count={`${audits.length} ${t('cm.cases.show.entries')}`}>
            {audits.length === 0 ? <EmptyBox>{t('cm.cases.no_audit_records')}</EmptyBox> : (
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[t('cm.cases.show.when'), t('cm.cases.show.action'), t('cm.cases.show.actor'), t('cm.cases.show.details')].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'text-left')}>{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {audits.map((audit) => (
                                    <tr key={audit.id} className={cx(tableRowClassName, 'align-top')}>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-xs')}><LocalizedDateDisplay value={audit.created_at} withTime /></td>
                                        <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>{tx(`cm.case_audit.actions.${audit.action}`, humanize(audit.action))}</td>
                                        <td className={cx(tableCellClassName, 'text-xs')}>{actor(audit)}</td>
                                        <td className={cx(tableCellClassName, 'text-xs')}>
                                            {audit.details.length > 0 ? (
                                                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                                                    {audit.details.map((line, i) => (
                                                        <div key={i} className="contents">
                                                            <dt className="text-(--app-muted-foreground)">{line.label}</dt>
                                                            <dd className="max-w-md break-words text-(--app-foreground)">{line.kind === 'date' ? <LocalizedDateDisplay value={line.value} withTime /> : line.value}</dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                            ) : <span className="text-(--app-muted-foreground)">—</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </SectionCard>
    );
}

/** The status timeline (case_status_logs): each change as "from → to", its note and when it happened. */
export function TimelineSection({ timeline }: { timeline: CaseTimelineEntry[] }) {
    const { t, text, statusLabel } = useCaseText();

    return (
        <SectionCard id="timeline" title={text('timeline_title')} icon={<ClockIcon />} count={`${timeline.length} ${t('cm.cases.show.entries')}`}>
            {timeline.length === 0 ? <EmptyBox>{text('timeline_empty')}</EmptyBox> : (
                <ol className="relative space-y-3 border-s-2 border-(--app-border) ps-5">
                    {timeline.map((entry) => (
                        <li key={entry.id} className="relative rounded-control border border-(--app-border) bg-(--app-surface-muted) px-4 py-3 text-sm">
                            <span aria-hidden="true" className="absolute -start-[1.72rem] top-4 h-3 w-3 rounded-full border-2 border-(--app-surface) bg-primary" />
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    {entry.from_status ? (
                                        <>
                                            <span className="rounded-full border border-(--app-border) bg-(--app-surface) px-2.5 py-0.5 text-xs text-(--app-muted-foreground)">{statusLabel(entry.from_status)}</span>
                                            <span aria-label={text('timeline_to')} className="text-(--app-muted-foreground)">→</span>
                                        </>
                                    ) : <span className="text-xs text-(--app-muted-foreground)">{text('timeline_opened')}</span>}
                                    <span className="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">{entry.to_status ? statusLabel(entry.to_status) : '—'}</span>
                                </div>
                                <span className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={entry.created_at} withTime /></span>
                            </div>
                            {entry.note && <p className="mt-2 text-xs text-(--app-muted-foreground)"><span className="font-semibold text-(--app-foreground)">{text('timeline_note')}:</span> {entry.note}</p>}
                        </li>
                    ))}
                </ol>
            )}
        </SectionCard>
    );
}
