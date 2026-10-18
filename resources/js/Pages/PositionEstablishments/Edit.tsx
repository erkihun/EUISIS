import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEventHandler } from 'react';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
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

    const labelCls = 'block text-sm font-medium text-(--app-foreground)';
    const inputCls = cx(inputClassName, 'mt-1 block w-full');
    const errorCls = 'mt-1 text-xs text-red-500';

    return (
        <AuthenticatedLayout contentWidth="form"
            header={
                <PageHeader
                    backHref={route('position-establishments.show', establishment.id)}
                    title={t('positionEstablishments.edit')}
                    description={establishment.establishment_number}
                />
            }
        >
            <Head title={t('positionEstablishments.edit')} />

            <div>
                <form onSubmit={submit} className="space-y-5 rounded-panel border border-(--app-border) bg-(--app-surface) p-6">
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
                        <button type="submit" disabled={processing} className={buttonClassName({ variant: 'primary' })}>
                            {t('common.saveChanges')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
