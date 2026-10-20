import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Select, buttonClassName, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { DateText, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, TransportEligibility } from '@/types/transport';

const helper = createColumnHelper<TransportEligibility>();

export default function Index({ grants, filters, can }: { grants: Paginated<TransportEligibility>; filters: Record<string, string | undefined>; can: { create: boolean; update: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const [draft, setDraft] = useState({ status: filters.status ?? '', subject_type: filters.subject_type ?? '' });
    const apply = (next = draft) => router.get(route('transport.eligibility.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });

    const columns = [
        helper.display({ id: 'subject', header: t('transport.subject'), cell: (c) => { const g = c.row.original; return g.subject_type === 'employee' ? <div><div className="font-medium">{g.employee?.full_name}</div><div className="text-xs text-(--app-muted-foreground)">{g.employee?.employee_number}</div></div> : <div><div className="font-medium">{name(g.organization)}</div><div className="text-xs text-(--app-muted-foreground)">{g.scope_type === 'subtree' ? t('transport.scopeSubtree') : t('transport.scopeSelf')}</div></div>; } }),
        helper.display({ id: 'provider', header: t('transport.provider'), cell: (c) => (c.row.original.provider ? name(c.row.original.provider) : t('transport.anyProvider')) }),
        helper.display({ id: 'validity', header: t('transport.validity'), cell: (c) => <span className="whitespace-nowrap text-xs"><DateText value={c.row.original.effective_from} /> – {c.row.original.effective_to ? <DateText value={c.row.original.effective_to} /> : '…'}</span> }),
        helper.accessor('authorization_reference', { header: t('transport.authorizationReference'), cell: (c) => <span className="text-xs">{c.getValue()}</span> }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
        helper.display({ id: 'actions', header: '', cell: (c) => (can.update ? <Link href={route('transport.eligibility.edit', c.row.original.id)} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>{t('transport.edit')}</Link> : null) }),
    ] as ColumnDef<TransportEligibility, unknown>[];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.eligibility')} description={t('transport.eligibilityIntro')} actions={can.create ? <Link href={route('transport.eligibility.create')} className={buttonClassName({ variant: 'primary' })}>{t('transport.newEligibility')}</Link> : undefined} />}>
            <Head title={t('transport.eligibility')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ status: '', subject_type: '' })} hasActiveFilters={Boolean(filters.status || filters.subject_type)}>
                    <FilterField label={t('transport.subjectType')}><Select value={draft.subject_type} onChange={(e) => setDraft({ ...draft, subject_type: e.target.value })}><option value="">{t('transport.all')}</option><option value="organization">{label('subjectTypes', 'organization')}</option><option value="employee">{label('subjectTypes', 'employee')}</option></Select></FilterField>
                    <FilterField label={t('transport.status')}><Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="">{t('transport.all')}</option>{['active', 'suspended', 'ended'].map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={grants.data} columns={columns} emptyTitle={t('transport.noRecords')} pagination={uiMeta(grants.meta)} onPageChange={(page) => router.get(route('transport.eligibility.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </AuthenticatedLayout>
    );
}
