import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
type TribunalCase = {
    id: string; case_number: string; status: string;
    decision_summary: string | null; hearing_date: string | null; decision_date: string | null;
    assigned_to_user: { name: string } | null;
    referral_state: 'queued' | 'sent' | 'failed' | null; referral_attempts: number; referral_error: string | null;
    referred_at: string | null; referred_by_user: { name: string } | null;
    court_case_number: string | null; court_review_status: string | null; court_review_reason: string | null;
    court_status: string | null; court_synced_at: string | null;
    grievance: {
        id: string; reference_number: string; subject: string; description: string;
        organization: { name_en: string; name_am: string | null } | null;
        category: { name_en: string; name_am: string | null } | null;
        employee: { full_name: string } | null;
        responses: Array<{ id: string; status: string; response_body_en: string }>;
    } | null;
};

type CourtMs = {
    enabled: boolean;
    admin_url: string | null;
    case_types: Array<{ id: number; name: string }>;
    defaults: {
        case_type_id: number | null; title: string; summary: string; respondent_name: string;
        complainant_phone: string | null; complainant_email: string | null;
    } | null;
    evidence: Array<{ id: string; title: string; size_bytes: number }>;
    last_poll_at: string | null;
    last_poll_error: string | null;
};

type Props = { case: TribunalCase; can: { update: boolean; refer: boolean }; courtMs: CourtMs };

const inputCls = cx(inputClassName, 'w-full');
const labelCls = 'block text-sm font-medium text-(--app-foreground) mb-1';
const termCls = 'text-xs font-medium text-(--app-muted-foreground)';
const errorText = 'text-red-600 dark:text-red-400';
const errorBox = 'rounded-control border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300';

