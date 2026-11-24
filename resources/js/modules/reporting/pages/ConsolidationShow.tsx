import RichTextEditor from '@/Components/forms/RichTextEditor';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArtifactList } from '@/modules/reporting/components/Files';
import { ActionErrors, Notice } from '@/modules/reporting/components/Notices';
import { badge, errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { ConsolidationRow } from '@/modules/reporting/pages/Consolidations';
import type { ArtifactRow, OrganizationRef } from '@/modules/reporting/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type JSX } from 'react';

interface Props {
    consolidation: ConsolidationRow & { executive_summary: string | null; missing: ({ organization_id: string; code: string | null; status: string } & Pick<OrganizationRef, 'name_en' | 'name_am'>)[]; lock_version: number };
    sources: { obligation_id: string; submission_id: string; version_no: number; reference_no: string; organization: Omit<OrganizationRef, 'id'> }[];
    metrics: { source: string; definition: string; value: string | null; unit: string | null; captured_at: string }[];
    artifacts: ArtifactRow[];
}

const INDICATORS = ['expected', 'included', 'missing', 'exempted_or_cancelled', 'nil_reports', 'submitted_on_time', 'submitted_late'];

/**
 * A consolidated report: the editor's own executive summary, then what the
 * compilation found. Institutions without an approved report are listed by
 * name with their status; the sources name the exact versions used.
 */
export default function ConsolidationShow({ consolidation, sources, metrics, artifacts }: Props): JSX.Element {
    const { rt, name, label, moment } = useReporting();
    const form = useForm({ title: consolidation.title, executive_summary: consolidation.executive_summary ?? '', lock_version: consolidation.lock_version });
    const [compiling, setCompiling] = useState(false);
    // Saving and compiling both move the lock version; the form follows what the server last sent.
    useEffect(() => {
        form.setDefaults({ title: consolidation.title, executive_summary: consolidation.executive_summary ?? '', lock_version: consolidation.lock_version });
        form.setData('lock_version', consolidation.lock_version);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [consolidation.lock_version]);
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('reporting.consolidations.update', consolidation.id), { preserveScroll: true });
    };
    const compile = () => {
        setCompiling(true);
        router.post(route('reporting.consolidations.compile', consolidation.id), {}, { preserveScroll: true, onFinish: () => setCompiling(false) });
    };
    const indicators = consolidation.indicators;

    return (
        <AuthenticatedLayout header={<PageHeader title={consolidation.title} description={`${name(consolidation.parent)} · ${name(consolidation.report_type)} · ${label(consolidation.period)}`} backHref={route('reporting.consolidations.index')} />}>
            <Head title={consolidation.title} />
            <ActionErrors except={['title', 'executive_summary']} />
            <div className="space-y-5 pb-4">
                <form onSubmit={save} className={cx(panelCls, 'space-y-3 p-4')} aria-labelledby="cs-summary">
                    <h2 id="cs-summary" className={sectionTitleCls}>{rt('consolidation.summaryTitle')}</h2>
                    <div>
                        <label htmlFor="cs-title" className={labelCls}>{rt('consolidation.titleLabel')}</label>
                        <input id="cs-title" type="text" className={cx(inputCls, 'max-w-2xl')} maxLength={250} required value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        {form.errors.title && <p className={errorCls} role="alert">{form.errors.title}</p>}
                    </div>
                    <RichTextEditor label={rt('consolidation.executiveSummary')} helperText={rt('consolidation.executiveSummaryHelp')} preset="document" value={form.data.executive_summary} onChange={(html) => form.setData('executive_summary', html)} error={form.errors.executive_summary} />
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className={secondaryBtn} disabled={form.processing}>{rt('common.save')}</button>
                        <button type="button" className={primaryBtn} disabled={compiling || form.isDirty} title={form.isDirty ? rt('consolidation.saveFirst') : undefined} onClick={compile}>
                            {rt(consolidation.status === 'compiled' ? 'consolidation.compileAgain' : 'consolidation.compile')}
                        </button>
                    </div>
                    <p className={cx(mutedCls, 'text-xs')}>{rt('consolidation.compileHelp')}</p>
                </form>

                {consolidation.status !== 'compiled' || !indicators ? <Notice>{rt('consolidation.notCompiled')}</Notice> : (
                    <>
                        <section className={cx(panelCls, 'p-4')} aria-labelledby="cs-result">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <h2 id="cs-result" className={sectionTitleCls}>{rt('consolidation.resultTitle')}</h2>
                                <p className={cx(mutedCls, 'text-xs')}>{rt('consolidation.compiledAt', { time: moment(consolidation.compiled_at) })}</p>
                            </div>
                            <dl className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                                {INDICATORS.map((key) => (
                                    <div key={key} className="flex flex-col-reverse">
                                        <dt className="text-xs text-(--app-muted-foreground)">{rt(`consolidation.indicator.${key}`)}</dt>
                                        <dd className="text-2xl font-semibold tabular-nums">{indicators[key] ?? 0}</dd>
                                    </div>
                                ))}
                            </dl>
                            <p className={cx(mutedCls, 'mt-3 text-xs')}>{rt('consolidation.countedOnce')}</p>
                            {metrics.length > 0 && (
                                <ul className="mt-3 space-y-1 border-t border-(--app-border) pt-3 text-sm">
                                    {metrics.map((metric) => (
                                        <li key={metric.definition}>
                                            <span className="font-medium tabular-nums">{metric.value === null ? '—' : Number(metric.value)} {metric.unit}</span> · {metric.definition}
                                            <span className={cx(mutedCls, 'block text-xs')}>{rt('consolidation.metricSource', { source: metric.source, time: moment(metric.captured_at) })}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="mt-3 border-t border-(--app-border) pt-3">
                                <h3 className={labelCls}>{rt('files.generatedTitle')}</h3>
                                <ArtifactList artifacts={artifacts} reloadOnly={['artifacts']} />
                            </div>
                        </section>

                        <div className="grid gap-5 lg:grid-cols-2">
                            <section className={cx(panelCls, 'p-4')} aria-labelledby="cs-missing">
                                <h2 id="cs-missing" className={sectionTitleCls}>{rt('consolidation.missingTitle')} <span className={badge(consolidation.missing.length > 0 ? 'warning' : 'success')}>{consolidation.missing.length}</span></h2>
                                <p className={cx(mutedCls, 'text-xs')}>{rt('consolidation.missingHelp')}</p>
                                {consolidation.missing.length === 0 ? <p className={cx(mutedCls, 'mt-2')}>{rt('consolidation.noneMissing')}</p> : (
                                    <ul className="mt-2 divide-y divide-(--app-border)">
                                        {consolidation.missing.map((row) => (
                                            <li key={row.organization_id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                                <span>{name(row)} <span className={cx(mutedCls, 'text-xs')}>({row.code})</span></span>
                                                <span className={badge(row.status === 'not_submitted' ? 'danger' : 'warning')}>{rt(`consolidation.missingStatus.${row.status}`)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                            <section className={cx(panelCls, 'p-4')} aria-labelledby="cs-sources">
                                <h2 id="cs-sources" className={sectionTitleCls}>{rt('consolidation.sourcesTitle')} <span className={badge('neutral')}>{sources.length}</span></h2>
                                <p className={cx(mutedCls, 'text-xs')}>{rt('consolidation.sourcesHelp')}</p>
                                <ul className="mt-2 divide-y divide-(--app-border)">
                                    {sources.map((source) => (
                                        <li key={source.obligation_id} className="py-2 text-sm">
                                            <Link href={route('reporting.obligations.show', source.obligation_id)} className="font-medium text-primary hover:underline">{name(source.organization)}</Link>
                                            <span className={cx(mutedCls, 'block text-xs')}><span className="font-mono">{source.reference_no}</span> · {rt('common.version', { n: source.version_no })}</span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        </div>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
