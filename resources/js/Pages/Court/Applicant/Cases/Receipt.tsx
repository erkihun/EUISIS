import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import { Card, badgeClassName, buttonClassName } from '@/Components/ui';
import { useBranding } from '@/hooks/useBranding';
import { CASE_STATUS_TONES, useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantCaseReceiptProps } from '@/court/types/applicantCases';
import { BackIcon, PrintIcon } from '../../Cases/Show/icons';

function Fact({ label, children }: { label: ReactNode; children: ReactNode }) {
    return (
        <div className="rounded-lg border border-(--app-border) bg-(--app-surface-muted) px-3 py-2 print:bg-transparent">
            <div className="text-[11px] font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{label}</div>
            <div className="text-sm font-semibold text-(--app-foreground)">{children}</div>
        </div>
    );
}

function Field({ label, children, wide = false }: { label: ReactNode; children: ReactNode; wide?: boolean }) {
    return (
        <div className={wide ? 'md:col-span-2' : undefined}>
            <dt className="text-sm text-(--app-muted-foreground)">{label}</dt>
            <dd className="text-sm font-medium text-(--app-foreground)">{children}</dd>
        </div>
    );
}

function Section({ title, children }: { title: ReactNode; children: ReactNode }) {
    return (
        <section className="break-inside-avoid">
            <h2 className="mb-2 text-sm font-semibold text-(--app-foreground)">{title}</h2>
            {children}
        </section>
    );
}

const rowClassName = 'flex items-center justify-between gap-3 rounded-md border border-(--app-border) p-2 text-sm';

/**
 * The applicant's printable filing receipt: what was filed, with whom, the
 * evidence, the extra files and the hearings. It opens in its own tab from the
 * case page, so it has no portal sidebar; the buttons do not print.
 */
