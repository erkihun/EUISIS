import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import type { OrganizationStructureTreeData } from '@/Components/organizations/OrganizationStructureTree';
import OrganogramChart from '@/Components/organizations/OrganogramChart';
import { useLocale } from '@/hooks/useLocale';
import { useOrganogramExport } from '@/hooks/useOrganogramExport';
import { toast } from '@/lib/toast';
import { localizedName } from '@/utils/localizedName';
import { useEffect } from 'react';

/**
 * Single-organization organogram. The tree itself is the same component the
 * detail page uses, so node content and expand/collapse behave identically;
 * this page adds the print and PDF affordances around it.
 */
export default function OrganizationOrganogram({ tree }: { tree: OrganizationStructureTreeData }) {
    const { t, locale } = useLocale();
    const organizationName = localizedName(tree.organization.name_en, tree.organization.name_am, locale);
    const { captureRef, exporting, error, exportPng, exportPdf, clearError } = useOrganogramExport(
        tree.organization.code,
        organizationName,
    );

    // Surface capture failures through the app's toast system.
    useEffect(() => {
        if (error !== null) {
            toast.error(t('organizations.exportFailed'));
            clearError();
        }
    }, [error, t, clearError]);

    const exportButtonCls =
        'rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';

    const exportButtons = (
        <>
            <span className="mx-1 h-4 w-px bg-gray-200 dark:bg-slate-700" />
            <button type="button" onClick={exportPng} disabled={exporting !== null} className={exportButtonCls}>
                {exporting === 'png' ? t('organizations.exporting') : t('organizations.exportPng')}
            </button>
            <button
                type="button"
                onClick={() => exportPdf({
                    title: t('organizations.organizationOrganogram'),
                    subtitle: `${tree.organization.code} — ${organizationName}`,
                    generatedLabel: t('organizations.generatedDate'),
                })}
                disabled={exporting !== null}
                className={exportButtonCls}
            >
                {exporting === 'pdf' ? t('organizations.exporting') : t('organizations.exportPdf')}
            </button>
        </>
    );

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('organizations.organizationOrganogram')} description={organizationName} />}
        >
            <Head title={`${t('organizations.organizationOrganogram')} — ${organizationName}`} />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
                <Link
                    href={route('organizations.show', tree.organization.id)}
                    className="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400"
                >
                    ← {t('organizations.backToOrganization')}
                </Link>

                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        {t('organizations.printOrganogram')}
                    </button>
                    {/* Server-rendered dompdf variant — a text outline that
                        survives without a browser, kept alongside the
                        client-side visual capture in the chart toolbar. */}
                    <a
                        href={`${route('organizations.organogram', tree.organization.id)}?format=pdf`}
                        className="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
                    >
                        {t('organizations.exportOutlinePdf')}
                    </a>
                </div>
            </div>

            <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 print:border-0 print:p-0">
                <OrganogramChart tree={tree} captureRef={captureRef} toolbarExtra={exportButtons} />
            </section>
        </AuthenticatedLayout>
    );
}
