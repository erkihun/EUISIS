import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

type Committee = { id: string; name_en: string; committee_type: string };
type Response = {
    id: string; status: string; response_body_en: string; response_body_am: string | null;
    compiled_at: string | null; approved_at: string | null; rejected_at: string | null;
    rejection_reason: string | null; revision_round: number;
    compiled_by_employee: { full_name: string } | null;
    approved_by_user: { name: string } | null;
    rejected_by_user: { name: string } | null;
};
type Grievance = {
    id: string; reference_number: string; subject: string; description: string; status: string;
    origin_level: string; submitted_at: string | null; requirement_fulfilled: boolean | null;
    requirement_notes: string | null; requirement_checked_at: string | null;
    organization: { name_en: string; name_am: string | null } | null;
    category: { name_en: string; name_am: string | null } | null;
    employee: { full_name: string } | null;
    submitted_by_user: { name: string } | null;
    current_assignment: { committee: { name_en: string } | null; due_at: string | null } | null;
    responses: Response[];
    escalations: Array<{ id: string; from_level: string; to_level: string; reason: string; escalated_at: string }>;
    decision_letter: { letter_reference: string; generated_at: string } | null;
    tribunal_case: { case_number: string; status: string } | null;
};
type Can = {
    assign: boolean; checkRequirement: boolean; draftResponse: boolean;
    compileResponse: boolean; approveResponse: boolean; generateLetter: boolean;
};

type Props = { grievance: Grievance; committees: Committee[]; can: Can };

const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';
const sectionCls = 'rounded-xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900';

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className={sectionCls}>
            <h3 className="mb-4 text-sm font-semibold uppercase text-gray-500 dark:text-slate-400">{title}</h3>
            {children}
        </div>
    );
}

function Field({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{label}</dt>
            <dd className="mt-0.5 text-sm text-gray-900 dark:text-slate-100">{value ?? '-'}</dd>
        </div>
    );
}

