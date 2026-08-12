import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { FormEventHandler, useState } from 'react';

type Application = {
    id: string;
    application_number: string;
    status: string;
    applied_at: string;
    screening_score: string | null;
    screening_notes: string | null;
    screened_at: string | null;
    selected_at: string | null;
    rejection_reason: string | null;
    employee: { name_en: string; code?: string } | null;
    announcement: { id: string; title_en: string } | null;
    positionEntry: {
        vacancy_slots: number;
        organization: { name_en: string } | null;
        organizationUnit: { name_en: string } | null;
        position: { title_en: string } | null;
    } | null;
};

type Can = {
    screen: boolean;
    shortlist: boolean;
    select: boolean;
    reject: boolean;
    withdraw: boolean;
    initiateTransfer: boolean;
};

type Props = {
    application: Application;
    can: Can;
};

const PIPELINE = ['submitted', 'screened', 'shortlisted', 'selected', 'transferred'] as const;
type PipelineStep = typeof PIPELINE[number];

export default function VacancyApplicationsShow({ application, can }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [showTransferForm, setShowTransferForm] = useState(false);
    const [showScreenForm, setShowScreenForm] = useState(false);
    const [showRejectForm, setShowRejectForm] = useState(false);

    const screenForm = useForm({ screening_score: '', screening_notes: '' });
    const rejectForm = useForm({ rejection_reason: '' });
    const transferForm = useForm({ effective_date: '' });

    const submitScreen: FormEventHandler = (e) => {
        e.preventDefault();
        screenForm.post(route('vacancy-applications.screen', application.id));
    };

    const submitReject: FormEventHandler = (e) => {
        e.preventDefault();
        rejectForm.post(route('vacancy-applications.reject', application.id));
    };

    const submitTransfer: FormEventHandler = async (e) => {
        e.preventDefault();
        const { confirmed } = await confirm({
            title: t('vacancies.initiateTransfer'),
            description: t('common.cannotUndo'),
            confirmLabel: t('confirmations.confirm'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) {
            transferForm.post(route('vacancy-applications.initiate-transfer', application.id));
        }
    };

    const inputCls = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100';
    const labelCls = 'block text-xs font-medium text-gray-500 dark:text-slate-400';

    const isFinal = ['withdrawn', 'rejected', 'transferred'].includes(application.status);
    const pipelineIndex = PIPELINE.indexOf(application.status as PipelineStep);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('vacancy-applications.index')}
                    title={application.application_number}
                    description={application.employee?.name_en}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.withdraw && (
                                <button type="button" onClick={() => router.post(route('vacancy-applications.withdraw', application.id))} className="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400">
                                    {t('vacancies.withdrawApplication')}
                                </button>
                            )}
                            {can.screen && (
                                <button type="button" onClick={() => setShowScreenForm(v => !v)} className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">
                                    {t('vacancies.screenApplication')}
                                </button>
                            )}
                            {can.shortlist && (
                                <button type="button" onClick={() => router.post(route('vacancy-applications.shortlist', application.id))} className="rounded-lg border border-blue-300 px-3 py-1.5 text-sm font-medium text-blue-600 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-400">
                                    {t('vacancies.shortlistApplication')}
                                </button>
                            )}
                            {can.select && (
                                <button type="button" onClick={() => router.post(route('vacancy-applications.select', application.id))} className="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
                                    {t('vacancies.selectApplication')}
                                </button>
                            )}
                            {can.reject && (
                                <button type="button" onClick={() => setShowRejectForm(v => !v)} className="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400">
                                    {t('vacancies.rejectApplication')}
                                </button>
                            )}
                            {can.initiateTransfer && application.status === 'selected' && (
                                <button type="button" onClick={() => setShowTransferForm(v => !v)} className="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
                                    {t('vacancies.initiateTransfer')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={application.application_number} />

            <div className="mx-auto max-w-2xl space-y-6">
                {/* Pipeline stepper */}
                {!isFinal ? (
                    <div className="flex items-center overflow-x-auto rounded-xl border border-gray-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-900">
                        {PIPELINE.map((step, index) => {
                            const done = pipelineIndex > index;
                            const active = pipelineIndex === index;
                            return (
                                <div key={step} className="flex flex-1 items-center">
                                    <div className="flex shrink-0 flex-col items-center gap-1.5">
                                        <div className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold ${done ? 'bg-emerald-500 text-white' : active ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-500 dark:bg-slate-700 dark:text-slate-400'}`}>
                                            {done ? '✓' : index + 1}
                                        </div>
                                        <span className={`whitespace-nowrap text-xs ${active ? 'font-semibold text-blue-600 dark:text-blue-400' : done ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400 dark:text-slate-500'}`}>
                                            {t(`vacancies.appStatus${step.charAt(0).toUpperCase()}${step.slice(1)}` as never)}
                                        </span>
                                    </div>
                                    {index < PIPELINE.length - 1 && (
                                        <div className={`mx-2 h-0.5 flex-1 ${done ? 'bg-emerald-500' : 'bg-gray-200 dark:bg-slate-700'}`} />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    <div className="flex items-center gap-3">
                        <StatusBadge status={application.status} />
                    </div>
                )}

                {/* Details */}
                <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <dl className="grid grid-cols-2 gap-4 text-sm">
                        {[
                            { label: t('employees.employee'), value: application.employee?.name_en ?? '—' },
                            { label: t('vacancies.announcement'), value: application.announcement?.title_en ?? '—' },
                            { label: t('positionEstablishments.organization'), value: application.positionEntry?.organization?.name_en ?? '—' },
                            { label: t('positionEstablishments.position'), value: application.positionEntry?.position?.title_en ?? '—' },
                            { label: t('vacancies.screeningScore'), value: application.screening_score ?? '—' },
                            ...(application.rejection_reason ? [{ label: t('vacancies.rejectionReason'), value: application.rejection_reason }] : []),
                        ].map(({ label, value }) => (
                            <div key={label}>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{label}</dt>
                                <dd className="mt-1 text-gray-800 dark:text-slate-200">{value}</dd>
                            </div>
                        ))}
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('vacancies.appliedAt')}</dt>
                            <dd className="mt-1 text-gray-800 dark:text-slate-200"><LocalizedDateDisplay value={application.applied_at} /></dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('vacancies.selectedAt')}</dt>
                            <dd className="mt-1 text-gray-800 dark:text-slate-200"><LocalizedDateDisplay value={application.selected_at} /></dd>
                        </div>
                        {application.screening_notes && (
                            <div className="col-span-2">
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('vacancies.screeningNotes')}</dt>
                                <dd className="mt-1 whitespace-pre-line text-gray-800 dark:text-slate-200">{application.screening_notes}</dd>
                            </div>
                        )}
                    </dl>
                </section>

                {showScreenForm && (
                    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 font-semibold text-gray-900 dark:text-slate-100">{t('vacancies.screenApplication')}</h3>
                        <form onSubmit={submitScreen} className="space-y-4">
                            <div>
                                <label className={labelCls}>{t('vacancies.screeningScore')} (0–100)</label>
                                <input type="number" min={0} max={100} step={0.01} className={inputCls} value={screenForm.data.screening_score} onChange={e => screenForm.setData('screening_score', e.target.value)} />
                                {screenForm.errors.screening_score && <p className="mt-1 text-xs text-red-500">{screenForm.errors.screening_score}</p>}
                            </div>
                            <div>
                                <label className={labelCls}>{t('vacancies.screeningNotes')}</label>
                                <textarea rows={3} className={inputCls} value={screenForm.data.screening_notes} onChange={e => screenForm.setData('screening_notes', e.target.value)} />
                            </div>
                            <div className="flex gap-2">
                                <button type="submit" disabled={screenForm.processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                                    {t('common.save')}
                                </button>
                                <button type="button" onClick={() => setShowScreenForm(false)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </section>
                )}

                {showRejectForm && (
                    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 font-semibold text-gray-900 dark:text-slate-100">{t('vacancies.rejectApplication')}</h3>
                        <form onSubmit={submitReject} className="space-y-4">
                            <div>
                                <label className={labelCls}>{t('vacancies.rejectionReason')}</label>
                                <textarea rows={3} className={inputCls} value={rejectForm.data.rejection_reason} onChange={e => rejectForm.setData('rejection_reason', e.target.value)} />
                                {rejectForm.errors.rejection_reason && <p className="mt-1 text-xs text-red-500">{rejectForm.errors.rejection_reason}</p>}
                            </div>
                            <div className="flex gap-2">
                                <button type="submit" disabled={rejectForm.processing} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60">
                                    {t('vacancies.rejectApplication')}
                                </button>
                                <button type="button" onClick={() => setShowRejectForm(false)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </section>
                )}

                {showTransferForm && (
                    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 font-semibold text-gray-900 dark:text-slate-100">{t('vacancies.initiateTransfer')}</h3>
                        <form onSubmit={submitTransfer} className="space-y-4">
                            <div>
                                <label className={labelCls}>{t('vacancies.effectiveDate')}</label>
                                <input type="date" className={inputCls} value={transferForm.data.effective_date} onChange={e => transferForm.setData('effective_date', e.target.value)} required />
                                {transferForm.errors.effective_date && <p className="mt-1 text-xs text-red-500">{transferForm.errors.effective_date}</p>}
                            </div>
                            <div className="flex gap-2">
                                <button type="submit" disabled={transferForm.processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                                    {t('vacancies.initiateTransfer')}
                                </button>
                                <button type="button" onClick={() => setShowTransferForm(false)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
