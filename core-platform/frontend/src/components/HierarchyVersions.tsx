import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { api, firstError } from '../api';
import { useAuth } from '../auth';
import { useLocale } from '../i18n';
import { formatDate } from '../lib/format';
import { hierarchyTransition, type HierarchyVersion } from '../lib/hierarchy';
import { nameOptions, useLookups } from '../lib/useLookups';
import { Alert, Button, Card, CardHeader, CardTitle, EmptyState, FormField, Select, Table, TBody, Td, Th, THead, Tr } from '../ui';
import FormDialog from './FormDialog';
import StatusBadge from './StatusBadge';

type Detail = {
    data: HierarchyVersion;
    edges: { parent_organization_id: string; child_organization_id: string; relationship_type: string }[];
    impact: Record<string, number>;
};

/** Version management is mounted only for citywide administrators. */
export default function HierarchyVersions({ onPublished }: { onPublished: () => void }) {
    const { user } = useAuth();
    const { t, locale } = useLocale();
    const { lookups } = useLookups();
    const [versions, setVersions] = useState<HierarchyVersion[] | null>(null);
    const [selected, setSelected] = useState('');
    const [detail, setDetail] = useState<Detail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [dialog, setDialog] = useState<'create' | 'parent' | 'transition' | null>(null);
    const [revision, setRevision] = useState(0);
    const reload = useCallback(() => setRevision((value) => value + 1), []);

    useEffect(() => {
        const controller = new AbortController();
        api<{ data: HierarchyVersion[] }>('/v1/hierarchy-versions', { signal: controller.signal })
            .then(({ data }) => { setVersions(data); setError(null); })
            .catch((caught) => { if (!controller.signal.aborted) setError(firstError(caught) ?? t('platform.error')); });
        return () => controller.abort();
    }, [revision, t]);

    useEffect(() => {
        setDetail(null);
        if (!selected) return;
        const controller = new AbortController();
        api<Detail>(`/v1/hierarchy-versions/${selected}`, { signal: controller.signal })
            .then((response) => { setDetail(response); setError(null); })
            .catch((caught) => { if (!controller.signal.aborted) setError(firstError(caught) ?? t('platform.error')); });
        return () => controller.abort();
    }, [selected, revision, t]);

    const version = detail?.data;
    const transition = version && user ? hierarchyTransition(version, user.id) : null;
    const organizations = nameOptions(locale, lookups.organizations);
    const organizationName = (id: string) => organizations.find((row) => row.value === id)?.label ?? id;

    return <Card className="mb-6 space-y-4">
        <CardHeader><CardTitle>{t('hierarchyVersions.title')}</CardTitle><Button onClick={() => setDialog('create')}>{t('hierarchyVersions.create')}</Button></CardHeader>
        {error && <Alert tone="danger">{error}</Alert>}
        {versions === null ? <EmptyState title={t('common.loading')} /> : <FormField label={t('hierarchyVersions.select')}>
            {({ id }) => <Select id={id} value={selected} onChange={(event) => { setSelected(event.target.value); setDialog(null); }}>
                <option value="">{t('hierarchyVersions.select')}</option>
                {versions.map((row) => <option key={row.id} value={row.id}>{row.version_name} · {t(`hierarchyVersions.states.${row.status}`)}</option>)}
            </Select>}
        </FormField>}
        {selected && !detail && !error && <EmptyState title={t('common.loading')} />}
        {version && detail && <>
            <div className="flex flex-wrap items-center gap-3">
                <StatusBadge status={version.status} label={t(`hierarchyVersions.states.${version.status}`)} />
                <span className="text-sm">{t('hierarchyVersions.effectiveFrom')}: {formatDate(version.effective_from)}</span>
                {version.status === 'draft' && <Button variant="outline" onClick={() => setDialog('parent')}>{t('hierarchyVersions.changeParent')}</Button>}
                {transition && <Button variant="primary" onClick={() => setDialog('transition')}>{t(`hierarchyVersions.${transition}`)}</Button>}
            </div>
            {version.status === 'review' && version.created_by === user?.id && <Alert>{t('hierarchyVersions.separateApprover')}</Alert>}
            {version.legal_basis && <p className="text-sm">{t('hierarchyVersions.legalBasis')}: {version.legal_basis}</p>}
            <div>
                <h3 className="mb-2 text-sm font-semibold">{t('hierarchyVersions.impact')}</h3>
                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {Object.entries(detail.impact).map(([key, count]) => <div key={key}><dt className="text-xs text-(--app-muted-foreground)">{t(`hierarchyVersions.impactFields.${key}`)}</dt><dd className="font-semibold">{count}</dd></div>)}
                </dl>
            </div>
            <Table><THead><Tr><Th>{t('hierarchyVersions.child')}</Th><Th>{t('hierarchyVersions.parent')}</Th></Tr></THead><TBody>
                {detail.edges.map((edge) => <Tr key={`${edge.child_organization_id}:${edge.relationship_type}:${edge.parent_organization_id}`}><Td>{organizationName(edge.child_organization_id)}</Td><Td>{organizationName(edge.parent_organization_id)}</Td></Tr>)}
            </TBody></Table>
        </>}
        <FormDialog open={dialog === 'create'} title={t('hierarchyVersions.create')} description={t('hierarchyVersions.cloneHelp')}
            fields={[
                { name: 'version_name', label: t('common.name'), required: true },
                { name: 'effective_from', label: t('hierarchyVersions.effectiveFrom'), type: 'date', required: true },
                { name: 'legal_basis', label: t('hierarchyVersions.legalBasis'), type: 'textarea' },
                { name: 'source_document', label: t('hierarchyVersions.sourceDocument') },
            ]} initial={{ version_name: '', effective_from: '', legal_basis: null, source_document: null }} submitLabel={t('platform.actions.save')} onClose={() => setDialog(null)}
            onSubmit={async (values) => {
                const response = await api<{ data: HierarchyVersion }>('/v1/hierarchy-versions', { method: 'POST', body: values });
                setSelected(response.data.id); setDialog(null); reload(); toast.success(t('platform.actions.saved'));
            }} />
        <FormDialog open={dialog === 'parent' && version?.status === 'draft'} title={t('hierarchyVersions.changeParent')} description={t('hierarchyVersions.parentHelp')}
            fields={[
                { name: 'child_organization_id', label: t('hierarchyVersions.child'), type: 'select', required: true, options: organizations },
                { name: 'parent_organization_id', label: t('hierarchyVersions.parent'), type: 'select', options: organizations, emptyOption: t('hierarchyVersions.root') },
            ]} initial={{ child_organization_id: '', parent_organization_id: null }} submitLabel={t('platform.actions.save')} onClose={() => setDialog(null)}
            onSubmit={async (values) => {
                await api(`/v1/hierarchy-versions/${selected}/parent`, { method: 'PUT', body: { ...values, parent_organization_id: values.parent_organization_id || null } });
                setDialog(null); reload(); toast.success(t('platform.actions.saved'));
            }} />
        <FormDialog open={dialog === 'transition' && transition !== null} title={transition ? t(`hierarchyVersions.${transition}`) : ''} description={t('hierarchyVersions.transitionHelp')} fields={[]} initial={{}} submitLabel={transition ? t(`hierarchyVersions.${transition}`) : ''} onClose={() => setDialog(null)}
            onSubmit={async () => {
                if (!transition) return;
                await api(`/v1/hierarchy-versions/${selected}/${transition}`, { method: 'POST' });
                setDialog(null); reload(); if (transition === 'publish') onPublished(); toast.success(t('platform.actions.saved'));
            }} />
    </Card>;
}
