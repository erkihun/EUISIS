import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { ListPagination, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors } from '@/modules/reporting/components/Notices';
import { OrganizationPicker } from '@/modules/reporting/components/Pickers';
import { badge, errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { Bilingual, FilterOptions, Labelled, OrganizationRef, Paginated } from '@/modules/reporting/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

export interface ConsolidationRow {
    id: string;
    title: string;
    status: 'draft' | 'compiled';
    indicators: Record<string, number> | null;
    parent: { id: string } & Bilingual;
    report_type: Bilingual;
    period: Labelled;
    created_by_name: string;
    compiled_at: string | null;
    created_at: string | null;
}

interface Props {
    rows: Paginated<ConsolidationRow>;
    options: FilterOptions;
}

/** Parent-level reports compiled from the approved reports of the institutions under one parent. */
export default function Consolidations({ rows, options }: Props): JSX.Element {
    const { rt, name, label, moment } = useReporting();
    const [parent, setParent] = useState<OrganizationRef | null>(null);
    const form = useForm({ cycle_id: '', parent_organization_id: '', title: '' });
    const create = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, parent_organization_id: parent?.id ?? '' }));
        form.post(route('reporting.consolidations.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('consolidation.title')} description={rt('consolidation.description')} />}>
            <Head title={rt('consolidation.title')} />
            <ActionErrors except={['cycle_id', 'parent_organization_id', 'title']} />
            <div className="space-y-5 pb-4">
                <form onSubmit={create} className={cx(panelCls, 'grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4')} aria-labelledby="cn-new">
                    <h2 id="cn-new" className={cx(sectionTitleCls, 'sm:col-span-2 lg:col-span-4')}>{rt('consolidation.new')}</h2>
                    <div>
                        <label htmlFor="cn-cycle" className={labelCls}>{rt('filters.period')}</label>
                        <select id="cn-cycle" className={inputCls} required value={form.data.cycle_id} onChange={(e) => form.setData('cycle_id', e.target.value)}>
                            <option value="">{rt('common.choose')}</option>
                            {options.cycles.map((cycle) => <option key={cycle.id} value={cycle.id}>{name(options.report_types.find((t) => t.id === cycle.report_type_id))} · {label(cycle)}</option>)}
                        </select>
                        {form.errors.cycle_id && <p className={errorCls} role="alert">{form.errors.cycle_id}</p>}
                    </div>
                    <OrganizationPicker label={rt('consolidation.parent')} value={parent} onChange={setParent} help={rt('consolidation.parentHelp')} error={form.errors.parent_organization_id} />
                    <div>
                        <label htmlFor="cn-title" className={labelCls}>{rt('consolidation.titleLabel')}</label>
                        <input id="cn-title" type="text" className={inputCls} maxLength={250} required value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        {form.errors.title && <p className={errorCls} role="alert">{form.errors.title}</p>}
                    </div>
                    <div className="flex items-end">
                        <button type="submit" className={primaryBtn} disabled={form.processing || parent === null}>{rt('consolidation.start')}</button>
                    </div>
                </form>

                {rows.data.length === 0 ? <EmptyState title={rt('consolidation.emptyTitle')} description={rt('consolidation.emptyDescription')} /> : (
                    <ul className="space-y-3">
                        {rows.data.map((row) => (
                            <li key={row.id} className={cx(panelCls, 'flex flex-wrap items-center justify-between gap-3 p-4')}>
                                <div className="min-w-0">
                                    <Link href={route('reporting.consolidations.show', row.id)} className="text-base font-semibold text-primary hover:underline">{row.title}</Link>
                                    <p className="text-sm">{name(row.parent)} · {name(row.report_type)} · {label(row.period)}</p>
                                    <p className={cx(mutedCls, 'text-xs')}>{row.created_by_name}{row.compiled_at ? ` · ${rt('consolidation.compiledAt', { time: moment(row.compiled_at) })}` : ''}</p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {row.indicators && <span className={badge(row.indicators.missing > 0 ? 'warning' : 'success')}>{rt('consolidation.includedOf', { included: row.indicators.included, expected: row.indicators.expected })}</span>}
                                    <span className={badge(row.status === 'compiled' ? 'success' : 'neutral')}>{rt(`consolidation.status.${row.status}`)}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                <ListPagination meta={rows} />
            </div>
        </AuthenticatedLayout>
    );
}
