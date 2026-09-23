import { FormEvent, ReactNode, useRef, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

type BatchSummary = {
    id: string;
    file_name: string;
    total_rows: number;
    valid_rows: number;
    failed_rows: number;
    status: string;
    importable: boolean;
};

type PreviewRow = {
    row_number: number;
    name: string;
    employee_number: string | null;
    organization: string | null;
    organization_unit: string | null;
    position: string | null;
    status: string;
    errors: string[];
};

type Organization = { id: string; code: string; name_en: string; name_am: string | null };

type Props = {
    batch: BatchSummary | null;
    preview: PreviewRow[];
    columns: string[];
    skippedRows: number;
    maxRows: number;
    allowedOrganizations: Organization[];
    can: { upload: boolean; confirm: boolean };
};

export default function ImportCsv({ batch, preview, columns, skippedRows, maxRows, allowedOrganizations, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';
    const fileInput = useRef<HTMLInputElement>(null);
    const [confirming, setConfirming] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [templateOrganization, setTemplateOrganization] = useState(allowedOrganizations.length === 1 ? allowedOrganizations[0].id : '');
    const templateError = usePage().props.errors.organization_id;
    const form = useForm<{ file: File | null }>({ file: null });

    const organizationLabel = (organization: Organization): string =>
        `${organization.code} — ${am ? (organization.name_am || organization.name_en) : organization.name_en}`;

    const chooseFile = (file: File | null) => {
        form.setData('file', file);
        form.clearErrors('file');
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('employees.import.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset('file');
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    const confirmImport = () => {
        if (!batch) return;
        setConfirming(true);
        router.post(route('employees.import.confirm', batch.id), {}, { onFinish: () => setConfirming(false) });
    };

    return (
        <AuthenticatedLayout>
            <Head title={t('employees.import.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('employees.import.title')}
                    description={t('employees.import.description')}
                    backHref={route('employees.index')}
                />

                <ol className="grid overflow-hidden rounded-panel border border-gray-200 bg-white sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
                    <Step number="1" label={t('employees.import.stepTemplate')} active />
                    <Step number="2" label={t('employees.import.stepUpload')} active={batch === null} complete={batch !== null} />
                    <Step number="3" label={t('employees.import.stepReview')} active={batch !== null} />
                </ol>

                <div className={`grid gap-5 ${can.upload ? 'lg:grid-cols-2' : ''}`}>
                    <section className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <SectionHeading
                            icon={<DownloadIcon />}
                            number="01"
                            title={t('employees.import.prepareTitle')}
                            description={t('employees.import.prepareDescription')}
                        />
                        <form method="get" action={route('employees.import.template')} className="space-y-4 p-5">
                            {/* The template's name columns follow the language
                                the page is being viewed in. */}
                            <input type="hidden" name="locale" value={locale} />
                            <div>
                                <label htmlFor="template-organization" className="mb-1.5 block text-sm font-medium text-gray-800 dark:text-slate-200">
                                    {t('employees.import.templateOrganization')}
                                </label>
                                <select
                                    id="template-organization"
                                    name="organization_id"
                                    required
                                    value={templateOrganization}
                                    onChange={(event) => setTemplateOrganization(event.target.value)}
                                    aria-describedby="template-help"
                                    aria-invalid={Boolean(templateError)}
                                    className="w-full rounded-control border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                                    <option value="">{t('employees.import.selectTemplateOrganization')}</option>
                                    {allowedOrganizations.map((organization) => (
                                        <option key={organization.id} value={organization.id}>{organizationLabel(organization)}</option>
                                    ))}
                                </select>
                                {templateError && <p role="alert" className="mt-1.5 text-sm text-red-600 dark:text-red-400">{templateError}</p>}
                            </div>

                            <button type="submit" disabled={!templateOrganization} className="inline-flex w-full items-center justify-center gap-2 rounded-control bg-[color:var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] disabled:cursor-not-allowed disabled:opacity-50">
                                <DownloadIcon small />
                                {t('employees.import.downloadTemplate')}
                            </button>

                            <p id="template-help" className="text-xs leading-5 text-gray-500 dark:text-slate-400">
                                {t('employees.import.templateOrganizationHelp').replace(':max', String(maxRows))}
                            </p>
                            {allowedOrganizations.length === 0 && <Notice tone="warning">{t('employees.import.noTemplateOrganizations')}</Notice>}
                        </form>
                    </section>

                    {can.upload && (
                        <section className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                            <SectionHeading
                                icon={<UploadIcon />}
                                number="02"
                                title={t('employees.import.uploadTitle')}
                                description={t('employees.import.uploadDescription')}
                            />
                            <form onSubmit={submit} className="p-5">
                                <input
                                    ref={fileInput}
                                    id="file"
                                    type="file"
                                    accept=".csv,text/csv"
                                    onChange={(event) => chooseFile(event.target.files?.[0] ?? null)}
                                    className="sr-only"
                                />
                                <label
                                    htmlFor="file"
                                    onDragEnter={() => setDragging(true)}
                                    onDragLeave={() => setDragging(false)}
                                    onDragOver={(event) => event.preventDefault()}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        setDragging(false);
                                        chooseFile(event.dataTransfer.files?.[0] ?? null);
                                    }}
                                    className={`flex min-h-44 cursor-pointer flex-col items-center justify-center rounded-panel border-2 border-dashed px-5 py-6 text-center transition ${dragging ? 'border-[color:var(--color-primary)] bg-blue-50 dark:bg-blue-950/20' : form.data.file ? 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-700 dark:bg-emerald-950/20' : 'border-gray-300 bg-gray-50/70 hover:border-[color:var(--color-primary)] hover:bg-blue-50/50 dark:border-slate-700 dark:bg-slate-950/40 dark:hover:bg-blue-950/10'}`}
                                >
                                    <span className={`flex h-11 w-11 items-center justify-center rounded-full ${form.data.file ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-white text-gray-500 shadow-sm dark:bg-slate-800 dark:text-slate-300'}`}>
                                        {form.data.file ? <CheckIcon /> : <UploadIcon />}
                                    </span>
                                    <span className="mt-3 max-w-full truncate text-sm font-semibold text-gray-900 dark:text-slate-100">
                                        {form.data.file?.name ?? t('employees.import.dropTitle')}
                                    </span>
                                    <span className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                        {form.data.file ? formatFileSize(form.data.file.size) : t('employees.import.dropHint')}
                                    </span>
                                </label>

                                {form.errors.file && <p role="alert" className="mt-2 text-sm text-red-600 dark:text-red-400">{form.errors.file}</p>}

                                <button type="submit" disabled={form.processing || !form.data.file} className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-control bg-[color:var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] disabled:cursor-not-allowed disabled:opacity-50">
                                    {form.processing ? <Spinner /> : <CheckDocumentIcon />}
                                    {form.processing ? t('employees.import.validating') : t('employees.import.validate')}
                                </button>

                                <p className="mt-3 rounded-control bg-blue-50 px-3 py-2 text-xs leading-5 text-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                                    {t('employees.import.numberGenerationHelp')}
                                </p>

                                <details className="mt-4 rounded-control bg-gray-50 px-3 py-2 text-xs dark:bg-slate-950/60">
                                    <summary className="cursor-pointer font-medium text-gray-700 dark:text-slate-300">{t('employees.import.requiredColumns')}</summary>
                                    <p className="mt-2 break-words font-mono leading-5 text-gray-500 dark:text-slate-400">{columns.join(', ')}</p>
                                </details>
                            </form>
                        </section>
                    )}
                </div>

                {allowedOrganizations.length > 0 && (
                    <details className="rounded-panel border border-gray-200 bg-white px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
                        <summary className="cursor-pointer text-sm font-medium text-gray-800 dark:text-slate-200">
                            {t('employees.import.allowedOrganizations')} <span className="ml-1 text-xs text-gray-400">({allowedOrganizations.length})</span>
                        </summary>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {allowedOrganizations.map((organization) => (
                                <span key={organization.id} className="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs text-gray-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                    {organizationLabel(organization)}
                                </span>
                            ))}
                        </div>
                    </details>
                )}

                {skippedRows > 0 && (
                    <Notice tone="warning" title={t('employees.import.truncatedTitle')}>
                        {t('employees.import.truncatedBody').replace(':skipped', String(skippedRows)).replace(':max', String(maxRows))}
                    </Notice>
                )}

                {batch && (
                    <section className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-gray-100 p-5 dark:border-slate-800">
                            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                                <div className="min-w-0">
                                    <p className="text-xs font-semibold uppercase tracking-wider text-[color:var(--color-primary)]">{t('employees.import.stepReview')}</p>
                                    <h2 className="mt-1 text-lg font-semibold text-gray-900 dark:text-slate-100">{t('employees.import.preview')}</h2>
                                    <p className="mt-1 truncate text-sm text-gray-500 dark:text-slate-400">{batch.file_name}</p>
                                </div>
                                <StatusPill importable={batch.importable} label={batch.importable ? t('employees.import.readyToImport') : t('employees.import.fixErrors')} />
                            </div>

                            <div className="mt-5 grid grid-cols-3 gap-3">
                                <Metric label={t('employees.import.totalRows')} value={batch.total_rows} />
                                <Metric label={t('employees.import.validRows')} value={batch.valid_rows} tone="success" />
                                <Metric label={t('employees.import.invalidRows')} value={batch.failed_rows} tone="danger" />
                            </div>
                        </div>

                        <div className="hidden overflow-x-auto md:block">
                            <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-800">
                                <thead className="bg-gray-50 dark:bg-slate-950">
                                    <tr>
                                        <Th>#</Th>
                                        <Th>{t('employees.employee')}</Th>
                                        <Th>{t('employees.employeeNumber')}</Th>
                                        <Th>{t('employees.organization')}</Th>
                                        <Th>{t('employees.position')}</Th>
                                        <Th>{t('common.status')}</Th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {preview.map((row) => <PreviewTableRow key={row.row_number} row={row} validLabel={t('employees.import.rowValid')} generatedLabel={t('employees.import.systemGenerated')} />)}
                                </tbody>
                            </table>
                        </div>

                        <ul className="divide-y divide-gray-100 md:hidden dark:divide-slate-800">
                            {preview.map((row) => (
                                <PreviewCard
                                    key={row.row_number}
                                    row={row}
                                    validLabel={t('employees.import.rowValid')}
                                    generatedLabel={t('employees.import.systemGenerated')}
                                    organizationLabel={t('employees.organization')}
                                    positionLabel={t('employees.position')}
                                />
                            ))}
                        </ul>

                        <div className="flex flex-col gap-3 border-t border-gray-100 bg-gray-50/70 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-950/40">
                            <div className="text-xs">
                                {batch.importable ? (
                                    <span className="text-emerald-700 dark:text-emerald-300">{t('employees.import.reviewDescription')}</span>
                                ) : (
                                    <span className="text-red-600 dark:text-red-400">{t('employees.import.notImportable')}</span>
                                )}
                            </div>
                            <div className="flex flex-col-reverse gap-2 sm:flex-row">
                                <button type="button" onClick={() => router.post(route('employees.import.cancel'))} className="rounded-control border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                                    {t('employees.import.cancel')}
                                </button>
                                {can.confirm && (
                                    <button type="button" onClick={confirmImport} disabled={!batch.importable || confirming} className="inline-flex items-center justify-center gap-2 rounded-control bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">
                                        {confirming ? <Spinner /> : <CheckIcon />}
                                        {t('employees.import.confirm')}
                                    </button>
                                )}
                            </div>
                        </div>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Step({ number, label, active = false, complete = false }: { number: string; label: string; active?: boolean; complete?: boolean }): JSX.Element {
    return (
        <li className={`flex items-center gap-3 border-b px-4 py-3 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0 ${active ? 'bg-blue-50/70 dark:bg-blue-950/20' : ''} border-gray-200 dark:border-slate-800`}>
            <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ${complete ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300' : active ? 'bg-[color:var(--color-primary)] text-white' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>
                {complete ? '✓' : number}
            </span>
            <span className={`text-sm font-medium ${active || complete ? 'text-gray-900 dark:text-slate-100' : 'text-gray-500 dark:text-slate-400'}`}>{label}</span>
        </li>
    );
}

function SectionHeading({ icon, number, title, description }: { icon: ReactNode; number: string; title: string; description: string }): JSX.Element {
    return (
        <div className="flex gap-3 border-b border-gray-100 px-5 py-4 dark:border-slate-800">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-blue-50 text-[color:var(--color-primary)] dark:bg-blue-950/40">{icon}</span>
            <div>
                <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400">{number}</p>
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h2>
                <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{description}</p>
            </div>
        </div>
    );
}

function Metric({ label, value, tone = 'neutral' }: { label: string; value: number; tone?: 'neutral' | 'success' | 'danger' }): JSX.Element {
    const colors = { neutral: 'text-gray-900 dark:text-white', success: 'text-emerald-600 dark:text-emerald-400', danger: 'text-red-600 dark:text-red-400' };
    return (
        <div className="rounded-control border border-gray-200 bg-gray-50 px-3 py-3 dark:border-slate-700 dark:bg-slate-800/70">
            <p className={`text-xl font-bold tabular-nums ${colors[tone]}`}>{value}</p>
            <p className="mt-0.5 truncate text-xs text-gray-500 dark:text-slate-400">{label}</p>
        </div>
    );
}

function StatusPill({ importable, label }: { importable: boolean; label: string }): JSX.Element {
    return <span className={`inline-flex shrink-0 items-center gap-1.5 self-start rounded-full px-3 py-1 text-xs font-semibold ${importable ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300'}`}><span className={`h-1.5 w-1.5 rounded-full ${importable ? 'bg-emerald-500' : 'bg-red-500'}`} />{label}</span>;
}

function PreviewTableRow({ row, validLabel, generatedLabel }: { row: PreviewRow; validLabel: string; generatedLabel: string }): JSX.Element {
    return (
        <tr className={row.status === 'invalid' ? 'bg-red-50/60 dark:bg-red-950/20' : 'hover:bg-gray-50/70 dark:hover:bg-slate-800/30'}>
            <td className="px-4 py-3 tabular-nums text-gray-400">{row.row_number}</td>
            <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{row.name || '—'}</td>
            <td className="px-4 py-3 font-mono text-xs text-gray-600 dark:text-slate-400">{row.employee_number ?? generatedLabel}</td>
            <td className="px-4 py-3 text-gray-700 dark:text-slate-300"><div>{row.organization ?? '—'}</div>{row.organization_unit && <div className="mt-0.5 text-xs text-gray-400">{row.organization_unit}</div>}</td>
            <td className="px-4 py-3 text-gray-700 dark:text-slate-300">{row.position ?? '—'}</td>
            <td className="px-4 py-3"><RowStatus row={row} validLabel={validLabel} /></td>
        </tr>
    );
}

function PreviewCard({ row, validLabel, generatedLabel, organizationLabel, positionLabel }: { row: PreviewRow; validLabel: string; generatedLabel: string; organizationLabel: string; positionLabel: string }): JSX.Element {
    return (
        <li className={`p-4 ${row.status === 'invalid' ? 'bg-red-50/60 dark:bg-red-950/20' : ''}`}>
            <div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="truncate font-medium text-gray-900 dark:text-slate-100">{row.name || '—'}</p><p className="mt-0.5 font-mono text-xs text-gray-500">#{row.row_number} · {row.employee_number ?? generatedLabel}</p></div><RowStatus row={row} validLabel={validLabel} compact /></div>
            <dl className="mt-3 grid gap-2 text-xs"><div><dt className="text-gray-400">{organizationLabel}</dt><dd className="text-gray-700 dark:text-slate-300">{row.organization ?? '—'}{row.organization_unit ? ` · ${row.organization_unit}` : ''}</dd></div><div><dt className="text-gray-400">{positionLabel}</dt><dd className="text-gray-700 dark:text-slate-300">{row.position ?? '—'}</dd></div></dl>
            {row.status === 'invalid' && <ul className="mt-3 list-disc space-y-1 pl-4 text-xs text-red-600 dark:text-red-400">{row.errors.map((error, index) => <li key={index}>{error}</li>)}</ul>}
        </li>
    );
}

function RowStatus({ row, validLabel, compact = false }: { row: PreviewRow; validLabel: string; compact?: boolean }): JSX.Element {
    if (row.status !== 'invalid') return <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300"><CheckIcon small />{validLabel}</span>;
    if (compact) return <span className="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-950/50 dark:text-red-300">{row.errors.length}</span>;
    return <ul className="space-y-1 text-xs text-red-600 dark:text-red-400">{row.errors.map((error, index) => <li key={index}>{error}</li>)}</ul>;
}

function Notice({ children, title, tone }: { children: ReactNode; title?: string; tone: 'warning' }): JSX.Element {
    return <div role="alert" className="rounded-panel border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">{title && <p className="font-semibold">{title}</p>}<div className={title ? 'mt-1 text-xs leading-5' : ''}>{children}</div></div>;
}

function Th({ children }: { children: ReactNode }): JSX.Element {
    return <th className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{children}</th>;
}

function formatFileSize(bytes: number): string {
    return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB · CSV` : `${(bytes / 1024 / 1024).toFixed(1)} MB · CSV`;
}

function DownloadIcon({ small = false }: { small?: boolean }): JSX.Element { return <svg className={small ? 'h-4 w-4' : 'h-5 w-5'} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14" /></svg>; }
function UploadIcon(): JSX.Element { return <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M12 16V4m0 0L8 8m4-4 4 4M5 20h14" /></svg>; }
function CheckDocumentIcon(): JSX.Element { return <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M7 3h7l4 4v14H7zM14 3v5h5M9 14l2 2 4-4" /></svg>; }
function CheckIcon({ small = false }: { small?: boolean }): JSX.Element { return <svg className={small ? 'h-3 w-3' : 'h-5 w-5'} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5"><path strokeLinecap="round" strokeLinejoin="round" d="m5 12 4 4L19 6" /></svg>; }
function Spinner(): JSX.Element { return <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle className="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="3" /><path className="opacity-75" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" /></svg>; }
