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

import { actionLinkClassName, buttonClassName } from '@/Components/ui';
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

    const exportButtonCls = buttonClassName({ variant: 'outline', size: 'sm' });

    const exportButtons = (
        <>
            <span className="mx-1 h-4 w-px bg-(--app-surface-muted)" />
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
                    className={actionLinkClassName({ size: 'sm' })}
                >
                    ← {t('organizations.backToOrganization')}
                </Link>

                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
                    >
                        {t('organizations.printOrganogram')}
                    </button>
                    {/* Server-rendered dompdf variant — a text outline that
                        survives without a browser, kept alongside the
                        client-side visual capture in the chart toolbar. */}
                    <a
                        href={`${route('organizations.organogram', tree.organization.id)}?format=pdf`}
                        className={buttonClassName({ variant: 'primary', size: 'sm' })}
                    >
                        {t('organizations.exportOutlinePdf')}
                    </a>
                </div>
            </div>

            <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5 print:border-0 print:p-0">
                <OrganogramChart tree={tree} captureRef={captureRef} toolbarExtra={exportButtons} />
            </section>
        </AuthenticatedLayout>
    );
}
