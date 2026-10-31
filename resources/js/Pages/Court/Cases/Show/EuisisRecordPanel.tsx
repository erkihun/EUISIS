import { badgeClassName } from '@/Components/ui';
import type { EuisisRecordData } from '@/court/types/caseDetail';
import { useCaseText } from './useCaseText';
import { AuditIcon } from './icons';

interface Props {
    record: EuisisRecordData;
}

/**
 * Flow 5: the grievance record an employee attached when filing from the EUISIS
 * employee portal, each grievance with the responses and decisions it received
 * there. Its PDFs are among the case's documents.
 */
export default function EuisisRecordPanel({ record }: Props) {
    const { t } = useCaseText();
    const dash = (value: string | null | undefined) => (value && value.trim() !== '' ? value : '—');
    // EUISIS sends the grievance status as its code (decision_issued); shown as words.
    const statusText = (status: string) => status.replace(/_/g, ' ').replace(/^./, (first) => first.toUpperCase());

    return (
        <section id="euisis-record" className="space-y-4 rounded-control border border-teal-200 bg-teal-50/40 p-4 dark:border-teal-900 dark:bg-teal-950/20">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-teal-100 text-teal-700 dark:bg-teal-900 dark:text-teal-200"><AuditIcon /></span>
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.euisis_record.title')}</div>
                        <p className="text-xs text-(--app-muted-foreground)">{t('cm.cases.euisis_record.description')}</p>
                    </div>
                </div>
                {record.filed_from_euisis && <span className={badgeClassName('info')}>{t('cm.cases.euisis_record.filed_from_euisis')}</span>}
            </div>

            {record.grievances.length === 0 ? (
                <p className="text-sm text-(--app-muted-foreground)">{t('cm.cases.euisis_record.none')}</p>
            ) : (
                <ol className="space-y-3">
                    {record.grievances.map((grievance) => (
                        <li key={grievance.id} className="space-y-3 rounded-control border border-(--app-border) bg-(--app-surface) p-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="font-semibold text-(--app-foreground)">{grievance.subject}</div>
                                    {grievance.category && <div className="text-xs text-(--app-muted-foreground)">{grievance.category}</div>}
                                </div>
                                {grievance.status && <span className={badgeClassName('neutral')}>{statusText(grievance.status)}</span>}
                            </div>

                            <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                                <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.cases.euisis_record.reference')}</dt><dd className="font-mono text-(--app-foreground)">{dash(grievance.reference)}</dd></div>
                                <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.cases.euisis_record.submitted')}</dt><dd className="text-(--app-foreground)">{dash(grievance.submitted_at)}</dd></div>
                                <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.cases.euisis_record.closed')}</dt><dd className="text-(--app-foreground)">{dash(grievance.closed_at)}</dd></div>
                            </dl>

                            {grievance.description && <p className="whitespace-pre-line text-sm text-(--app-foreground)">{grievance.description}</p>}

                            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                <div className="space-y-2">
                                    <div className="text-xs font-semibold text-(--app-muted-foreground)">{t('cm.cases.euisis_record.responses')}</div>
                                    {grievance.responses.length === 0 ? (
                                        <p className="text-sm text-(--app-muted-foreground)">—</p>
                                    ) : grievance.responses.map((response, index) => (
                                        <div key={index} className="rounded-control border border-(--app-border) p-2 text-sm">
                                            <p className="whitespace-pre-line text-(--app-foreground)">{response.body}</p>
                                            {response.at && <p className="mt-1 text-xs text-(--app-muted-foreground)">{response.at}</p>}
                                        </div>
                                    ))}
                                </div>
                                <div className="space-y-2">
                                    <div className="text-xs font-semibold text-(--app-muted-foreground)">{t('cm.cases.euisis_record.decisions')}</div>
                                    {grievance.decisions.length === 0 ? (
                                        <p className="text-sm text-(--app-muted-foreground)">—</p>
                                    ) : grievance.decisions.map((decision, index) => (
                                        <div key={index} className="rounded-control border border-(--app-border) p-2 text-sm">
                                            {(decision.number || decision.type) && (
                                                <p className="text-xs font-semibold text-(--app-muted-foreground)">{[decision.number, decision.type].filter(Boolean).join(' · ')}</p>
                                            )}
                                            <p className="whitespace-pre-line text-(--app-foreground)">{decision.text}</p>
                                            {decision.at && <p className="mt-1 text-xs text-(--app-muted-foreground)">{decision.at}</p>}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