function formatDateTime(value: string | null, locale: string): string {
    if (!value) return '-';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString(locale === 'am' ? 'am-ET' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

function CourtMsPanel({ tc, courtMs, canRefer }: { tc: TribunalCase; courtMs: CourtMs; canRefer: boolean }): JSX.Element | null {
    const { locale, t } = useLocale();
    const defaults = courtMs.defaults;
    const form = useForm({
        case_type_id: defaults?.case_type_id ? String(defaults.case_type_id) : '',
        title: defaults?.title ?? '',
        summary: defaults?.summary ?? '',
        relief_requested: '',
        respondent_name: defaults?.respondent_name ?? '',
        complainant_phone: defaults?.complainant_phone ?? '',
        complainant_email: defaults?.complainant_email ?? '',
        evidence_ids: [] as string[],
    });

    if (!courtMs.enabled && tc.referral_state === null) return null;

    const sent = tc.referral_state === 'sent';
    const mayRefer = canRefer && courtMs.enabled && (tc.referral_state === null || tc.referral_state === 'failed');
    const toggleEvidence = (id: string): void => {
        form.setData('evidence_ids', form.data.evidence_ids.includes(id)
            ? form.data.evidence_ids.filter(e => e !== id)
            : [...form.data.evidence_ids, id]);
    };

    return (
        <div className="rounded-card border border-(--app-border) bg-(--app-surface) p-6 space-y-4" id="court-ms">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold uppercase text-(--app-muted-foreground)">{t('grievances.courtMs.title')}</h3>
                {tc.referral_state && <StatusBadge status={tc.referral_state} label={t(`grievances.courtMs.state.${tc.referral_state}` as never)} />}
            </div>

            {tc.referral_state === 'queued' && (
                <p className="text-sm text-(--app-muted-foreground)">
                    {tc.referral_attempts > 0 ? t('grievances.courtMs.retrying').replace(':attempts', String(tc.referral_attempts)) : t('grievances.courtMs.waiting')}
                </p>
            )}
            {tc.referral_state === 'failed' && tc.referral_error && (
                <p role="alert" className={errorBox}>
                    {t('grievances.courtMs.failed')} {tc.referral_error}
                </p>
            )}

            {sent && (
                <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div>
                        <dt className={termCls}>{t('grievances.courtMs.caseNumber')}</dt>
                        <dd className="mt-0.5 font-mono text-sm">
                            {courtMs.admin_url
                                ? <a href={courtMs.admin_url} target="_blank" rel="noopener noreferrer" className="text-(--app-primary) underline">{tc.court_case_number}</a>
                                : tc.court_case_number ?? '-'}
                        </dd>
                    </div>
                    <div>
                        <dt className={termCls}>{t('grievances.courtMs.reviewStatus')}</dt>
                        <dd className="mt-0.5 text-sm">{tc.court_review_status ? t(`grievances.courtMs.review.${tc.court_review_status}` as never) : '-'}</dd>
                    </div>
                    <div>
                        <dt className={termCls}>{t('grievances.courtMs.caseStatus')}</dt>
                        <dd className="mt-0.5 text-sm">{tc.court_status ? t(`grievances.courtMs.status.${tc.court_status}` as never) : '-'}</dd>
                    </div>
                    {tc.court_review_reason && (
                        <div className="col-span-2 sm:col-span-3">
                            <dt className={termCls}>{t('grievances.courtMs.returnReason')}</dt>
                            <dd className="mt-0.5 whitespace-pre-wrap text-sm">{tc.court_review_reason}</dd>
                        </div>
                    )}
                    <div className="col-span-2 sm:col-span-3 text-xs text-(--app-muted-foreground)">
                        {t('grievances.courtMs.syncedAt').replace(':time', formatDateTime(tc.court_synced_at, locale))}
                        {courtMs.last_poll_error && <span className={cx('ms-2', errorText)}>{t('grievances.courtMs.pollError')}</span>}
                    </div>
                </dl>
            )}

            {tc.referred_at && (
                <p className="text-xs text-(--app-muted-foreground)">
                    {t('grievances.courtMs.referredBy').replace(':name', tc.referred_by_user?.name ?? '-').replace(':time', formatDateTime(tc.referred_at, locale))}
                </p>
            )}

            {mayRefer && (
                <form onSubmit={e => { e.preventDefault(); form.post(route('tribunal-cases.referral', tc.id), { preserveScroll: true }); }} className="space-y-4 border-t border-(--app-border) pt-4">
                    <p className="text-sm text-(--app-muted-foreground)">{t('grievances.courtMs.intro')}</p>
                    {courtMs.case_types.length === 0 && (
                        <p role="alert" className={cx('text-sm', errorText)}>{t('grievances.courtMs.noCaseTypes')}</p>
                    )}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="cms-case-type" className={labelCls}>{t('grievances.courtMs.caseType')}</label>
                            <select id="cms-case-type" className={inputCls} value={form.data.case_type_id} onChange={e => form.setData('case_type_id', e.target.value)} required>
                                <option value="">{t('common.select')}</option>
                                {courtMs.case_types.map(type => <option key={type.id} value={type.id}>{type.name}</option>)}
                            </select>
                            {form.errors.case_type_id && <p className={cx('mt-1 text-xs', errorText)}>{form.errors.case_type_id}</p>}
                        </div>
                        <div>
                            <label htmlFor="cms-respondent" className={labelCls}>{t('grievances.courtMs.respondent')}</label>
                            <input id="cms-respondent" className={inputCls} value={form.data.respondent_name} onChange={e => form.setData('respondent_name', e.target.value)} required maxLength={255} />
                        </div>
                    </div>
                    <div>
                        <label htmlFor="cms-title" className={labelCls}>{t('grievances.courtMs.caseTitle')}</label>
                        <input id="cms-title" className={inputCls} value={form.data.title} onChange={e => form.setData('title', e.target.value)} required maxLength={255} />
                        {form.errors.title && <p className={cx('mt-1 text-xs', errorText)}>{form.errors.title}</p>}
                    </div>
                    <div>
                        <label htmlFor="cms-summary" className={labelCls}>{t('grievances.courtMs.summary')}</label>
                        <textarea id="cms-summary" rows={6} className={inputCls} value={form.data.summary} onChange={e => form.setData('summary', e.target.value)} required maxLength={10000} />
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('grievances.courtMs.summaryHint')}</p>
                        {form.errors.summary && <p className={cx('mt-1 text-xs', errorText)}>{form.errors.summary}</p>}
                    </div>
                    <div>
                        <label htmlFor="cms-relief" className={labelCls}>{t('grievances.courtMs.relief')}</label>
                        <textarea id="cms-relief" rows={2} className={inputCls} value={form.data.relief_requested} onChange={e => form.setData('relief_requested', e.target.value)} maxLength={5000} />
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="cms-phone" className={labelCls}>{t('grievances.courtMs.complainantPhone')}</label>
                            <input id="cms-phone" className={inputCls} value={form.data.complainant_phone} onChange={e => form.setData('complainant_phone', e.target.value)} maxLength={40} />
                        </div>
                        <div>
                            <label htmlFor="cms-email" className={labelCls}>{t('grievances.courtMs.complainantEmail')}</label>
                            <input id="cms-email" type="email" className={inputCls} value={form.data.complainant_email} onChange={e => form.setData('complainant_email', e.target.value)} maxLength={255} />
                            {form.errors.complainant_email && <p className={cx('mt-1 text-xs', errorText)}>{form.errors.complainant_email}</p>}
                        </div>
                    </div>
                    <fieldset>
                        <legend className={labelCls}>{t('grievances.courtMs.documents')}</legend>
                        {courtMs.evidence.length === 0
                            ? <p className="text-sm text-(--app-muted-foreground)">{t('grievances.courtMs.noDocuments')}</p>
                            : (
                                <ul className="space-y-1">
                                    {courtMs.evidence.map(item => (
                                        <li key={item.id}>
                                            <label className="inline-flex items-center gap-2 text-sm">
                                                <input type="checkbox" checked={form.data.evidence_ids.includes(item.id)} onChange={() => toggleEvidence(item.id)} />
                                                <span>{item.title}</span>
                                                <span className="text-xs text-(--app-muted-foreground)">{Math.max(1, Math.round(item.size_bytes / 1024))} KB</span>
                                            </label>
                                        </li>
                                    ))}
                                </ul>
                            )}
                    </fieldset>
                    {(form.errors as Record<string, string | undefined>).referral && <p role="alert" className={cx('text-sm', errorText)}>{(form.errors as Record<string, string | undefined>).referral}</p>}
                    <div className="flex justify-end">
                        <button type="submit" disabled={form.processing || courtMs.case_types.length === 0} className={buttonClassName({ variant: 'primary' })}>
                            {tc.referral_state === 'failed' ? t('grievances.courtMs.sendAgain') : t('grievances.courtMs.send')}
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}

export default function TribunalShow({ case: tc, can, courtMs }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const { data, setData, patch, processing } = useForm({
        status: tc.status,
        decision_summary: tc.decision_summary ?? '',
        hearing_date: tc.hearing_date ?? '',
        decision_date: tc.decision_date ?? '',
    });

    return (
        <AuthenticatedLayout header={<PageHeader title={tc.case_number} />}>
            <Head title={tc.case_number} />

            <div className="space-y-6">
                <div className="rounded-card border border-(--app-border) bg-(--app-surface) p-6">
                    <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <div>
                            <dt className={termCls}>{t('grievances.caseNumber')}</dt>
                            <dd className="mt-0.5 font-mono text-sm">{tc.case_number}</dd>
                        </div>
                        <div>
                            <dt className={termCls}>{t('grievances.status')}</dt>
                            <dd className="mt-0.5"><StatusBadge status={tc.status} /></dd>
                        </div>
                        <div>
                            <dt className={termCls}>{t('grievances.organization')}</dt>
                            <dd className="mt-0.5 text-sm">{(am ? tc.grievance?.organization?.name_am : null) ?? tc.grievance?.organization?.name_en ?? '-'}</dd>
                        </div>
                    </dl>
                </div>

                {tc.grievance && (
                    <div className="rounded-card border border-(--app-border) bg-(--app-surface) p-6">
                        <h3 className="mb-4 text-sm font-semibold uppercase text-(--app-muted-foreground)">{t('grievances.grievance')}</h3>
                        <p className="font-mono text-xs text-(--app-muted-foreground) mb-1">{tc.grievance.reference_number}</p>
                        <p className="text-sm font-medium text-(--app-foreground) mb-2">{tc.grievance.subject}</p>
                        <p className="whitespace-pre-wrap text-sm text-(--app-muted-foreground)">{tc.grievance.description}</p>
                    </div>
                )}

                <CourtMsPanel tc={tc} courtMs={courtMs} canRefer={can.refer} />

                {can.update && (
                    <div className="rounded-card border border-(--app-border) bg-(--app-surface) p-6">
                        <h3 className="mb-4 text-sm font-semibold text-(--app-foreground)">{t('common.update')}</h3>
                        <form onSubmit={e => { e.preventDefault(); patch(route('tribunal-cases.update', tc.id)); }} className="space-y-4">
                            <div>
                                <label className={labelCls}>{t('grievances.status')}</label>
                                <select className={inputCls} value={data.status} onChange={e => setData('status', e.target.value)}>
                                    {['open', 'hearing', 'decided', 'closed'].map(s => (
                                        <option key={s} value={s}>{t(`grievances.tribunalStatus${s.charAt(0).toUpperCase() + s.slice(1)}` as never)}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className={labelCls}>{t('grievances.hearingDate')}</label>
                                    <input type="date" className={inputCls} value={data.hearing_date} onChange={e => setData('hearing_date', e.target.value)} />
                                </div>
                                <div>
                                    <label className={labelCls}>{t('grievances.decisionDate')}</label>
                                    <input type="date" className={inputCls} value={data.decision_date} onChange={e => setData('decision_date', e.target.value)} />
                                </div>
                            </div>
                            <div>
                                <label className={labelCls}>{t('grievances.decisionSummary')}</label>
                                <textarea rows={4} className={inputCls} value={data.decision_summary} onChange={e => setData('decision_summary', e.target.value)} />
                            </div>
                            <div className="flex justify-end">
                                <button type="submit" disabled={processing} className={buttonClassName({ variant: 'primary' })}>
                                    {t('common.save')}
                                </button>
                            </div>
                        </form>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
