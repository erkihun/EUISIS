import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import {
    Alert, Card, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { CASE_STATUS_TONES, REVIEW_STATUS_TONES, useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantCaseShowProps } from '@/court/types/applicantCases';
import SectionCard, { EmptyBox } from '../../Cases/Show/SectionCard';
import { DetailsIcon, EyeIcon, MailIcon, PencilIcon, PrintIcon, WitnessesIcon } from '../../Cases/Show/icons';
import FilesCard from './Partials/FilesCard';
import { AuditCard, HearingsCard, LettersCard, MessagesCard, RespondentResponsesCard } from './Partials/ShowAside';

/** A value with a "Copy" button that says "Copied" for a moment, as on the Blade page. */
function CopyValue({ value, className }: { value: string; className?: string }) {
    const { t } = useApplicantCaseText();
    const [copied, setCopied] = useState(false);
    useEffect(() => {
        if (!copied) return undefined;
        const timer = window.setTimeout(() => setCopied(false), 1400);
        return () => window.clearTimeout(timer);
    }, [copied]);

    const copy = () => {
        void navigator.clipboard?.writeText(value).then(() => setCopied(true)).catch(() => undefined);
    };

    return (
        <div className="mt-0.5 flex flex-wrap items-center gap-2">
            <span className={cx('font-semibold tracking-tight text-(--app-foreground)', className)}>{value}</span>
            <button type="button" onClick={copy} className="rounded-full border border-(--app-border) px-3 py-1 text-[11px] text-(--app-muted-foreground) hover:bg-(--app-surface-muted)" aria-live="polite">
                {copied ? t('cm.cases.copied') : t('cm.cases.copy')}
            </button>
        </div>
    );
}

function Info({ label, children }: { label: ReactNode; children: ReactNode }) {
    return (
        <div>
            <dt className="text-sm text-(--app-muted-foreground)">{label}</dt>
            <dd className="text-sm font-medium text-(--app-foreground)">{children}</dd>
        </div>
    );
}

/**
 * The applicant's case page: the header (case number and access code with
 * copy, status, review state, edit / receipt PDF / email / printable receipt),
 * the reviewer's note; the case information, respondent, details, relief,
 * submitted documents, witnesses and uploaded files; and on the right the
 * hearings (calendar download), approved letters, accepted respondent
 * responses, the message thread with its composer and the audit trail.
 */
export default function ApplicantCaseShow(props: ApplicantCaseShowProps) {
    const { case: kase, applicant, docs, witnesses } = props;
    const { t, statusLabel, reviewLabel } = useApplicantCaseText();
    const [emailing, setEmailing] = useState(false);
    const review = kase.review_status;

    const emailReceipt = () => {
        router.post(route('court.applicant.cases.receipt.email', kase.id), {}, {
            preserveScroll: true, onStart: () => setEmailing(true), onFinish: () => setEmailing(false),
        });
    };

    const reviewMessage = review === 'returned'
        ? <Alert tone="warning">{t('cm.cases.review_messages.returned')}</Alert>
        : review === 'rejected'
            ? <Alert tone="danger">{t('cm.cases.review_messages.rejected')}</Alert>
            : review === 'awaiting_review'
                ? <Alert tone="warning">{t('cm.cases.review_messages.awaiting_review')}</Alert>
                : null;

    return (
        <AuthenticatedLayout header={<PageHeader title={kase.case_number} backHref={route('court.applicant.cases.index')} />}>
            <Head title={kase.case_number} />

            <div className="space-y-5">
                <Card className="p-0">
                    <div className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                        <div className="flex flex-wrap items-start gap-6 md:items-center">
                            <div>
                                <div className="text-[11px] uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.case_number')}</div>
                                <CopyValue value={kase.case_number} className="text-xl" />
                            </div>
                            {kase.code && (
                                <div>
                                    <div className="text-[11px] uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.respondent.case_access_code_label')}</div>
                                    <CopyValue value={kase.code} className="text-lg" />
                                </div>
                            )}
                            <div className="flex flex-wrap items-center gap-2">
                                <span className={badgeClassName(CASE_STATUS_TONES[kase.status] ?? 'neutral')}>{statusLabel(kase.status)}</span>
                                <span className={badgeClassName(REVIEW_STATUS_TONES[review] ?? 'success')}>{reviewLabel(review)}</span>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            {review !== 'accepted' && (
                                <Link href={route('court.applicant.cases.edit', kase.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                    <PencilIcon className="mr-1 h-3.5 w-3.5" />{t('cm.cases.edit_case')}
                                </Link>
                            )}
                            <a href={route('court.applicant.cases.receipt.pdf', kase.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.cases.download_pdf')}</a>
                            <button type="button" onClick={emailReceipt} disabled={emailing} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                <MailIcon className="mr-1 h-3.5 w-3.5" />{t('cm.cases.email_pdf')}
                            </button>
                            <a href={route('court.applicant.cases.receipt', kase.id)} target="_blank" rel="noopener" className={buttonClassName({ variant: 'ghost', size: 'sm' })}>
                                <PrintIcon className="mr-1 h-3.5 w-3.5" />{t('cm.cases.filing_receipt')}
                            </a>
                        </div>
                    </div>
                    {kase.review_note && (
                        <div className="border-t border-(--app-border) px-5 py-3 text-xs leading-snug text-(--app-muted-foreground)">
                            <span className="font-semibold text-(--app-foreground)">{t('cm.cases.reviewer_note')}</span>{' '}
                            {kase.review_note}
                            {review === 'returned' && kase.status === 'pending' && (
                                <Link href={route('court.applicant.cases.edit', kase.id)} className="ml-1 font-semibold text-primary hover:underline">{t('cm.cases.edit_your_case')}</Link>
                            )}
                        </div>
                    )}
                </Card>

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="space-y-5 lg:col-span-2">
                        <SectionCard id="case-information" title={t('cm.cases.case_information')} icon={<DetailsIcon className="h-4 w-4" />}>
                            {reviewMessage}
                            <dl className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <Info label={t('cm.cases.applicant_full_name')}>{applicant.name}</Info>
                                <Info label={t('cm.cases.applicant_address')}>{applicant.address}</Info>
                                <Info label={t('cm.cases.case_type')}>{kase.case_type ?? '-'}</Info>
                                <Info label={t('cm.cases.filed')}><LocalizedDateDisplay value={kase.filed_at} /></Info>
                            </dl>

                            {(kase.respondent_name || kase.respondent_address) && (
                                <div>
                                    <h3 className="mb-3 text-sm font-semibold text-(--app-foreground)">{t('cm.cases.respondent_defendant')}</h3>
                                    <dl className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <Info label={t('cm.cases.name')}>{kase.respondent_name ?? '—'}</Info>
                                        <Info label={t('cm.cases.address')}>{kase.respondent_address ?? '—'}</Info>
                                    </dl>
                                </div>
                            )}

                            <div>
                                <h3 className="mb-2 text-sm font-semibold text-(--app-foreground)">{t('cm.cases.case_details')}</h3>
                                <RichTextViewer html={kase.description_html} className="text-justify" />
                            </div>

                            {kase.relief_html && (
                                <div>
                                    <h3 className="mb-2 text-sm font-semibold text-(--app-foreground)">{t('cm.cases.relief_requested')}</h3>
                                    <RichTextViewer html={kase.relief_html} className="text-justify" />
                                </div>
                            )}
                        </SectionCard>

                        <SectionCard id="submitted-documents" title={t('cm.cases.submitted_documents')} icon={<DetailsIcon className="h-4 w-4" />} count={docs.length}>
                            {docs.length === 0 ? <EmptyBox>{t('cm.cases.no_submitted_documents')}</EmptyBox> : (
                                <ul className="divide-y divide-(--app-border)">
                                    {docs.map((doc) => (
                                        <li key={doc.id} className="flex items-center justify-between gap-4 py-2.5">
                                            <div className="min-w-0 flex-1">
                                                <div className="font-medium text-(--app-foreground)">{doc.title ?? t('cm.cases.document')}</div>
                                                <div className="flex flex-wrap gap-1 text-xs text-(--app-muted-foreground)">
                                                    <span>{doc.mime ?? t('cm.cases.file')}</span>
                                                    {doc.size !== null && <span>· {(doc.size / 1024).toFixed(1)} KB</span>}
                                                    {doc.created_at && <span>· <LocalizedDateDisplay value={doc.created_at} withTime /></span>}
                                                </div>
                                                {doc.description_html && <RichTextViewer html={doc.description_html} className="mt-1 text-xs" />}
                                            </div>
                                            {doc.has_file && (
                                                <a href={route('court.applicant.cases.evidences.download', { id: kase.id, evidenceId: doc.id })} target="_blank" rel="noopener" className={buttonClassName({ variant: 'outline', size: 'xs' })}>
                                                    <EyeIcon className="mr-1 h-3 w-3" />{t('cm.cases.view')}
                                                </a>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        <SectionCard id="witnesses" title={t('cm.cases.witnesses_section.title')} icon={<WitnessesIcon className="h-4 w-4" />} count={witnesses.length}>
                            {witnesses.length === 0 ? <EmptyBox>{t('cm.cases.no_witnesses_listed')}</EmptyBox> : (
                                <div className={tableContainerClassName}>
                                    <div className="overflow-x-auto">
                                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                                            <thead className={tableHeadClassName}>
                                                <tr>
                                                    {[t('cm.cases.labels.name'), t('cm.cases.labels.phone'), t('cm.cases.labels.email'), t('cm.cases.labels.address')].map((heading) => (
                                                        <th key={heading} className={tableHeaderCellClassName}>{heading}</th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody className={tableBodyClassName}>
                                                {witnesses.map((witness) => (
                                                    <tr key={witness.id} className={tableRowClassName}>
                                                        <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>{witness.full_name}</td>
                                                        <td className={tableCellClassName}>{witness.phone ?? '—'}</td>
                                                        <td className={tableCellClassName}>
                                                            {witness.email ? <a href={`mailto:${witness.email}`} className="text-primary hover:underline">{witness.email}</a> : '—'}
                                                        </td>
                                                        <td className={tableCellClassName}>{witness.address ?? '—'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </SectionCard>

                        <FilesCard caseId={kase.id} files={props.files} />
                    </div>

                    <div className="space-y-5">
                        <HearingsCard caseId={kase.id} hearings={props.hearings} />
                        <LettersCard letters={props.letters} />
                        <RespondentResponsesCard caseId={kase.id} caseNumber={kase.case_number} responses={props.respondentResponses} />
                        <MessagesCard caseId={kase.id} messages={props.messages} />
                        <AuditCard audits={props.audits} />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