export default function ApplicantCaseReceipt({ receipt, witnesses, documents, files, hearings, generatedAt }: ApplicantCaseReceiptProps) {
    const { t, text, fill, statusLabel } = useApplicantCaseText();
    const { brandName } = useBranding();
    const notProvided = text('receipt_not_provided');

    return (
        <>
            <Head title={`${t('cm.cases.filing_receipt')} · ${receipt.case_number}`} />
            <div className="min-h-screen bg-(--app-background) text-(--app-foreground) print:bg-white">
                <main className="mx-auto max-w-3xl px-4 py-8 print:max-w-none print:p-0">
                    <div className="mb-4 flex items-center justify-between gap-3 print:hidden">
                        <Link href={route('court.applicant.cases.show', receipt.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                            <BackIcon className="mr-1 h-3.5 w-3.5" />{t('cm.cases.back_to_case')}
                        </Link>
                        <button type="button" onClick={() => window.print()} className={buttonClassName({ size: 'sm' })}>
                            <PrintIcon className="mr-1 h-3.5 w-3.5" />{text('receipt_print')}
                        </button>
                    </div>

                    <Card className="space-y-6 print:border-0 print:p-0 print:shadow-none">
                        <header className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h1 className="text-xl font-semibold tracking-tight">{t('cm.cases.filing_receipt')}</h1>
                                <p className="mt-1 text-sm text-(--app-muted-foreground)">{brandName}</p>
                                <p className="mt-2 max-w-md text-xs text-(--app-muted-foreground)">{t('cm.cases.receipt.summary_notice')}</p>
                            </div>
                            <dl className="text-right">
                                <dt className="text-xs text-(--app-muted-foreground)">{t('cm.cases.case_number')}</dt>
                                <dd className="font-mono text-lg font-semibold tracking-tight">{receipt.case_number}</dd>
                                {receipt.code && <>
                                    <dt className="mt-1 text-xs text-(--app-muted-foreground)">{text('receipt_access_code')}</dt>
                                    <dd className="font-mono text-sm font-semibold">{receipt.code}</dd>
                                </>}
                                <dd className="mt-1"><span className={badgeClassName(CASE_STATUS_TONES[receipt.status] ?? 'neutral')}>{statusLabel(receipt.status)}</span></dd>
                            </dl>
                        </header>

                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <Fact label={t('cm.cases.filing_date')}><LocalizedDateDisplay value={receipt.filed_at} /></Fact>
                            <Fact label={text('receipt_documents')}>{documents.length}</Fact>
                            <Fact label={t('cm.cases.witnesses')}>{witnesses.length}</Fact>
                            <Fact label={t('cm.cases.summary.hearings')}>{hearings.length}</Fact>
                        </div>

                        <dl className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field label={t('cm.cases.summary.applicant')}>
                                {receipt.applicant.name}
                                {(receipt.applicant.email || receipt.applicant.phone) && (
                                    <span className="block font-normal text-(--app-muted-foreground)">
                                        {[receipt.applicant.email, receipt.applicant.phone].filter(Boolean).join(' | ')}
                                    </span>
                                )}
                            </Field>
                            <Field label={t('cm.cases.summary.title')}>{receipt.title}</Field>
                            <Field label={text('receipt_court_type')}>
                                {[receipt.court_name ?? notProvided, receipt.case_type].filter(Boolean).join(' | ')}
                            </Field>
                            {receipt.respondent && (
                                <Field label={t('cm.cases.respondent_defendant')}>
                                    {receipt.respondent.name ?? notProvided}
                                    {receipt.respondent.address && <span className="block font-normal text-(--app-muted-foreground)">{receipt.respondent.address}</span>}
                                </Field>
                            )}
                        </dl>

                        <div className="space-y-4 border-t border-(--app-border) pt-6">
                            <Section title={t('cm.cases.labels.description')}>
                                {receipt.description_html
                                    ? <RichTextViewer html={receipt.description_html} className="text-justify text-sm" />
                                    : <p className="text-sm text-(--app-muted-foreground)">{t('cm.cases.no_description')}</p>}
                            </Section>
                            {receipt.relief_html && (
                                <Section title={t('cm.cases.details.relief_requested')}>
                                    <RichTextViewer html={receipt.relief_html} className="text-justify text-sm" />
                                </Section>
                            )}
                        </div>

                        <div className="grid gap-6 md:grid-cols-2">
                            <Section title={text('receipt_human_evidence')}>
                                {witnesses.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{text('receipt_none_provided')}</p> : (
                                    <ul className="space-y-2">
                                        {witnesses.map((witness) => (
                                            <li key={witness.id} className="rounded-md border border-(--app-border) p-2 text-sm">
                                                <div className="font-medium">{witness.name}</div>
                                                <div className="text-xs text-(--app-muted-foreground)">
                                                    {[witness.phone ?? text('receipt_no_phone'), witness.email].filter(Boolean).join(' | ')}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                            <Section title={t('cm.cases.evidence_documents')}>
                                {documents.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{text('receipt_none_uploaded')}</p> : (
                                    <ul className="space-y-2">
                                        {documents.map((document) => (
                                            <li key={document.id} className={rowClassName}>
                                                <span className="min-w-0 break-words font-medium">{document.title}</span>
                                                {document.url && (
                                                    <a href={document.url} target="_blank" rel="noopener" className="shrink-0 text-xs text-primary hover:underline print:hidden">
                                                        {t('cm.cases.documents.view')}
                                                    </a>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                        </div>

                        {files.length > 0 && (
                            <Section title={t('cm.cases.navigation.uploaded_files')}>
                                <ul className="space-y-2">
                                    {files.map((file) => (
                                        <li key={file.id} className={rowClassName}>
                                            <div className="min-w-0">
                                                <div className="break-words font-medium">{file.label}</div>
                                                <LocalizedDateDisplay value={file.uploaded_at} withTime className="text-xs text-(--app-muted-foreground)" />
                                            </div>
                                            <span className="shrink-0 text-xs text-(--app-muted-foreground)">{file.extension}</span>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}

                        {hearings.length > 0 && (
                            <Section title={t('cm.cases.summary.hearings')}>
                                <ul className="space-y-2">
                                    {hearings.map((hearing) => (
                                        <li key={hearing.id} className={rowClassName}>
                                            <div className="min-w-0">
                                                <div className="font-medium">
                                                    <LocalizedDateDisplay value={hearing.at} withTime />
                                                    {hearing.type && <span> | {hearing.type}</span>}
                                                </div>
                                                <div className="text-xs text-(--app-muted-foreground)">{hearing.location ?? notProvided}</div>
                                            </div>
                                            <a href={hearing.ics_url} className={`${buttonClassName({ variant: 'outline', size: 'sm' })} shrink-0 print:hidden`}>
                                                {t('cm.cases.add_to_calendar')}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}

                        <footer className="space-y-1 border-t border-(--app-border) pt-4 text-xs text-(--app-muted-foreground)">
                            <p>{text('receipt_generated_on')} <LocalizedDateDisplay value={generatedAt} withTime /></p>
                            <p>{fill(t('cm.cases.receipt.generated_electronically_by'), { app: brandName })}</p>
                        </footer>
                    </Card>
                </main>
            </div>
        </>
    );
}
