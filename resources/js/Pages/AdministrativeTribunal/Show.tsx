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
    grievance: {
        id: string; reference_number: string; subject: string; description: string;
        organization: { name_en: string; name_am: string | null } | null;
        category: { name_en: string; name_am: string | null } | null;
        employee: { full_name: string } | null;
        responses: Array<{ id: string; status: string; response_body_en: string }>;
    } | null;
};

type Props = { case: TribunalCase; can: { update: boolean } };

const inputCls = cx(inputClassName, 'w-full');
const labelCls = 'block text-sm font-medium text-(--app-foreground) mb-1';

export default function TribunalShow({ case: tc, can }: Props): JSX.Element {
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
                            <dt className="text-xs font-medium text-(--app-muted-foreground)">{t('grievances.caseNumber')}</dt>
                            <dd className="mt-0.5 font-mono text-sm">{tc.case_number}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-(--app-muted-foreground)">{t('grievances.status')}</dt>
                            <dd className="mt-0.5"><StatusBadge status={tc.status} /></dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-(--app-muted-foreground)">{t('grievances.organization')}</dt>
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
