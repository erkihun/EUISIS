import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEventHandler } from 'react';

type Establishment = {
    id: string;
    establishment_number: string;
    approved_slots: number;
    effective_from: string;
    effective_to: string | null;
    approval_reference: string | null;
    notes: string | null;
};

export default function PositionEstablishmentsEdit({ establishment }: { establishment: Establishment }) {
    const { t } = useLocale();
    const { data, setData, patch, processing, errors } = useForm({
        approved_slots: establishment.approved_slots,
        effective_from: establishment.effective_from,
        effective_to: establishment.effective_to ?? '',
        approval_reference: establishment.approval_reference ?? '',
        notes: establishment.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('position-establishments.update', establishment.id));
    };

    const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300';
    const inputCls = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[color:var(--color-primary)] focus:outline-none dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100';
    const errorCls = 'mt-1 text-xs text-red-500';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('position-establishments.show', establishment.id)}
                    title={t('positionEstablishments.edit')}
                    description={establishment.establishment_number}
                />
            }
        >
            <Head title={t('positionEstablishments.edit')} />

            <div className="mx-auto max-w-2xl">
                <form onSubmit={submit} className="space-y-5 rounded-panel border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div>
                        <label className={labelCls}>{t('positionEstablishments.approvedSlots')}</label>
                        <input type="number" min={1} className={inputCls} value={data.approved_slots} onChange={e => setData('approved_slots', +e.target.value)} required />
                        {errors.approved_slots && <p className={errorCls}>{errors.approved_slots}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className={labelCls}>{t('positionEstablishments.effectiveFrom')}</label>
                            <input type="date" className={inputCls} value={data.effective_from} onChange={e => setData('effective_from', e.target.value)} required />
                            {errors.effective_from && <p className={errorCls}>{errors.effective_from}</p>}
                        </div>
                        <div>
                            <label className={labelCls}>{t('positionEstablishments.effectiveTo')}</label>
                            <input type="date" className={inputCls} value={data.effective_to} onChange={e => setData('effective_to', e.target.value)} />
                        </div>
                    </div>

                    <div>
                        <label className={labelCls}>{t('positionEstablishments.approvalReference')}</label>
                        <input type="text" className={inputCls} value={data.approval_reference} onChange={e => setData('approval_reference', e.target.value)} />
                    </div>

                    <div>
                        <label className={labelCls}>{t('positionEstablishments.notes')}</label>
                        <textarea rows={3} className={inputCls} value={data.notes} onChange={e => setData('notes', e.target.value)} />
                    </div>

                    <div className="flex justify-end">
                        <button type="submit" disabled={processing} className="rounded-lg bg-[color:var(--color-primary)] px-5 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60">
                            {t('common.saveChanges')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
