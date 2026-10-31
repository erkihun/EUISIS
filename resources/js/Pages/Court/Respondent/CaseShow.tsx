import { Head, Link } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import { CalendarIcon, DownloadIcon, MapPinned } from '@/Components/Icons';
import {
    Alert, Button, Card, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import type { RespondentCaseShowProps } from '@/court/types/respondent';
import { CASE_STATUS_TONES, LETTER_TONES, useStatusLabels } from './Partials/status';

/** A titled block with its item count, as the Blade case page laid each list out. */
function Section({ title, count, children, className }: { title: string; count?: number; children: ReactNode; className?: string }) {
    return (
        <section className={cx('space-y-3', className)}>
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-sm font-semibold text-(--app-foreground)">{title}</h2>
                {count !== undefined && <span className="text-xs text-(--app-muted-foreground)">{count}</span>}
            </div>
            {children}
        </section>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="rounded-lg border border-dashed border-(--app-border) px-4 py-6 text-center text-sm text-(--app-muted-foreground)">{children}</p>;
}

/**
 * A case as the respondent sees it: its state, the case information, details and relief asked for, the
 * documents filed with it, hearings, approved letters and the published decisions to download, and the way
 * to give a response.
 */
export default function RespondentCaseShow({ case: item, docs, hearings, letters, decisions, witnesses, audits }: RespondentCaseShowProps) {
    const { t, tx, text } = useRespondentText();
    const { caseStatus } = useStatusLabels();
    const [copied, setCopied] = useState(false);
    // cases.not_available is missing from the English lang file; the labels one says the same.
    const notAvailable = tx('cm.cases.not_available', t('cm.cases.labels.not_available'));

    useEffect(() => {
        if (!copied) return undefined;
        const timer = window.setTimeout(() => setCopied(false), 1400);
        return () => window.clearTimeout(timer);
    }, [copied]);

    const copyNumber = () => {
        void navigator.clipboard?.writeText(item.case_number).then(() => setCopied(true), () => undefined);
    };

    const actions = (
        <>
            <span className={badgeClassName(CASE_STATUS_TONES[item.status] ?? 'neutral')}>{caseStatus(item.status)}</span>
            <Button variant="outline" size="sm" onClick={copyNumber} aria-live="polite">{copied ? t('cm.cases.copied') : t('cm.cases.copy')}</Button>
            <Link href={route('court.respondent.responses.create', { case_number: item.case_number })} className={buttonClassName({})}>{t('cm.respondent.give_response')}</Link>
        </>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={item.case_number} description={t('cm.cases.case_number')} actions={actions} />}>
            <Head title={item.case_number} />

            <div className="space-y-4">
                {item.review_note && (
                    <p className="text-sm text-(--app-muted-foreground)">
                        <span className="font-semibold text-(--app-foreground)">{t('cm.cases.reviewer_note')}</span> {item.review_note}
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="space-y-6 lg:col-span-2">
                        {['returned', 'rejected', 'awaiting_review'].includes(item.review_status) && (
                            <Alert tone="warning">{t('cm.cases.review_notice')}</Alert>
                        )}

                        <Section title={t('cm.cases.case_information')}>
                            <dl className="grid gap-4 text-sm md:grid-cols-2">
                                <div><dt className="text-(--app-muted-foreground)">{t('cm.cases.case_type')}</dt><dd className="font-medium text-(--app-foreground)">{item.case_type || '-'}</dd></div>
                                <div><dt className="text-(--app-muted-foreground)">{t('cm.cases.filed')}</dt><dd className="font-medium text-(--app-foreground)"><LocalizedDateDisplay value={item.filed_at} fallback="-" /></dd></div>
                                <div><dt className="text-(--app-muted-foreground)">{t('cm.cases.applicant_full_name')}</dt><dd className="font-medium text-(--app-foreground)">{item.applicant_name || '-'}</dd></div>
                                <div><dt className="text-(--app-muted-foreground)">{t('cm.cases.applicant_email')}</dt><dd className="font-medium text-(--app-foreground)">{item.applicant_email || '-'}</dd></div>
                            </dl>
                        </Section>

                        {(item.respondent_name || item.respondent_address) && (
                            <Section title={t('cm.cases.respondent_defendant')}>
                                <dl className="grid gap-4 text-sm md:grid-cols-2">
                                    <div><dt className="text-(--app-muted-foreground)">{t('cm.cases.name')}</dt><dd className="font-medium text-(--app-foreground)">{item.respondent_name || notAvailable}</dd></div>
                                    <div><dt className="text-(--app-muted-foreground)">{t('cm.cases.address')}</dt><dd className="font-medium text-(--app-foreground)">{item.respondent_address || notAvailable}</dd></div>
                                </dl>
                            </Section>
                        )}

                        <Section title={t('cm.cases.case_details')}>
                            <RichTextViewer html={item.description_html} className="text-sm text-(--app-foreground)" />
                        </Section>

                        {item.relief_requested_html && (
                            <Section title={t('cm.cases.relief_requested')}>
                                <RichTextViewer html={item.relief_requested_html} className="text-sm text-(--app-foreground)" />
                            </Section>
                        )}

                        <Section title={t('cm.cases.submitted_documents')} count={docs.length}>
                            {docs.length === 0 ? <Empty>{t('cm.cases.no_submitted_documents')}</Empty> : (
                                <ul className="divide-y divide-(--app-border) text-sm">
                                    {docs.map((doc) => (
                                        <li key={doc.id} className="flex items-center justify-between gap-4 py-2">
                                            <div className="min-w-0 flex-1">
                                                <div className="font-medium text-(--app-foreground)">{doc.title || t('cm.cases.document')}</div>
                                                <div className="flex flex-wrap gap-1 text-xs text-(--app-muted-foreground)">
                                                    <span>{doc.mime || t('cm.cases.file')}</span>
                                                    {doc.size !== null && <span>· {text('size_kb', { size: (doc.size / 1024).toFixed(1) })}</span>}
                                                    {doc.created_at && <span>· <LocalizedDateDisplay value={doc.created_at} withTime /></span>}
                                                </div>
                                                {doc.description_html && <RichTextViewer html={doc.description_html} className="mt-1 text-xs text-(--app-muted-foreground)" />}
                                            </div>
                                            {doc.download_url && (
                                                <a href={doc.download_url} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.cases.view')}</a>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>

                        <Section title={t('cm.cases.witnesses_section.title')} count={witnesses.length}>
                            {witnesses.length === 0 ? <Empty>{t('cm.cases.no_witnesses_listed')}</Empty> : (
                                <div className="overflow-x-auto">
                                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                                        <thead className={tableHeadClassName}>
                                            <tr>
                                                {[t('cm.cases.labels.name'), t('cm.cases.labels.phone'), t('cm.cases.labels.email'), t('cm.cases.labels.address')].map((heading) => (
                                                    <th key={heading} className={cx(tableHeaderCellClassName, 'text-left')}>{heading}</th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className={tableBodyClassName}>
                                            {witnesses.map((witness, index) => (
                                                <tr key={`${witness.full_name}-${index}`} className={tableRowClassName}>
                                                    <td className={cx(tableCellClassName, 'font-medium')}>{witness.full_name}</td>
                                                    <td className={tableCellClassName}>{witness.phone || notAvailable}</td>
                                                    <td className={tableCellClassName}>{witness.email ? <a href={`mailto:${witness.email}`} className="text-primary hover:underline">{witness.email}</a> : notAvailable}</td>
                                                    <td className={tableCellClassName}>{witness.address || notAvailable}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Section>
                    </Card>

                    <div className="space-y-4">
                        <Card>
                            <Section title={t('cm.cases.hearings.title')} count={hearings.length}>
                                {hearings.length === 0 ? <Empty>{t('cm.cases.no_hearings_scheduled')}</Empty> : (
                                    <ul className="max-h-64 space-y-3 overflow-y-auto pr-1 text-sm">
                                        {hearings.map((hearing) => (
                                            <li key={hearing.id} className="flex items-start gap-3 rounded-lg border border-(--app-border) p-3">
                                                <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full bg-(--app-surface-muted) text-(--app-muted-foreground)">
                                                    <CalendarIcon className="h-4 w-4" aria-hidden="true" />
                                                </span>
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <LocalizedDateDisplay value={hearing.hearing_at} withTime className="font-medium text-(--app-foreground)" />
                                                        {hearing.type && <span className={badgeClassName('info')}>{hearing.type}</span>}
                                                    </div>
                                                    <div className="mt-1 flex items-center gap-1.5 text-xs text-(--app-muted-foreground)">
                                                        <MapPinned className="h-3.5 w-3.5" aria-hidden="true" />
                                                        <span>{hearing.location || notAvailable}</span>
                                                    </div>
                                                    {hearing.notes && <p className="mt-2 text-xs text-(--app-muted-foreground)">{hearing.notes}</p>}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                        </Card>

                        <Card>
                            <Section title={t('cm.cases.letters_section.title')} count={letters.length}>
                                {letters.length === 0 ? <Empty>{t('cm.cases.letters_section.empty')}</Empty> : (
                                    <ul className="max-h-64 space-y-3 overflow-y-auto pr-1">
                                        {letters.map((letter) => (
                                            <li key={letter.id} className="flex items-start justify-between gap-2 rounded-lg border border-(--app-border) p-3">
                                                <div className="min-w-0 space-y-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-semibold text-(--app-foreground)">{letter.subject || letter.template_title || t('cm.cases.letters_section.letter')}</span>
                                                        <span className={badgeClassName(LETTER_TONES[letter.approval_status] ?? 'neutral')}>{t(`cm.cases.letters_section.status.${letter.approval_status}`)}</span>
                                                    </div>
                                                    <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-(--app-muted-foreground)">
                                                        <span>{t('cm.cases.letters_section.reference')}: {letter.reference_number || notAvailable}</span>
                                                        <span>{t('cm.cases.letters_section.template')}: {letter.template_title || notAvailable}</span>
                                                        <span>{t('cm.cases.letters_section.author')}: {letter.author_name || notAvailable}</span>
                                                        <span>{t('cm.cases.letters_section.created')}: <LocalizedDateDisplay value={letter.created_at} withTime /></span>
                                                    </div>
                                                </div>
                                                <a href={letter.preview_url} target="_blank" rel="noopener noreferrer" className="shrink-0 text-xs font-semibold text-primary underline">{t('cm.cases.view')}</a>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                        </Card>

                        <Card>
                            <Section title={t('cm.dashboard.decisions')} count={decisions.length}>
                                {decisions.length === 0 ? <Empty>{t('cm.dashboard.no_decisions')}</Empty> : (
                                    <ul className="space-y-3 text-sm">
                                        {decisions.map((decision) => (
                                            <li key={decision.id} className="flex items-center justify-between gap-3 rounded-lg border border-(--app-border) p-3">
                                                <div className="min-w-0">
                                                    <div className="truncate font-semibold text-(--app-foreground)">{decision.name || t('cm.dashboard.decisions')}</div>
                                                    <LocalizedDateDisplay value={decision.date} className="text-xs text-(--app-muted-foreground)" />
                                                </div>
                                                <a href={decision.download_url} className={buttonClassName({ size: 'sm', className: 'whitespace-nowrap' })}>
                                                    <DownloadIcon className="h-3.5 w-3.5" />{t('cm.decisions.download')}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                        </Card>

                        <Card>
                            <Section title={t('cm.cases.case_audit_trail')} count={audits.length}>
                                {audits.length === 0 ? <Empty>{t('cm.cases.no_audit_records')}</Empty> : (
                                    <ul className="max-h-64 space-y-3 overflow-y-auto text-sm">
                                        {audits.map((audit, index) => (
                                            <li key={`${audit.created_at}-${index}`} className="rounded-lg border border-(--app-border) p-3">
                                                <div className="flex items-center gap-2 text-xs text-(--app-muted-foreground)">
                                                    <LocalizedDateDisplay value={audit.created_at} withTime />
                                                    <span className={badgeClassName('neutral')}>{audit.action.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())}</span>
                                                </div>
                                                <div className="mt-1 text-xs text-(--app-muted-foreground)">
                                                    {t('cm.cases.audit_actor')} {audit.actor_name || audit.actor_type || t('cm.cases.system')}{audit.actor_id ? ` (#${audit.actor_id})` : ''}
                                                </div>
                                                {audit.meta && <pre className="mt-2 whitespace-pre-wrap rounded border border-(--app-border) px-2 py-1 text-xs">{JSON.stringify(audit.meta, null, 2)}</pre>}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                        </Card>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
