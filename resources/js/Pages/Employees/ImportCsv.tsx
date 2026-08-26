import { FormEvent, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

/**
 * Employee CSV upload.
 *
 * One page carries all three steps — upload, preview, confirm — because they
 * are a single decision for the importer: they should see what a file will do
 * without losing the file, and confirm from the same screen.
 *
 * Nothing here decides authorisation. `can.upload` / `can.confirm` only hide
 * controls; the server re-checks both permissions and organization scope on
 * every request.
 */

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

type Props = {
    batch: BatchSummary | null;
    preview: PreviewRow[];
    columns: string[];
    allowedOrganizations: { id: string; code: string; name_en: string; name_am: string | null }[];
    can: { upload: boolean; confirm: boolean };
};

export default function ImportCsv({ batch, preview, columns, allowedOrganizations, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';
    const [confirming, setConfirming] = useState(false);

    const { setData, post, processing, errors, reset } = useForm<{ file: File | null }>({ file: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('employees.import.store'), {
            forceFormData: true,
            onSuccess: () => reset('file'),
        });
    };

    function confirmImport() {
        if (batch === null) {
            return;
        }

        setConfirming(true);
        router.post(route('employees.import.confirm', batch.id), {}, {
            onFinish: () => setConfirming(false),
        });
    }

    function cancelImport() {
        router.post(route('employees.import.cancel'));
    }

    return (
        <AuthenticatedLayout>
            <Head title={t('employees.import.title')} />

            <div className="space-y-5">
                <PageHeader
                    title={t('employees.import.title')}
                    backHref={route('employees.index')}
                    actions={
                        <a
                            href={route('employees.import.template')}
                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            {t('employees.import.downloadTemplate')}
                        </a>
                    }
                />

                {/* Upload */}
                {can.upload && (
                    <form
                        onSubmit={submit}
                        className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
                    >
                        <label htmlFor="file" className="block text-sm font-medium text-gray-900 dark:text-slate-100">
                            {t('employees.import.chooseFile')}
                        </label>
                        <input
                            id="file"
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                            className="mt-2 block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-blue-700 dark:text-slate-300"
                        />
                        {errors.file && <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{errors.file}</p>}

                        <div className="mt-3 flex flex-wrap gap-2">
                            <button
                                type="submit"
                                disabled={processing}
                                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                            >
                                {t('employees.import.validate')}
                            </button>
                        </div>

                        {/* Expected column order, so the file can be built by hand. */}
                        <p className="mt-4 break-words text-xs text-gray-500 dark:text-slate-400">
                            {columns.join(', ')}
                        </p>

                        {allowedOrganizations.length > 0 && (
                            <div className="mt-3 text-xs text-gray-500 dark:text-slate-400">
                                <span className="font-medium">{t('employees.import.allowedOrganizations')}:</span>{' '}
                                {allowedOrganizations
                                    .map((org) => `${org.code} (${am ? (org.name_am ?? org.name_en) : org.name_en})`)
                                    .join(' · ')}
                            </div>
                        )}
                    </form>
                )}

                {/* Preview */}
                {batch !== null && (
                    <div className="rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-slate-800">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                                    {t('employees.import.preview')}
                                </h2>
                                <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{batch.file_name}</p>
                            </div>

                            <div className="flex flex-wrap items-center gap-3 text-xs">
                                <Stat label={t('employees.import.totalRows')} value={batch.total_rows} />
                                <Stat label={t('employees.import.validRows')} value={batch.valid_rows} tone="text-emerald-600 dark:text-emerald-400" />
                                <Stat label={t('employees.import.invalidRows')} value={batch.failed_rows} tone="text-red-600 dark:text-red-400" />
                            </div>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-800">
                                <thead className="bg-gray-50 dark:bg-slate-950">
                                    <tr>
                                        <Th>#</Th>
                                        <Th>{t('employees.employee')}</Th>
                                        <Th>{t('employees.employee_id')}</Th>
                                        <Th>{t('organizations.title')}</Th>
                                        <Th>{t('nav.positions')}</Th>
                                        <Th>{t('common.status')}</Th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {preview.map((row) => (
                                        <tr
                                            key={row.row_number}
                                            className={row.status === 'invalid' ? 'bg-red-50/60 dark:bg-red-950/20' : undefined}
                                        >
                                            <td className="px-4 py-2 tabular-nums text-gray-500 dark:text-slate-400">
                                                {row.row_number}
                                            </td>
                                            <td className="px-4 py-2 text-gray-900 dark:text-slate-100">{row.name || '—'}</td>
                                            <td className="px-4 py-2 font-mono text-xs text-gray-600 dark:text-slate-400">
                                                {row.employee_number ?? '—'}
                                            </td>
                                            <td className="px-4 py-2 text-gray-700 dark:text-slate-300">
                                                <div>{row.organization ?? '—'}</div>
                                                {row.organization_unit && (
                                                    <div className="text-xs text-gray-500 dark:text-slate-400">
                                                        {row.organization_unit}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-gray-700 dark:text-slate-300">{row.position ?? '—'}</td>
                                            <td className="px-4 py-2">
                                                {row.status === 'invalid' ? (
                                                    <ul className="space-y-0.5 text-xs text-red-600 dark:text-red-400">
                                                        {row.errors.map((error, index) => (
                                                            <li key={index}>{error}</li>
                                                        ))}
                                                    </ul>
                                                ) : (
                                                    <span className="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                                        {t('employees.import.rowValid')}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex flex-wrap gap-2 border-t border-gray-100 px-5 py-3 dark:border-slate-800">
                            {can.confirm && (
                                <button
                                    type="button"
                                    onClick={confirmImport}
                                    disabled={!batch.importable || confirming}
                                    className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {t('employees.import.confirm')}
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={cancelImport}
                                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {t('employees.import.cancel')}
                            </button>

                            {!batch.importable && (
                                <p className="self-center text-xs text-red-600 dark:text-red-400">
                                    {t('employees.import.notImportable')}
                                </p>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: string }): JSX.Element {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className="text-gray-500 dark:text-slate-400">{label}</span>
            <span className={`font-semibold tabular-nums ${tone ?? 'text-gray-900 dark:text-slate-100'}`}>{value}</span>
        </span>
    );
}

function Th({ children }: { children?: React.ReactNode }): JSX.Element {
    return (
        <th className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
            {children}
        </th>
    );
}
