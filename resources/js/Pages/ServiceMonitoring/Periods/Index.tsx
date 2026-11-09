import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { errorCls, inputCls, labelCls, panelCls, primaryBtn, scrollCls, tableCls, tdCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import SmStatusBadge from '@/Components/serviceMonitoring/SmStatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { FiscalYearRow } from '@/types/serviceMonitoring';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';
import ActionErrors from '@/Components/serviceMonitoring/ActionErrors';

/** Fiscal years and their four periods; the coordinator creates a year (its schedule follows D5). */
export default function PeriodsIndex({ years, can }: { years: FiscalYearRow[]; can: { manage: boolean } }): JSX.Element {
    const { sm } = useSm();
    const form = useForm({ ec_year: String((years[0]?.ec_year ?? 2018) + 1) });

    function create(event: FormEvent) {
        event.preventDefault();
        form.post(route('service-monitoring.fiscal-years.store'), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('periods.title')} description={sm('periods.description')} />}>
            <Head title={sm('periods.title')} />
            <ActionErrors />
            <div className="space-y-4">
                {can.manage && (
                    <form onSubmit={create} className={`${panelCls} flex flex-wrap items-end gap-3 p-4`}>
                        <div>
                            <label htmlFor="sm-ec-year" className={labelCls}>{sm('periods.ecYear')}</label>
                            <input id="sm-ec-year" type="number" min={2000} max={2100} className={inputCls} value={form.data.ec_year} onChange={(e) => form.setData('ec_year', e.target.value)} />
                            {form.errors.ec_year && <p className={errorCls}>{form.errors.ec_year}</p>}
                        </div>
                        <button type="submit" className={primaryBtn} disabled={form.processing}>{sm('periods.createYear')}</button>
                    </form>
                )}
                {years.length === 0 && <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('periods.noYears')}</p>}
                {years.map((year) => (
                    <section key={year.id} className="space-y-2" aria-labelledby={`fy-${year.id}`}>
                        <h2 id={`fy-${year.id}`} className="text-sm font-semibold text-(--app-foreground)">
                            {sm('common.fiscalYear')} {year.ec_year} · <LocalizedDateDisplay value={year.starts_on} /> – <LocalizedDateDisplay value={year.ends_on} />
                        </h2>
                        <div className={scrollCls}>
                            <table className={tableCls}>
                                <thead>
                                    <tr>
                                        {['common.quarter', 'periods.starts', 'periods.ends', 'periods.opens', 'periods.submissionDue', 'periods.subcityReviewDue', 'periods.cityReviewDue', 'common.status'].map((key) => (
                                            <th key={key} scope="col" className={thCls}>{sm(key)}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {year.periods.map((p) => (
                                        <tr key={p.id}>
                                            <td className={tdCls}><Link className="font-medium underline" href={route('service-monitoring.periods.show', p.id)}>{sm('periods.quarterLabel', { n: p.quarter })}</Link></td>
                                            {[p.starts_on, p.ends_on, p.opens_on, p.submission_due_on, p.subcity_review_due_on, p.city_review_due_on].map((d, i) => (
                                                <td key={i} className={tdCls}><LocalizedDateDisplay value={d} /></td>
                                            ))}
                                            <td className={tdCls}><SmStatusBadge kind="period" status={p.status} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
