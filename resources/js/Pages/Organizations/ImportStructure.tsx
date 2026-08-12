import { Head, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import StatCard from '@/Components/StatCard';
import EmptyState from '@/Components/EmptyState';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

type SheetDefinition = {
    name: string;
    required: boolean;
    columns: string[];
    required_columns: string[];
};

type ImportIssue = {
    sheet: string;
    row: number | null;
    column: string | null;
    message: string;
};

type UnitNode = {
    code: string;
    name_en: string | null;
    name_am: string | null;
    type_code: string | null;
    status: string;
    parent_code: string | null;
    children: UnitNode[];
};

type CodeAssignment = {
    sheet: string;
    row: number;
    name: string;
    provided_code: string | null;
    generated_code: string | null;
    code: string | null;
    source: 'provided' | 'generated_by_code_rule';
    code_rule: string | null;
};

type ImportPreview = {
    file_name: string | null;
    can_import: boolean;
    mode: 'create' | 'update';
    auto_generate_codes: boolean;
    codes: CodeAssignment[];
    organization: {
        code: string | null;
        name_en: string | null;
        name_am: string | null;
        type_code: string | null;
        parent_code: string | null;
        status: string;
        exists: boolean;
    } | null;
    unit_tree: UnitNode[];
    counts: { units: number; positions: number; employees: number };
    errors: Record<string, ImportIssue[]>;
    warnings: Record<string, ImportIssue[]>;
    error_count: number;
    warning_count: number;
    result: {
        organization_code: string;
        mode: string;
        units_created: number;
        positions_created: number;
        employees_created: number;
        assignments_created: number;
    } | null;
};

const buttonBase =
    'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50';
const primaryButton = `${buttonBase} bg-blue-600 text-white hover:bg-blue-700`;
const secondaryButton = `${buttonBase} border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800`;
const cellCls = 'px-3 py-2 text-sm text-gray-700 dark:text-slate-300';
const headCls =
    'px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400';

/** One flat, grouped table of errors or warnings — the wizard's main feedback surface. */
function IssueTable({
    title,
    groups,
    tone,
}: {
    title: string;
    groups: Record<string, ImportIssue[]>;
    tone: 'error' | 'warning';
}) {
    const { t } = useLocale();
    const sheets = Object.keys(groups);

    if (sheets.length === 0) return null;

    const toneCls =
        tone === 'error'
            ? 'border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-950/30'
            : 'border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-950/30';
    const titleCls =
        tone === 'error' ? 'text-red-800 dark:text-red-300' : 'text-amber-800 dark:text-amber-300';

    return (
        <div className={`rounded-xl border ${toneCls} p-4`}>
            <h3 className={`text-sm font-semibold ${titleCls}`}>{title}</h3>

            {sheets.map((sheet) => (
                <div key={sheet} className="mt-3">
                    <p className="text-xs font-semibold text-gray-600 dark:text-slate-400">{sheet}</p>
                    <div className="mt-1 overflow-x-auto">
                        <table className="min-w-full">
                            <thead>
                                <tr>
                                    <th className={headCls}>{t('organizationStructureImport.columns.row')}</th>
                                    <th className={headCls}>{t('organizationStructureImport.columns.column')}</th>
                                    <th className={headCls}>{t('organizationStructureImport.columns.message')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups[sheet].map((issue, index) => (
                                    <tr key={`${sheet}-${index}`} className="border-t border-gray-200 dark:border-slate-800">
                                        <td className={cellCls}>{issue.row ?? '—'}</td>
                                        <td className={`${cellCls} font-mono text-xs`}>{issue.column ?? '—'}</td>
                                        <td className={cellCls}>{issue.message}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * Provided vs generated code, per row. This is the whole point of the preview:
 * the user sees the exact code every blank cell will receive, and which Code
 * Rule produced it, before anything is written.
 */
function CodeTable({ codes }: { codes: CodeAssignment[] }) {
    const { t } = useLocale();

    if (codes.length === 0) return null;

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full">
                <thead>
                    <tr>
                        <th className={headCls}>{t('organizationStructureImport.columns.sheet')}</th>
                        <th className={headCls}>{t('organizationStructureImport.columns.row')}</th>
                        <th className={headCls}>{t('organizationStructureImport.name')}</th>
                        <th className={headCls}>{t('organizationStructureImport.providedCode')}</th>
                        <th className={headCls}>{t('organizationStructureImport.generatedCode')}</th>
                        <th className={headCls}>{t('organizationStructureImport.codeRuleUsed')}</th>
                    </tr>
                </thead>
                <tbody>
                    {codes.map((entry) => (
                        <tr
                            key={`${entry.sheet}-${entry.row}`}
                            className="border-t border-gray-200 dark:border-slate-800"
                        >
                            <td className={cellCls}>{entry.sheet}</td>
                            <td className={cellCls}>{entry.row}</td>
                            <td className={cellCls}>{entry.name || '—'}</td>
                            <td className={`${cellCls} font-mono text-xs`}>{entry.provided_code ?? '—'}</td>
                            <td className={`${cellCls} font-mono text-xs`}>
                                {entry.generated_code ? (
                                    <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                                        {entry.generated_code}
                                    </span>
                                ) : (
                                    '—'
                                )}
                            </td>
                            <td className={cellCls}>
                                {entry.source === 'generated_by_code_rule'
                                    ? (entry.code_rule ?? t('organizationStructureImport.generatedByCodeRule'))
                                    : '—'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Recursive preview of the unit hierarchy the file declares. */
function UnitTree({ nodes, locale, depth = 0 }: { nodes: UnitNode[]; locale: string; depth?: number }) {
    return (
        <ul className={depth === 0 ? 'space-y-1' : 'mt-1 space-y-1 border-l border-gray-200 pl-4 dark:border-slate-800'}>
            {nodes.map((node) => (
                <li key={node.code}>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium text-gray-900 dark:text-slate-100">
                            {localizedName(node.name_en, node.name_am, locale) || node.code}
                        </span>
                        <span className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-600 dark:bg-slate-800 dark:text-slate-400">
                            {node.code}
                        </span>
                        {node.type_code && (
                            <span className="text-xs text-gray-400 dark:text-slate-500">{node.type_code}</span>
                        )}
                    </div>
                    {node.children.length > 0 && (
                        <UnitTree nodes={node.children} locale={locale} depth={depth + 1} />
                    )}
                </li>
            ))}
        </ul>
    );
}

export default function ImportStructure({
    sheets,
    preview,
}: {
    sheets: SheetDefinition[];
    preview?: ImportPreview;
}) {
    const { t, locale } = useLocale();
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [showSpec, setShowSpec] = useState(false);

    const form = useForm<{
        file: File | null;
        import_employees: boolean;
        auto_generate_codes: boolean;
    }>({
        file: null,
        import_employees: true,
        // Blank code cells are generated from the Code Rules by default.
        auto_generate_codes: true,
    });

    // The confirm step re-uploads the same file the preview validated, so the
    // server can re-check it from scratch rather than trust a cached verdict.
    const hasFile = form.data.file !== null;

    function submitPreview(event: React.FormEvent) {
        event.preventDefault();
        form.post(route('organizations.import-structure.preview'), { forceFormData: true });
    }

    function submitConfirm() {
        form.post(route('organizations.import-structure.confirm'), { forceFormData: true });
    }

    const result = preview?.result ?? null;

    return (
        <AuthenticatedLayout>
            <Head title={t('organizationStructureImport.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('organizationStructureImport.title')}
                    description={t('organizationStructureImport.description')}
                    backHref={route('organizations.index')}
                    actions={
                        <a href={route('organizations.import-structure.template')} className={secondaryButton}>
                            {t('organizationStructureImport.downloadTemplate')}
                        </a>
                    }
                />

                {/* ── Step 1: upload ─────────────────────────────────────── */}
                <FormSection title={t('organizationStructureImport.uploadExcelFile')} grid={false}>
                    <form onSubmit={submitPreview} className="space-y-4">
                        <div>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".xlsx,.xls,.csv"
                                onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                                className="block w-full text-sm text-gray-600 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-blue-700 dark:text-slate-400"
                            />
                            {form.errors.file && (
                                <p className="mt-2 text-sm text-red-600 dark:text-red-400">{form.errors.file}</p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                                <input
                                    type="checkbox"
                                    checked={form.data.auto_generate_codes}
                                    onChange={(event) => form.setData('auto_generate_codes', event.target.checked)}
                                    className="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950"
                                />
                                {t('organizationStructureImport.autoGenerateCodes')}
                            </label>
                            <p className="ml-6 text-xs text-gray-500 dark:text-slate-400">
                                {t('organizationStructureImport.autoGenerateHint')}
                            </p>

                            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                                <input
                                    type="checkbox"
                                    checked={form.data.import_employees}
                                    onChange={(event) => form.setData('import_employees', event.target.checked)}
                                    className="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950"
                                />
                                {t('organizationStructureImport.importEmployees')}
                            </label>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <button type="submit" className={primaryButton} disabled={!hasFile || form.processing}>
                                {t('organizationStructureImport.previewImport')}
                            </button>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => setShowSpec((open) => !open)}
                            >
                                {t('organizationStructureImport.expectedSheets')}
                            </button>
                        </div>
                    </form>

                    {showSpec && (
                        <div className="mt-4 space-y-3 rounded-lg border border-gray-200 p-4 dark:border-slate-800">
                            {sheets.map((sheet) => (
                                <div key={sheet.name}>
                                    <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                                        {sheet.name}{' '}
                                        <span className="text-xs font-normal text-gray-400 dark:text-slate-500">
                                            (
                                            {sheet.required
                                                ? t('organizationStructureImport.required')
                                                : t('organizationStructureImport.optional')}
                                            )
                                        </span>
                                    </p>
                                    <p className="mt-1 flex flex-wrap gap-1">
                                        {sheet.columns.map((column) => {
                                            const isRequired = sheet.required_columns.includes(column);

                                            return (
                                                <span
                                                    key={column}
                                                    className={`rounded px-1.5 py-0.5 font-mono text-xs ${
                                                        isRequired
                                                            ? 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300'
                                                            : 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400'
                                                    }`}
                                                >
                                                    {column}
                                                </span>
                                            );
                                        })}
                                    </p>
                                </div>
                            ))}
                        </div>
                    )}
                </FormSection>

                {/* ── Step 2: preview ────────────────────────────────────── */}
                {preview && (
                    <>
                        {result && (
                            <div className="rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-900/50 dark:bg-green-950/30">
                                <h3 className="text-sm font-semibold text-green-800 dark:text-green-300">
                                    {t('organizationStructureImport.importedSuccessfully')}
                                </h3>
                            </div>
                        )}

                        <div className="grid gap-4 sm:grid-cols-3">
                            <StatCard
                                label={t('organizationStructureImport.counts.units')}
                                value={preview.counts.units}
                            />
                            <StatCard
                                label={t('organizationStructureImport.counts.positions')}
                                value={preview.counts.positions}
                            />
                            <StatCard
                                label={t('organizationStructureImport.counts.employees')}
                                value={preview.counts.employees}
                            />
                        </div>

                        {preview.organization && (
                            <FormSection title={t('organizationStructureImport.organizationSummary')} grid={false}>
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    {[
                                        ['code', preview.organization.code],
                                        ['name_en', preview.organization.name_en],
                                        ['name_am', preview.organization.name_am],
                                        ['organization_type_code', preview.organization.type_code],
                                        ['parent_organization_code', preview.organization.parent_code],
                                        ['status', preview.organization.status],
                                    ].map(([label, value]) => (
                                        <div key={label as string}>
                                            <dt className="font-mono text-xs text-gray-500 dark:text-slate-500">
                                                {label}
                                            </dt>
                                            <dd className="text-sm text-gray-900 dark:text-slate-100">
                                                {value || '—'}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="mt-3 text-sm text-gray-500 dark:text-slate-400">
                                    {preview.mode === 'update'
                                        ? t('organizationStructureImport.modeUpdate')
                                        : t('organizationStructureImport.modeCreate')}
                                </p>
                            </FormSection>
                        )}

                        {preview.unit_tree.length > 0 && (
                            <FormSection title={t('organizationStructureImport.unitTree')} grid={false}>
                                <UnitTree nodes={preview.unit_tree} locale={locale} />
                            </FormSection>
                        )}

                        {preview.codes.length > 0 && (
                            <FormSection title={t('organizationStructureImport.generatedCodes')} grid={false}>
                                <CodeTable codes={preview.codes} />
                            </FormSection>
                        )}

                        <IssueTable
                            title={t('organizationStructureImport.importErrors')}
                            groups={preview.errors}
                            tone="error"
                        />
                        <IssueTable
                            title={t('organizationStructureImport.importWarnings')}
                            groups={preview.warnings}
                            tone="warning"
                        />

                        {preview.error_count === 0 && preview.warning_count === 0 && !result && (
                            <EmptyState title={t('organizationStructureImport.noErrors')} />
                        )}

                        {/* ── Step 3: confirm ────────────────────────────── */}
                        {!result && (
                            <div className="flex flex-wrap items-center gap-3">
                                <button
                                    type="button"
                                    className={primaryButton}
                                    onClick={submitConfirm}
                                    // Disabled without a clean preview *and* without the
                                    // file still in hand — the confirm step re-uploads it.
                                    disabled={!preview.can_import || !hasFile || form.processing}
                                >
                                    {t('organizationStructureImport.confirmImport')}
                                </button>

                                {!preview.can_import && (
                                    <p className="text-sm text-red-600 dark:text-red-400">
                                        {t('organizationStructureImport.errorsBlockImport')}
                                    </p>
                                )}
                                {preview.can_import && !hasFile && (
                                    <p className="text-sm text-gray-500 dark:text-slate-400">
                                        {t('organizationStructureImport.reselectFile')}
                                    </p>
                                )}
                            </div>
                        )}
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
