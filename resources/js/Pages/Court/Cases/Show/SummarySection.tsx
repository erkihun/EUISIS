import { useEffect, useState } from 'react';
import { StatusBadge as UiStatusBadge } from '@euisis/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Alert, buttonClassName } from '@/Components/ui';
import type { CaseDetailCase, CaseHearing, EuisisPanelData, EuisisRecordData } from '@/court/types/caseDetail';
import EuisisPanel from './EuisisPanel';
import EuisisRecordPanel from './EuisisRecordPanel';
import SectionCard, { MetaTile } from './SectionCard';
import { REVIEW_TONES, STATUS_TONES, useCaseText } from './useCaseText';
import { CalendarIcon, CloseIcon, ExternalIcon, EyeIcon, FilesIcon, UserIcon, UsersIcon, WarningIcon } from './icons';

interface Props {
    kase: CaseDetailCase;
    hearings: CaseHearing[];
    euisis: EuisisPanelData | null;
    euisisRecord?: EuisisRecordData | null;
}

const stripTags = (html: string): string => html.replace(/<[^>]*>/g, '');
const limit = (text: string, max: number): string => (text.length > max ? `${text.slice(0, max)}...` : text);

/** The case summary: key facts, the lawyer notice, the parties, the EUISIS panel, hearings, assignment and timestamps. */
export default function SummarySection({ kase, hearings, euisis, euisisRecord = null }: Props) {
    const { t, statusLabel, reviewLabel, fill } = useCaseText();
    const [pdfOpen, setPdfOpen] = useState(false);

    useEffect(() => {
        if (!pdfOpen) return;
        const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') setPdfOpen(false); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [pdfOpen]);

    const lawyerDocumentUrl = route('court.cases.lawyer-document', kase.id);

    return (
        <SectionCard
            id="case-summary"
            title={t('cm.cases.navigation.case_summary')}
            icon={<FilesIcon />}
            actions={(
                <>
                    <UiStatusBadge tone={STATUS_TONES[kase.status] ?? 'neutral'}>{statusLabel(kase.status)}</UiStatusBadge>
                    <UiStatusBadge tone={REVIEW_TONES[kase.review_status] ?? 'success'}>{reviewLabel(kase.review_status)}</UiStatusBadge>
                </>
            )}
        >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <MetaTile label={t('cm.cases.summary.type')}>{kase.case_type ?? '—'}</MetaTile>
                <MetaTile label={t('cm.cases.summary.filing_date')}><LocalizedDateDisplay value={kase.filing_date} /></MetaTile>
                <MetaTile label={t('cm.cases.case_number')}>{kase.case_number || '—'}</MetaTile>
            </div>

            {kase.submitted_by_lawyer && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-control border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-900 dark:bg-indigo-950/30">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-200"><UserIcon className="h-5 w-5" /></span>
                        <p className="font-semibold text-indigo-900 dark:text-indigo-100">{fill(t('cm.dashboard.submitted_by_lawyer'), { name: kase.lawyer_name })}</p>
                    </div>
                    {kase.has_lawyer_document && (
                        <button type="button" onClick={() => setPdfOpen(true)} className={buttonClassName({ variant: 'outline', size: 'xs' })}>
                            <EyeIcon className="h-4 w-4" /> {t('cm.cases.show.view_lawyer_document')}
                        </button>
                    )}
                    {pdfOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label={t('cm.cases.show.lawyer_document')}>
                            <div className="absolute inset-0 bg-black/50" onClick={() => setPdfOpen(false)} aria-hidden="true" />
                            <div className="relative z-10 flex h-[80vh] w-full max-w-6xl flex-col overflow-hidden rounded-card bg-(--app-surface) shadow-xl">
                                <div className="flex items-center justify-between bg-orange-500 px-4 py-3">
                                    <h4 className="text-sm font-semibold text-white">{t('cm.cases.show.lawyer_document')}</h4>
                                    <div className="flex items-center gap-2">
                                        <a href={lawyerDocumentUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 rounded-control border border-white/40 bg-white/10 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-white/20">
                                            <ExternalIcon className="h-4 w-4" /> {t('cm.cases.show.open_new_tab')}
                                        </a>
                                        <button type="button" onClick={() => setPdfOpen(false)} className="inline-flex h-8 w-8 items-center justify-center rounded-control text-white hover:bg-white/20" aria-label={t('cm.cases.general.cancel')}>
                                            <CloseIcon className="h-5 w-5" />
                                        </button>
                                    </div>
                                </div>
                                <iframe src={lawyerDocumentUrl} className="min-h-0 w-full flex-1 border-0" title={t('cm.cases.show.lawyer_document')} />
                            </div>
                        </div>
                    )}
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="rounded-control border border-indigo-100 bg-indigo-50/40 p-4 dark:border-indigo-900 dark:bg-indigo-950/20">
                    <div className="mb-2 flex items-center gap-2">
                        <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-200"><UserIcon /></span>
                        <div className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.summary.applicant')}</div>
                    </div>
                    <p className="font-semibold text-(--app-foreground)">{kase.applicant_display_name !== '' ? kase.applicant_display_name : '—'}</p>
                    <p className="mt-0.5 whitespace-pre-line text-xs text-(--app-muted-foreground)">{kase.applicant_address !== '' ? kase.applicant_address : '—'}</p>
                </div>
                <div className="rounded-control border border-rose-100 bg-rose-50/40 p-4 dark:border-rose-900 dark:bg-rose-950/20">
                    <div className="mb-2 flex items-center gap-2">
                        <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-200"><UsersIcon /></span>
                        <div className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.respondent_defendant')}</div>
                    </div>
                    <p className="font-semibold text-(--app-foreground)">{kase.respondent_name ?? '—'}</p>
                    <p className="mt-0.5 whitespace-pre-line text-xs text-(--app-muted-foreground)">{kase.respondent_address ?? '—'}</p>
                </div>
            </div>

            {euisis && <EuisisPanel caseId={kase.id} panel={euisis} />}
            {euisisRecord && <EuisisRecordPanel record={euisisRecord} />}

            <div className="border-t border-(--app-border) pt-4">
                <div className="mb-2 text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.summary.hearings')}</div>
                {hearings.length > 0 ? (
                    <div className="space-y-2">
                        {hearings.map((hearing) => (
                            <div key={hearing.id} className="flex items-start gap-3 rounded-control border border-(--app-border) bg-(--app-surface) p-3">
                                <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-control bg-primary/10 text-primary"><CalendarIcon /></span>
                                <div>
                                    <div className="font-semibold text-(--app-foreground)"><LocalizedDateDisplay value={hearing.hearing_at} withTime /></div>
                                    <div className="text-xs text-(--app-muted-foreground)">
                                        {t('cm.cases.case_number')} {kase.case_number}
                                        {hearing.notes && <> · {limit(stripTags(hearing.notes), 60)}</>}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-control border border-dashed border-(--app-border) bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-muted-foreground)">—</div>
                )}
            </div>

            <div className="border-t border-(--app-border) pt-4">
                <div className="mb-2 text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.summary.assignee')}</div>
                {kase.assignee_name ? (
                    <div className="space-y-2 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4">
                        <div className="flex items-center gap-2 font-semibold text-(--app-foreground)">
                            <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200"><UserIcon /></span>
                            {kase.assignee_name}
                        </div>
                        {kase.assignee_team_name && (
                            <div className="text-sm text-(--app-muted-foreground)">{t('cm.cases.summary.team')}: <span className="font-medium text-(--app-foreground)">{kase.assignee_team_name}</span></div>
                        )}
                        {kase.assigned_at && (
                            <div>
                                <div className="mb-0.5 text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.summary.assigned_at')}</div>
                                <div className="text-sm font-medium text-(--app-foreground)"><LocalizedDateDisplay value={kase.assigned_at} withTime /></div>
                            </div>
                        )}
                    </div>
                ) : (
                    <Alert tone="warning" className="flex items-center gap-2"><WarningIcon className="h-4 w-4 shrink-0" /> <span className="text-sm font-medium">{t('cm.cases.summary.unassigned')}</span></Alert>
                )}
            </div>

            <div className="border-t border-(--app-border) pt-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <MetaTile label={t('cm.cases.summary.created')}><LocalizedDateDisplay value={kase.created_at} withTime /></MetaTile>
                    <MetaTile label={t('cm.cases.summary.updated')}><LocalizedDateDisplay value={kase.updated_at} withTime /></MetaTile>
                </div>
            </div>
        </SectionCard>
    );
}
