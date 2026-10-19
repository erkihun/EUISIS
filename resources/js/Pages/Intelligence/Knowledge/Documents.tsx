import { type FormEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { FilterChips } from '@/Components/intelligence/StatusBadges';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Alert, Button, Card, CardTitle, FormField, Input, ListPagination, Select, TBody, THead, Table, TableCard, Td, Textarea, Th, Tr, badgeClassName, type Tone } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SrLabels } from '@/types/situationRoom';
import type { KnowledgeClassification, KnowledgeDocumentPage, KnowledgeDocumentRow, KnowledgeStatus } from '@/types/intelligenceKnowledge';

type Props = {
    labels: SrLabels;
    filters: { status: string; classification: string };
    options: { statuses: KnowledgeStatus[]; classifications: KnowledgeClassification[]; languages: Array<'en' | 'am'> };
    counts: Record<KnowledgeStatus, number>;
    documents: KnowledgeDocumentPage;
    scoped: boolean;
};

const STATUS_TONE: Record<KnowledgeStatus, Tone> = { draft: 'warning', approved: 'success', retired: 'neutral' };
const CLASS_TONE: Record<KnowledgeClassification, Tone> = { public: 'info', internal: 'neutral', confidential: 'warning', restricted: 'danger' };

/**
 * Knowledge documents for governance managers: the list with its filters, an
 * inline create form (a document is text, not a dialog), and the approve /
 * retire / delete actions, each confirmed and audited server-side.
 */
