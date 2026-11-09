import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedTimePicker from '@/Components/Calendar/LocalizedTimePicker';
import DetailSections from '@/Components/fieldWork/DetailSections';
import { dangerBtn, inputCls, labelCls, panelCls, primaryBtn, secondaryBtn } from '@/Components/fieldWork/helpers';
import type { FieldWorkDetail } from '@/Components/fieldWork/types';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type JSX } from 'react';

type Props = {
    fieldWork: FieldWorkDetail;
    isRequester: boolean;
    can: { edit: boolean; submit: boolean; cancel: boolean; checkIn: boolean; checkOut: boolean; complete: boolean };
    now: string;
};

/**
 * The employee's own field work. GPS is read once, only when the employee
 * presses check-in or check-out (never in the background), and only the raw
 * reading is sent; the server decides whether it is within the expected area.
 */
export default function MyFieldWorkShow({ fieldWork, isRequester, can, now }: Props): JSX.Element {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const pageErrors = (usePage().props.errors ?? {}) as Record<string, string>;
    const [locating, setLocating] = useState<'check-in' | 'check-out' | null>(null);
    const [gpsError, setGpsError] = useState<string | null>(null);
    const [completing, setCompleting] = useState(false);

    const lead = fieldWork.participants.find((p) => p.role === 'lead');
    const defaultReturn = (lead?.checked_out_at ?? now).slice(0, 16);
    const completion = useForm({
        actual_return_date: defaultReturn.slice(0, 10),
        actual_return_time: defaultReturn.slice(11, 16),
        completion_note: '',
        outcome: '',
        follow_up_required: false,
        follow_up_note: '',
    });

    function capture(action: 'check-in' | 'check-out') {
        setGpsError(null);
        if (!('geolocation' in navigator)) {
            setGpsError(t('fieldWork.gps.unsupported'));
            return;
        }
        setLocating(action);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                router.post(route(`employee.field-work.${action}`, fieldWork.id), {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                    captured_at: new Date(position.timestamp).toISOString(),
                }, { preserveScroll: true, onFinish: () => setLocating(null) });
            },
            (error) => {
                setLocating(null);
                setGpsError(t(error.code === error.PERMISSION_DENIED ? 'fieldWork.gps.denied' : error.code === error.TIMEOUT ? 'fieldWork.gps.timeout' : 'fieldWork.gps.unavailable'));
            },
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
        );
    }

    async function cancel() {
        const { confirmed, reason } = await confirm({
            title: t('fieldWork.actions.cancel'),
            description: t('fieldWork.show.cancelConfirm'),
            confirmLabel: t('fieldWork.actions.cancel'),
            cancelLabel: t('fieldWork.actions.close'),
            variant: 'warning',
            requireReason: false,
            reasonLabel: t('fieldWork.fields.cancelReason'),
        });
        if (confirmed) router.post(route('employee.field-work.cancel', fieldWork.id), { reason: reason ?? '' }, { preserveScroll: true });
    }

    const anyAction = Object.values(can).some(Boolean);
    const workflowError = pageErrors.status ?? pageErrors.conflicts ?? pageErrors.captured_at ?? pageErrors.location ?? pageErrors.employee;

    return (
        <PortalPage title={t('fieldWork.show.title')} backHref={route('employee.field-work.index')}>
            <div className="mx-auto max-w-4xl space-y-4">
                {(can.checkIn || can.checkOut) && (
                    <section className={`${panelCls} space-y-2 p-4`}>
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('fieldWork.gps.title')}</h2>
                        <p className="text-xs text-gray-500 dark:text-slate-400">{t('fieldWork.gps.privacy')}</p>
                        <div className="flex flex-wrap gap-2">
                            {can.checkIn && <button type="button" disabled={locating !== null} onClick={() => capture('check-in')} className={primaryBtn}>{locating === 'check-in' ? t('fieldWork.actions.locating') : t('fieldWork.actions.checkIn')}</button>}
                            {can.checkOut && <button type="button" disabled={locating !== null} onClick={() => capture('check-out')} className={primaryBtn}>{locating === 'check-out' ? t('fieldWork.actions.locating') : t('fieldWork.actions.checkOut')}</button>}
                        </div>
                        {gpsError && <p role="alert" className="text-sm text-red-700 dark:text-red-400">{gpsError}</p>}
                    </section>
                )}

                {workflowError && (
                    <p role="alert" className="whitespace-pre-line rounded-md bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-300">{workflowError}</p>
                )}

                {anyAction && (
                    <div className="flex flex-wrap gap-2">
                        {can.edit && <Link href={route('employee.field-work.edit', fieldWork.id)} className={secondaryBtn}>{t('fieldWork.actions.edit')}</Link>}
                        {can.submit && <button type="button" className={primaryBtn} onClick={() => router.post(route('employee.field-work.submit', fieldWork.id), {}, { preserveScroll: true })}>{t('fieldWork.actions.submit')}</button>}
                        {can.complete && !completing && <button type="button" className={primaryBtn} onClick={() => setCompleting(true)}>{t('fieldWork.actions.complete')}</button>}
                        {can.cancel && <button type="button" className={dangerBtn} onClick={cancel}>{t('fieldWork.actions.cancel')}</button>}
                    </div>
                )}

                {can.complete && completing && (
                    <form
                        className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2`}
                        onSubmit={(e) => { e.preventDefault(); completion.post(route('employee.field-work.complete', fieldWork.id), { preserveScroll: true, onSuccess: () => setCompleting(false) }); }}
                    >
                        <h2 className="text-sm font-semibold text-gray-900 sm:col-span-2 dark:text-slate-100">{t('fieldWork.actions.complete')}</h2>
                        <p className="text-xs text-gray-500 sm:col-span-2 dark:text-slate-400">{t('fieldWork.show.completeHint')}</p>
                        <div>
                            <span className={labelCls}>{t('fieldWork.fields.actualReturnDate')}</span>
                            <LocalizedDatePicker value={completion.data.actual_return_date} onChange={(v) => completion.setData('actual_return_date', v)} />
                            {completion.errors.actual_return_date && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{completion.errors.actual_return_date}</p>}
                        </div>
                        <div>
                            <span className={labelCls}>{t('fieldWork.fields.actualReturnTime')}</span>
                            <LocalizedTimePicker value={completion.data.actual_return_time} onChange={(v) => completion.setData('actual_return_time', v)} />
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="fw-note" className={labelCls}>{t('fieldWork.fields.completionNote')}<span className="text-red-600"> *</span></label>
                            <textarea id="fw-note" rows={3} maxLength={5000} className={inputCls} value={completion.data.completion_note} onChange={(e) => completion.setData('completion_note', e.target.value)} />
                            {completion.errors.completion_note && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{completion.errors.completion_note}</p>}
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="fw-outcome" className={labelCls}>{t('fieldWork.fields.outcome')}</label>
                            <textarea id="fw-outcome" rows={2} maxLength={5000} className={inputCls} value={completion.data.outcome} onChange={(e) => completion.setData('outcome', e.target.value)} />
                        </div>
                        <label className="flex items-center gap-2 text-sm text-gray-700 sm:col-span-2 dark:text-slate-300">
                            <input type="checkbox" checked={completion.data.follow_up_required} onChange={(e) => completion.setData('follow_up_required', e.target.checked)} />
                            {t('fieldWork.fields.followUpRequired')}
                        </label>
                        {completion.data.follow_up_required && (
                            <div className="sm:col-span-2">
                                <label htmlFor="fw-follow" className={labelCls}>{t('fieldWork.fields.followUpNote')}</label>
                                <textarea id="fw-follow" rows={2} maxLength={2000} className={inputCls} value={completion.data.follow_up_note} onChange={(e) => completion.setData('follow_up_note', e.target.value)} />
                                {completion.errors.follow_up_note && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{completion.errors.follow_up_note}</p>}
                            </div>
                        )}
                        <div className="flex gap-2 sm:col-span-2">
                            <button type="submit" disabled={completion.processing} className={primaryBtn}>{t('fieldWork.actions.complete')}</button>
                            <button type="button" className={secondaryBtn} onClick={() => setCompleting(false)}>{t('fieldWork.actions.close')}</button>
                        </div>
                    </form>
                )}

                {!anyAction && isRequester && fieldWork.status !== 'completed' && (
                    <p className="text-sm text-gray-500 dark:text-slate-400">{t('fieldWork.show.noActions')}</p>
                )}

                <DetailSections fieldWork={fieldWork} />
            </div>
        </PortalPage>
    );
}
