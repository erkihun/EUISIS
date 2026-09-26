import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Empty, Pill, Section, Table, employeeName, formatScore, inputCls, nameOf, pageCls, primaryBtn, smallBtn, tdCls, thCls, type Bilingual } from '@/Components/performance/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Item = { id: string; employee: { name: string | null; name_en: string | null; number: string | null }; manager_score: string | null; proposed_score: string | null; calibrated_score: string | null; reason: string | null; decided_at: string | null };

type Props = {
    session: { id: string; title: string; status: string; session_date: string | null; committee: Bilingual };
    items: Item[];
    candidates: { id: string; employee: string | null; employee_en: string | null; final_score: string | null; rating_en: string | null; rating_am: string | null }[];
    can: { manage: boolean; decide: boolean; finalize: boolean };
};

/** One calibration session: results under review, before/after scores with reasons, and finalization. */
export default function CalibrationShow({ session, items, candidates, can }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [selected, setSelected] = useState<string[]>([]);
    const finalized = session.status === 'FINALIZED';

    async function finalize() {
        const { confirmed } = await confirm({ title: t('performance.actions.finalize'), description: t('performance.calibration.finalizeNote'), confirmLabel: t('performance.actions.finalize'), cancelLabel: t('performance.actions.cancel'), variant: 'danger' });
        if (confirmed) router.post(route('performance.calibration.finalize', session.id), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={session.title} description={session.committee ? nameOf(session.committee, locale) : undefined} backHref={route('performance.calibration.index')}
            actions={can.finalize && !finalized && items.length > 0 && <button type="button" className={primaryBtn} onClick={finalize}>{t('performance.actions.finalize')}</button>} />}>
            <Head title={session.title} />
            <div className={pageCls}>
                <div className="flex items-center gap-3 text-sm"><Pill group="session" value={session.status} />{session.session_date && <LocalizedDateDisplay value={session.session_date} />}</div>

                <Section title={t('performance.calibration.title')}>
                    {items.length === 0 ? <Empty>{t('performance.calibration.empty')}</Empty> : (
                        <Table head={<>
                            <th className={thCls}>{t('performance.fields.employee')}</th>
                            <th className={thCls}>{t('performance.calibration.managerScore')}</th>
                            <th className={thCls}>{t('performance.calibration.calibratedScore')}</th>
                            <th className={thCls}>{t('performance.fields.reason')}</th>
                            {can.decide && !finalized && <th className={thCls}><span className="sr-only">{t('performance.actions.decide')}</span></th>}
                        </>}>
                            {items.map((item) => <ItemRow key={item.id} item={item} canDecide={can.decide && !finalized} locale={locale} />)}
                        </Table>
                    )}
                </Section>

                {can.manage && !finalized && (
                    <Section title={t('performance.calibration.candidates')} actions={candidates.length > 0 && (
                        <>
                            <button type="button" className={smallBtn} onClick={() => setSelected(selected.length === candidates.length ? [] : candidates.map((c) => c.id))}>
                                {selected.length === candidates.length ? t('performance.calibration.clearSelection') : t('performance.calibration.selectAll')}
                            </button>
                            {selected.length > 0 && (
                                <button type="button" className={smallBtn} onClick={() => router.post(route('performance.calibration.results.store', session.id), { result_ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) })}>
                                    {t('performance.actions.addResults')} ({selected.length})
                                </button>
                            )}
                        </>
                    )}>
                        {candidates.length === 0 ? <Empty>—</Empty> : (
                            <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                                {candidates.map((c) => (
                                    <li key={c.id} className="flex items-center gap-3 py-2">
                                        <input type="checkbox" aria-label={c.employee ?? ''} checked={selected.includes(c.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, c.id] : selected.filter((id) => id !== c.id))} />
                                        <span className="flex-1">{employeeName({ name: c.employee, name_en: c.employee_en }, locale)}</span>
                                        <span className="tabular-nums">{formatScore(c.final_score)}</span>
                                        <span className="w-32 text-xs text-gray-500">{(locale === 'am' && c.rating_am) || c.rating_en}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function ItemRow({ item, canDecide, locale }: { item: Item; canDecide: boolean; locale: string }) {
    const { t } = useLocale();
    const form = useForm({ calibrated_score: item.calibrated_score ?? item.manager_score ?? '', reason: item.reason ?? '' });
    return (
        <tr>
            <td className={tdCls}>{employeeName(item.employee, locale)}<p className="text-xs text-gray-500">{item.employee.number}</p></td>
            <td className={`${tdCls} tabular-nums`}>{formatScore(item.manager_score)}</td>
            <td className={`${tdCls} tabular-nums`}>
                {canDecide ? <input aria-label={t('performance.calibration.calibratedScore')} className={`${inputCls} w-24`} inputMode="decimal" value={form.data.calibrated_score} onChange={(e) => form.setData('calibrated_score', e.target.value)} /> : formatScore(item.calibrated_score)}
                {form.errors.calibrated_score && <p className="text-xs text-red-700">{form.errors.calibrated_score}</p>}
            </td>
            <td className={tdCls}>
                {canDecide ? <input aria-label={t('performance.fields.reason')} className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /> : item.reason}
                {form.errors.reason && <p className="text-xs text-red-700">{form.errors.reason}</p>}
                {item.decided_at && <p className="text-xs text-gray-500"><LocalizedDateDisplay value={item.decided_at} withTime /></p>}
            </td>
            {canDecide && <td className={tdCls}><button type="button" className={smallBtn} disabled={form.processing} onClick={() => form.post(route('performance.calibration.items.decide', item.id), { preserveScroll: true })}>{t('performance.actions.decide')}</button></td>}
        </tr>
    );
}