export default function KnowledgeDocuments({ filters, options, counts, documents, scoped }: PageProps<Props>) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const { confirm } = useConfirm();
    const title = t('intelligenceKnowledge.documents.title');
    const build = (patch: Partial<typeof filters>) => route('intelligence.knowledge.documents.index', Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, v]) => v !== '')));

    const form = useForm({ title: '', body: '', classification: 'internal' as KnowledgeClassification, language: 'en' as 'en' | 'am', organization_id: '', required_permission: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, organization_id: data.organization_id.trim() || null, required_permission: data.required_permission.trim() || null }));
        form.post(route('intelligence.knowledge.documents.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const transition = async (doc: KnowledgeDocumentRow, status: 'approved' | 'retired') => {
        const { confirmed } = await confirm({
            title: status === 'approved' ? t('intelligenceKnowledge.documents.approve') : t('intelligenceKnowledge.documents.retire'),
            description: status === 'approved' ? t('intelligenceKnowledge.documents.approveConfirm') : t('intelligenceKnowledge.documents.retireConfirm'),
            confirmLabel: status === 'approved' ? t('intelligenceKnowledge.documents.approve') : t('intelligenceKnowledge.documents.retire'),
            cancelLabel: t('common.cancel'),
            variant: status === 'approved' ? 'success' : 'warning',
        });
        if (confirmed) {
            router.patch(route('intelligence.knowledge.documents.update', doc.id), { status }, { preserveScroll: true });
        }
    };
    const remove = async (doc: KnowledgeDocumentRow) => {
        const { confirmed } = await confirm({
            title: t('common.delete'),
            description: t('intelligenceKnowledge.documents.deleteConfirm'),
            confirmLabel: t('common.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) {
            router.delete(route('intelligence.knowledge.documents.destroy', doc.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('intelligenceKnowledge.documents.description')} backHref={route('intelligence.knowledge.index')} />}>
            <Head title={`${title} · ${t('intelligence.title')}`} />

            <div className="space-y-5">
                <ul className="grid grid-cols-3 gap-3">
                    {options.statuses.map((status) => (
                        <li key={status}>
                            <Card className="flex items-center justify-between gap-3 p-4">
                                <span className={badgeClassName(STATUS_TONE[status])}>{sr(`intelligence.statuses.${status}`)}</span>
                                <span className="text-2xl font-semibold tabular-nums text-(--app-foreground)">{counts[status]}</span>
                            </Card>
                        </li>
                    ))}
                </ul>

                <div className="space-y-2">
                    <FilterChips
                        label={t('intelligenceKnowledge.documents.status')}
                        value={filters.status}
                        options={[{ key: '', label: t('intelligenceKnowledge.documents.all') }, ...options.statuses.map((key) => ({ key, label: sr(`intelligence.statuses.${key}`) }))]}
                        build={(key) => build({ status: key })}
                    />
                    <FilterChips
                        label={t('intelligenceKnowledge.documents.classification')}
                        value={filters.classification}
                        options={[{ key: '', label: t('intelligenceKnowledge.documents.all') }, ...options.classifications.map((key) => ({ key, label: sr(`intelligence.classifications.${key}`) }))]}
                        build={(key) => build({ classification: key })}
                    />
                </div>

                <TableCard title={t('intelligenceKnowledge.documents.list')} footer={<ListPagination meta={documents} />}>
                    {documents.data.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-(--app-muted-foreground)">{t('intelligenceKnowledge.documents.none')}</p>
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('intelligenceKnowledge.documents.titleColumn')}</Th>
                                    <Th>{t('intelligenceKnowledge.documents.status')}</Th>
                                    <Th>{t('intelligenceKnowledge.documents.classification')}</Th>
                                    <Th>{t('intelligenceKnowledge.documents.scope')}</Th>
                                    <Th align="right">{t('intelligenceKnowledge.documents.version')}</Th>
                                    <Th align="right">{t('intelligenceKnowledge.documents.chunks')}</Th>
                                    <Th>{t('intelligenceKnowledge.documents.updatedAt')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {documents.data.map((doc) => (
                                    <Tr key={doc.id}>
                                        <Td>
                                            <span className="block font-medium text-(--app-foreground)">{doc.title}</span>
                                            <span className="block text-xs text-(--app-muted-foreground)">{sr(`intelligence.source_types.${doc.source_type}`)}{doc.source_path ? ` · ${doc.source_path}` : ''} · {doc.language.toUpperCase()}</span>
                                            {doc.required_permission && <span className="block font-mono text-xs text-(--app-muted-foreground)">{doc.required_permission}</span>}
                                        </Td>
                                        <Td>
                                            <span className={badgeClassName(STATUS_TONE[doc.status])}>{sr(`intelligence.statuses.${doc.status}`)}</span>
                                            {doc.approved_by && doc.status === 'approved' && <span className="mt-0.5 block text-xs text-(--app-muted-foreground)">{t('intelligenceKnowledge.documents.approvedBy')}: {doc.approved_by}</span>}
                                        </Td>
                                        <Td><span className={badgeClassName(CLASS_TONE[doc.classification])}>{sr(`intelligence.classifications.${doc.classification}`)}</span></Td>
                                        <Td muted>{doc.organization_id ? <span className="font-mono text-xs">{doc.organization_id}</span> : t('intelligenceKnowledge.documents.cityWide')}</Td>
                                        <Td numeric>{doc.version}</Td>
                                        <Td numeric>{doc.chunks}</Td>
                                        <Td muted>{doc.updated_at ? <LocalizedDateDisplay value={doc.updated_at} withTime /> : '—'}</Td>
                                        <Td align="right">
                                            <div className="flex flex-wrap justify-end gap-1.5">
                                                {doc.status !== 'approved' && (
                                                    <Button size="xs" variant="outline" onClick={() => transition(doc, 'approved')}>
                                                        {doc.status === 'retired' ? t('intelligenceKnowledge.documents.reapprove') : t('intelligenceKnowledge.documents.approve')}
                                                    </Button>
                                                )}
                                                {doc.status === 'approved' && <Button size="xs" variant="outline" onClick={() => transition(doc, 'retired')}>{t('intelligenceKnowledge.documents.retire')}</Button>}
                                                <Button size="xs" variant="destructiveOutline" onClick={() => remove(doc)}>{t('common.delete')}</Button>
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </TableCard>

                <Card className="p-0">
                    <div className="border-b border-(--app-border) px-(--card-padding) py-3"><CardTitle>{t('intelligenceKnowledge.documents.create.title')}</CardTitle></div>
                    <form onSubmit={submit} className="space-y-4 p-(--card-padding)">
                        {scoped && <Alert tone="info">{t('intelligenceKnowledge.documents.scopedNote')}</Alert>}
                        <div className="grid gap-4 md:grid-cols-3">
                            <FormField id="kd-title" label={t('intelligenceKnowledge.documents.create.titleField')} required error={form.errors.title} className="md:col-span-3">
                                {({ id }) => <Input id={id} value={form.data.title} maxLength={190} onChange={(e) => form.setData('title', e.target.value)} />}
                            </FormField>
                            <FormField id="kd-classification" label={t('intelligenceKnowledge.documents.classification')} description={t('intelligenceKnowledge.documents.create.classificationHelp')} error={form.errors.classification}>
                                {({ id }) => (
                                    <Select id={id} value={form.data.classification} onChange={(e) => form.setData('classification', e.target.value as KnowledgeClassification)}>
                                        {options.classifications.map((c) => <option key={c} value={c}>{sr(`intelligence.classifications.${c}`)}</option>)}
                                    </Select>
                                )}
                            </FormField>
                            <FormField id="kd-language" label={t('intelligenceKnowledge.documents.language')} error={form.errors.language}>
                                {({ id }) => (
                                    <Select id={id} value={form.data.language} onChange={(e) => form.setData('language', e.target.value as 'en' | 'am')}>
                                        {options.languages.map((l) => <option key={l} value={l}>{l.toUpperCase()}</option>)}
                                    </Select>
                                )}
                            </FormField>
                            <FormField id="kd-permission" label={t('intelligenceKnowledge.documents.requiredPermission')} description={t('intelligenceKnowledge.documents.create.requiredPermissionHelp')} error={form.errors.required_permission}>
                                {({ id }) => <Input id={id} value={form.data.required_permission} maxLength={120} onChange={(e) => form.setData('required_permission', e.target.value)} />}
                            </FormField>
                            <FormField id="kd-organization" label={t('intelligenceKnowledge.documents.create.organization')} description={t('intelligenceKnowledge.documents.create.organizationHelp')} error={form.errors.organization_id} className="md:col-span-3">
                                {({ id }) => <Input id={id} value={form.data.organization_id} maxLength={36} onChange={(e) => form.setData('organization_id', e.target.value)} />}
                            </FormField>
                            <FormField id="kd-body" label={t('intelligenceKnowledge.documents.create.body')} description={t('intelligenceKnowledge.documents.create.bodyHelp')} required error={form.errors.body} className="md:col-span-3">
                                {({ id }) => <Textarea id={id} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className="min-h-48 font-mono text-xs" />}
                            </FormField>
                        </div>
                        <div className="flex justify-end">
                            <Button type="submit" loading={form.processing}>{t('intelligenceKnowledge.documents.create.submit')}</Button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
