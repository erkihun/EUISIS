import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import DetailSections from '@/Components/fieldWork/DetailSections';
import ManagementNav from '@/Components/fieldWork/ManagementNav';
import { dangerBtn, inputCls, labelCls, panelCls, primaryBtn, secondaryBtn } from '@/Components/fieldWork/helpers';
import type { FieldWorkDetail, ManagementAbilities } from '@/Components/fieldWork/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import { useState, type JSX } from 'react';

type Props = {
    fieldWork: FieldWorkDetail;
    can: ManagementAbilities & { approve: boolean; return: boolean; reject: boolean };
};

type Mode = 'approve' | 'return' | 'reject';

/**
 * One request for a supervisor or HR oversight. Decision buttons appear only
 * for the live-resolved immediate supervisor; the server re-checks that on
 * every post. Return and reject require a reason.
 */
export default function FieldWorkShow({ fieldWork, can }: Props): JSX.Element {
    const { t } = useLocale();
    const [mode, setMode] = useState<Mode | null>(null);
    const form = useForm<{ reason: string }>({ reason: '' });
    const anyDecision = can.approve || can.return || can.reject;
    const errors = form.errors as Record<string, string>;

    function decide(action: Mode) {
        const name = { approve: 'field-work.requests.approve', return: 'field-work.requests.return', reject: 'field-work.requests.reject' }[action];
        form.post(route(name, fieldWork.id), { preserveScroll: true, onSuccess: () => { setMode(null); form.reset(); } });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('fieldWork.show.title')} backHref={route(can.requests ? 'field-work.requests.index' : 'field-work.dashboard')} />}>
            <Head title={`${t('fieldWork.show.title')} ${fieldWork.reference_number}`} />
            <div className="mx-auto max-w-4xl space-y-4">
                <ManagementNav can={can} current="field-work.requests.index" />
                <DetailSections fieldWork={fieldWork} />

                {anyDecision && (
                    <section className={`${panelCls} p-4`}>
                        <h2 className="mb-2 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('fieldWork.show.decide')}</h2>
                        {mode === null ? (
                            <div className="flex flex-wrap gap-2">
                                {can.approve && <button type="button" className={primaryBtn} onClick={() => setMode('approve')}>{t('fieldWork.actions.approve')}</button>}
                                {can.return && <button type="button" className={secondaryBtn} onClick={() => setMode('return')}>{t('fieldWork.actions.return')}</button>}
                                {can.reject && <button type="button" className={dangerBtn} onClick={() => setMode('reject')}>{t('fieldWork.actions.reject')}</button>}
                            </div>
                        ) : (
                            <form onSubmit={(e) => { e.preventDefault(); decide(mode); }} className="space-y-3">
                                <div>
                                    <label htmlFor="decision-reason" className={labelCls}>
                                        {mode === 'approve' ? t('fieldWork.fields.comment') : t('fieldWork.fields.reason')}
                                        {mode !== 'approve' && <span className="text-red-600"> *</span>}
                                    </label>
                                    <textarea id="decision-reason" rows={3} maxLength={2000} className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required={mode !== 'approve'} />
                                    {mode !== 'approve' && <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('fieldWork.show.reasonRequired')}</p>}
                                    {errors.reason && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{errors.reason}</p>}
                                </div>
                                {(errors.status || errors.conflicts) && (
                                    <p role="alert" className="whitespace-pre-line rounded-md bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-300">{errors.status ?? errors.conflicts}</p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <button type="submit" disabled={form.processing} className={mode === 'reject' ? dangerBtn : primaryBtn}>{t(`fieldWork.actions.${mode}`)}</button>
                                    <button type="button" className={secondaryBtn} onClick={() => { setMode(null); form.clearErrors(); }}>{t('fieldWork.actions.close')}</button>
                                </div>
                            </form>
                        )}
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