export default function GrievancesShow({ grievance, committees, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const assignForm = useForm({ committee_id: '', notes: '' });
    const reqForm = useForm({ fulfilled: '', notes: '' });
    const compileForm = useForm({ response_body_en: '', response_body_am: '' });
    const approveForm = useForm({});
    const rejectForm = useForm({ rejection_reason: '' });

    const latestResponse = grievance.responses[grievance.responses.length - 1] ?? null;

    function originLevelKey(level: string): string {
        return `grievances.originLevel${level.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('')}` as never;
    }

    function escalationReasonKey(reason: string): string {
        return `grievances.escalationReason${reason.charAt(0).toUpperCase() + reason.slice(1).replace(/_([a-z])/g, (_, c: string) => c.toUpperCase())}` as never;
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={grievance.reference_number}
                    actions={
                        grievance.decision_letter ? (
                            <a href={route('grievances.letter', grievance.id)} className="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                                {t('grievances.downloadLetter')}
                            </a>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={grievance.reference_number} />

            <div className="space-y-6">
                {/* Details */}
                <Section title={t('grievances.grievance')}>
                    <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <Field label={t('grievances.referenceNumber')} value={grievance.reference_number} />
                        <Field label={t('grievances.status')} value={<StatusBadge status={grievance.status} />} />
                        <Field label={t('grievances.category')} value={(am ? grievance.category?.name_am : null) ?? grievance.category?.name_en} />
                        <Field label={t('grievances.organization')} value={(am ? grievance.organization?.name_am : null) ?? grievance.organization?.name_en} />
                        <Field label={t('grievances.originLevel')} value={t(originLevelKey(grievance.origin_level) as never)} />
                        <Field label={t('grievances.submittedAt')} value={<LocalizedDateDisplay value={grievance.submitted_at} />} />
                        <Field label={t('grievances.submittedBy')} value={grievance.submitted_by_user?.name ?? grievance.employee?.full_name} />
                    </dl>
                    <div className="mt-4">
                        <p className={labelCls}>{t('grievances.subject')}</p>
                        <p className="text-sm text-gray-900 dark:text-slate-100">{grievance.subject}</p>
                    </div>
                    <div className="mt-4">
                        <p className={labelCls}>{t('grievances.description')}</p>
                        <p className="whitespace-pre-wrap text-sm text-gray-700 dark:text-slate-300">{grievance.description}</p>
                    </div>
                </Section>

                {/* Requirement check */}
                {can.checkRequirement && !grievance.requirement_checked_at && (
                    <Section title={t('grievances.checkRequirements')}>
                        <form onSubmit={e => { e.preventDefault(); reqForm.post(route('grievances.check-requirement', grievance.id)); }} className="space-y-4">
                            <div className="flex gap-4">
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="radio" name="fulfilled" value="1" onChange={() => reqForm.setData('fulfilled', '1')} /> {t('grievances.markFulfilled')}
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="radio" name="fulfilled" value="0" onChange={() => reqForm.setData('fulfilled', '0')} /> {t('grievances.markIncomplete')}
                                </label>
                            </div>
                            <textarea rows={3} className={inputCls} placeholder={t('grievances.requirementNotes')} value={reqForm.data.notes} onChange={e => reqForm.setData('notes', e.target.value)} />
                            <button type="submit" disabled={!reqForm.data.fulfilled || reqForm.processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                                {t('grievances.checkRequirements')}
                            </button>
                        </form>
                    </Section>
                )}

                {/* Assignment */}
                {can.assign && (
                    <Section title={t('grievances.assignCommittee')}>
                        <form onSubmit={e => { e.preventDefault(); assignForm.post(route('grievances.assign', grievance.id)); }} className="flex flex-wrap items-end gap-4">
                            <div className="flex-1 min-w-48">
                                <label className={labelCls}>{t('grievances.committee')}</label>
                                <select className={inputCls} value={assignForm.data.committee_id} onChange={e => assignForm.setData('committee_id', e.target.value)}>
                                    <option value="">— {t('common.select')} —</option>
                                    {committees.map(c => <option key={c.id} value={c.id}>{c.name_en}</option>)}
                                </select>
                            </div>
                            <div className="flex-1 min-w-48">
                                <label className={labelCls}>{t('common.notes')}</label>
                                <input type="text" className={inputCls} value={assignForm.data.notes} onChange={e => assignForm.setData('notes', e.target.value)} />
                            </div>
                            <button type="submit" disabled={!assignForm.data.committee_id || assignForm.processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                                {t('grievances.assignCommittee')}
                            </button>
                        </form>
                    </Section>
                )}

                {/* Compile response (chairperson) */}
                {can.compileResponse && (
                    <Section title={t('grievances.compileResponse')}>
                        {latestResponse?.rejection_reason && (
                            <div className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/30 dark:text-red-300">
                                <strong>{t('grievances.rejectionReason')}:</strong> {latestResponse.rejection_reason}
                            </div>
                        )}
                        <form onSubmit={e => { e.preventDefault(); compileForm.post(route('grievances.compile-response', grievance.id)); }} className="space-y-4">
                            <div>
                                <label className={labelCls}>{t('grievances.responseBodyEn')} *</label>
                                <textarea rows={6} className={inputCls} value={compileForm.data.response_body_en} onChange={e => compileForm.setData('response_body_en', e.target.value)} />
                            </div>
                            <div>
                                <label className={labelCls}>{t('grievances.responseBodyAm')}</label>
                                <textarea rows={6} className={inputCls} value={compileForm.data.response_body_am} onChange={e => compileForm.setData('response_body_am', e.target.value)} />
                            </div>
                            <button type="submit" disabled={!compileForm.data.response_body_en || compileForm.processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                                {t('grievances.compileResponse')}
                            </button>
                        </form>
                    </Section>
                )}

                {/* Manager approval */}
                {can.approveResponse && latestResponse?.status === 'compiled' && (
                    <Section title={t('grievances.response')}>
                        <div className="mb-4 whitespace-pre-wrap rounded-lg bg-gray-50 p-4 text-sm text-gray-800 dark:bg-slate-950 dark:text-slate-200">
                            {latestResponse.response_body_en}
                        </div>
                        <div className="flex gap-3">
                            <button
                                type="button"
                                disabled={approveForm.processing}
                                onClick={() => approveForm.post(route('grievances.approve-response', grievance.id))}
                                className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                            >
                                {t('grievances.approveResponse')}
                            </button>
                        </div>
                        <form onSubmit={e => { e.preventDefault(); rejectForm.post(route('grievances.reject-response', grievance.id)); }} className="mt-4 space-y-3">
                            <textarea rows={3} className={inputCls} placeholder={t('grievances.rejectionReason')} value={rejectForm.data.rejection_reason} onChange={e => rejectForm.setData('rejection_reason', e.target.value)} />
                            <button type="submit" disabled={!rejectForm.data.rejection_reason || rejectForm.processing} className="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400 disabled:opacity-50">
                                {t('grievances.rejectResponse')}
                            </button>
                        </form>
                    </Section>
                )}

                {/* Responses history */}
                {grievance.responses.length > 0 && (
                    <Section title={t('grievances.response')}>
                        <div className="space-y-4">
                            {[...grievance.responses].reverse().map(r => (
                                <div key={r.id} className="rounded-lg border border-gray-100 p-4 dark:border-slate-700">
                                    <div className="mb-2 flex items-center justify-between">
                                        <span className="text-xs text-gray-500">{t('grievances.revisionRound')} {r.revision_round}</span>
                                        <StatusBadge status={r.status} />
                                    </div>
                                    <p className="whitespace-pre-wrap text-sm text-gray-800 dark:text-slate-200">{r.response_body_en}</p>
                                    {r.rejection_reason && (
                                        <p className="mt-2 text-xs text-red-600 dark:text-red-400"><strong>{t('grievances.rejectionReason')}:</strong> {r.rejection_reason}</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </Section>
                )}

                {/* Escalations */}
                {grievance.escalations.length > 0 && (
                    <Section title={t('grievances.escalations')}>
                        <ul className="space-y-2 text-sm">
                            {grievance.escalations.map(e => (
                                <li key={e.id} className="flex items-center gap-2 text-gray-700 dark:text-slate-300">
                                    <span className="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                        {t(escalationReasonKey(e.reason) as never)}
                                    </span>
                                    <span>{e.from_level} → {e.to_level}</span>
                                    <span className="ml-auto text-xs text-gray-400"><LocalizedDateDisplay value={e.escalated_at} /></span>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                {/* Tribunal case */}
                {grievance.tribunal_case && (
                    <Section title={t('grievances.tribunalCase')}>
                        <dl className="grid grid-cols-2 gap-4">
                            <Field label={t('grievances.caseNumber')} value={grievance.tribunal_case.case_number} />
                            <Field label={t('grievances.status')} value={<StatusBadge status={grievance.tribunal_case.status} />} />
                        </dl>
                    </Section>
                )}

                {/* Decision letter */}
                {grievance.decision_letter && (
                    <Section title={t('grievances.decisionLetter')}>
                        <dl className="grid grid-cols-2 gap-4">
                            <Field label={t('grievances.letterReference')} value={grievance.decision_letter.letter_reference} />
                            <Field label={t('grievances.generatedAt')} value={<LocalizedDateDisplay value={grievance.decision_letter.generated_at} />} />
                        </dl>
                        <div className="mt-4">
                            <a href={route('grievances.letter', grievance.id)} className="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                                {t('grievances.downloadLetter')}
                            </a>
                        </div>
                    </Section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
