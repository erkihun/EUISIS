import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { StatusBadge as UiStatusBadge } from '@euisis/ui';
import { Alert, Card, buttonClassName, cx } from '@/Components/ui';
import type { CaseDetailCase, CaseDetailPermissions, CaseSection } from '@/court/types/caseDetail';
import { REVIEW_TONES, STATUS_TONES, useCaseText } from './useCaseText';
import { BackIcon, CheckIcon, ChevronDownIcon, CloseIcon, DetailsIcon, LetterIcon, NoteIcon, PlusIcon, ReturnIcon, UsersIcon, WarningIcon } from './icons';

export type ReviewDecision = 'accept' | 'return' | 'reject';

interface Props {
    kase: CaseDetailCase;
    can: CaseDetailPermissions;
    caseLocked: boolean;
    onOpenSection: (section: CaseSection) => void;
    onReview: (decision: ReviewDecision) => void;
}

/**
 * The header card: case number with a copy button, status and review chips,
 * the meta row, and the actions the viewer may take (inspection request,
 * review, assign, bench note, write letter, give decision, back).
 */
export default function HeaderCard({ kase, can, caseLocked, onOpenSection, onReview }: Props) {
    const { t, statusLabel, reviewLabel } = useCaseText();
    const [copied, setCopied] = useState(false);
    const [reviewOpen, setReviewOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!reviewOpen) return;
        const close = (event: MouseEvent) => {
            if (menuRef.current && !menuRef.current.contains(event.target as Node)) setReviewOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [reviewOpen]);

    const copyNumber = async () => {
        try {
            await navigator.clipboard.writeText(kase.case_number);
            setCopied(true);
            setTimeout(() => setCopied(false), 1400);
        } catch {
            // The clipboard is unavailable (insecure context): nothing to show.
        }
    };

    const canReviewNow = can.review && ['awaiting_review', 'returned'].includes(kase.review_status);
    const primary = (extra?: string) => buttonClassName({ variant: 'primary', size: 'sm', className: extra });

    return (
        <Card className="overflow-hidden p-0">
            <div className="h-1 w-full bg-linear-to-r from-primary to-primary/60" aria-hidden="true" />
            <div className="px-5 py-5">
                <div className="flex w-full flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
                    <div className="min-w-0 flex-1">
                        <p className="mb-1.5 text-[10.5px] font-semibold uppercase tracking-[.16em] text-(--app-muted-foreground)">{t('cm.cases.case_number')}</p>
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="font-mono text-2xl font-bold tracking-tight text-(--app-foreground)" id="case-no">{kase.case_number}</span>
                            <button type="button" onClick={copyNumber} className={buttonClassName({ variant: 'outline', size: 'xs' })}>
                                {copied ? t('cm.cases.copied') : t('cm.cases.copy')}
                            </button>
                            <UiStatusBadge tone={STATUS_TONES[kase.status] ?? 'neutral'} className="print:hidden">{statusLabel(kase.status)}</UiStatusBadge>
                            <UiStatusBadge tone={REVIEW_TONES[kase.review_status] ?? 'success'} className="print:hidden">{reviewLabel(kase.review_status)}</UiStatusBadge>
                        </div>
                        <div className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-[12.5px] text-(--app-muted-foreground)">
                            {kase.filing_date && (
                                <span><span className="opacity-70">{t('cm.cases.summary.filing_date')}:</span> <LocalizedDateDisplay value={kase.filing_date} /></span>
                            )}
                            {kase.applicant_display_name && (
                                <span><span className="opacity-70">{t('cm.cases.summary.applicant')}:</span> {kase.applicant_display_name}</span>
                            )}
                            {kase.case_type && (
                                <span><span className="opacity-70">{t('cm.cases.table.type')}:</span> {kase.case_type}</span>
                            )}
                            {kase.assignee_name && (
                                <span><span className="opacity-70">{t('cm.cases.summary.assignee')}:</span> {kase.assignee_name}</span>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        {can.manageInspectionRequests && (
                            <a href={route('court.case-inspection-requests.create', { case_id: kase.id })} className={primary()}>
                                <DetailsIcon className="h-3.5 w-3.5" /> {t('cm.cases.show.inspection_request')}
                            </a>
                        )}

                        {canReviewNow && (
                            <div className="relative" ref={menuRef}>
                                <button type="button" className={primary()} onClick={() => setReviewOpen((open) => !open)} aria-haspopup="menu" aria-expanded={reviewOpen}>
                                    {t('cm.cases.show.review_actions')} <ChevronDownIcon className="h-3.5 w-3.5" />
                                </button>
                                {reviewOpen && (
                                    <div role="menu" className="absolute right-0 top-full z-30 mt-1.5 w-48 overflow-hidden rounded-control border border-(--app-border) bg-(--app-surface) py-1.5 shadow-xl">
                                        <button type="button" role="menuitem" className="flex w-full items-center gap-2.5 px-3.5 py-2 text-sm text-emerald-700 hover:bg-(--app-surface-muted) dark:text-emerald-300" onClick={() => { setReviewOpen(false); onReview('accept'); }}>
                                            <CheckIcon className="h-4 w-4 shrink-0" /> {t('cm.cases.show.accept')}
                                        </button>
                                        <button type="button" role="menuitem" className="flex w-full items-center gap-2.5 px-3.5 py-2 text-sm text-amber-700 hover:bg-(--app-surface-muted) dark:text-amber-300" onClick={() => { setReviewOpen(false); onReview('return'); }}>
                                            <ReturnIcon className="h-4 w-4 shrink-0" /> {t('cm.cases.show.return')}
                                        </button>
                                        <button type="button" role="menuitem" className="flex w-full items-center gap-2.5 px-3.5 py-2 text-sm text-red-700 hover:bg-(--app-surface-muted) dark:text-red-300" onClick={() => { setReviewOpen(false); onReview('reject'); }}>
                                            <CloseIcon className="h-4 w-4 shrink-0" /> {t('cm.cases.show.reject')}
                                        </button>
                                    </div>
                                )}
                            </div>
                        )}

                        {!caseLocked && can.assign && (
                            <Link href={route('court.cases.assign.form', kase.id)} className={primary()}>
                                <UsersIcon className="h-3.5 w-3.5" /> {t('cm.cases.assign_change')}
                            </Link>
                        )}
                        {!caseLocked && can.manageBench && (
                            <a href={route('court.bench-notes.index', { case_id: kase.id })} className={primary('bg-amber-500 hover:bg-amber-600')}>
                                <NoteIcon className="h-3.5 w-3.5" /> {t('cm.cases.show.bench_note')}
                            </a>
                        )}
                        {!caseLocked && can.writeLetter && (
                            <button type="button" onClick={() => onOpenSection('letters-compose')} className={primary('bg-emerald-600 hover:bg-emerald-700')}>
                                <LetterIcon className="h-3.5 w-3.5" /> {t('cm.cases.show.write_letter')}
                            </button>
                        )}
                        {!caseLocked && kase.status === 'closed' && (
                            <a href={route('court.decisions.create', { case_id: kase.id })} className={primary()}>
                                <PlusIcon className="h-3.5 w-3.5" /> {t('cm.cases.show.give_decision')}
                            </a>
                        )}
                        <Link href={route('court.cases.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                            <BackIcon className="h-3.5 w-3.5" /> {t('cm.cases.back')}
                        </Link>
                    </div>
                </div>

                {caseLocked && (
                    <Alert tone="warning" className={cx('mt-4 flex items-center gap-2.5')}>
                        <WarningIcon className="h-4 w-4 shrink-0" /> <span>{t('cm.cases.show.actions_locked')}</span>
                    </Alert>
                )}
            </div>
        </Card>
    );
}
