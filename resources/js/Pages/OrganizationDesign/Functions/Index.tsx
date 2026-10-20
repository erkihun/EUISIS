import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FunctionTree, { type FunctionNode } from '@/Components/organizationDesign/FunctionTree';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Card, CardHeader, CardTitle, FilterField, FilterPanel, Select, actionLinkClassName } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Organization = { id: string; code: string; name_en: string; name_am: string | null };
type Mandate = { id: string; code: string; version_no: number; title_en: string; title_am: string | null; status: string };

type Props = {
    organizations: Organization[];
    organizationId: string | null;
    mandates: Mandate[];
    tree: FunctionNode[];
    filters: { organization_id?: string };
};

/**
 * The function tree of one organization across all its mandates, read-only:
 * functions are written on the mandate page, under the mandate they rest on.
 */
export default function FunctionsIndex({ organizations, organizationId, mandates, tree }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState(organizationId ?? '');
    const [mandateId, setMandateId] = useState('');
    const shown = mandateId ? tree.filter((node) => node.mandate_id === mandateId) : tree;
    const organization = organizations.find((o) => o.id === organizationId) ?? null;

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('functions.title')} description={tt('functions.description')} />}>
            <Head title={tt('functions.title')} />

            <div className="space-y-4">
                <FilterPanel onSubmit={() => router.get(route('organization-design.functions.index'), { organization_id: draft }, { preserveState: true })} hasActiveFilters={false}>
                    <FilterField label={tt('fields.organization')} wide>
                        <Select value={draft} onChange={(e) => setDraft(e.target.value)}>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('functions.underMandate')}>
                        <Select value={mandateId} onChange={(e) => setMandateId(e.target.value)}>
                            <option value="">{tt('functions.allMandates')}</option>
                            {mandates.map((m) => <option key={m.id} value={m.id}>{m.code} v{m.version_no} · {titleOf(m, locale)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader><CardTitle>{organization ? `${tt('functions.tree')} · ${nameOf(organization, locale)}` : tt('functions.tree')}</CardTitle></CardHeader>
                        <div className="p-3">
                            <FunctionTree tree={shown} canManage={false} emptyText={tt('functions.none')} />
                        </div>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>{tt('mandates.title')}</CardTitle></CardHeader>
                        {mandates.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('mandates.noMandates')}</p> : (
                            <ul className="divide-y divide-(--app-border)">
                                {mandates.map((m) => (
                                    <li key={m.id} className="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                        <div className="min-w-0">
                                            <Link href={route('organization-design.mandates.show', m.id)} className={actionLinkClassName({ size: 'sm' })}>{m.code} v{m.version_no}</Link>
                                            <p className="truncate text-xs text-(--app-muted-foreground)">{titleOf(m, locale)}</p>
                                        </div>
                                        <StatusPill status={m.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="border-t border-(--app-border) px-5 py-3 text-xs text-(--app-muted-foreground)">{t('common.edit')}: {tt('mandates.title')} → {tt('functions.tree')}</p>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
