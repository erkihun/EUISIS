import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { Alert, Button, Card, TBody, THead, Table, TableCard, Td, Th, Tr } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

export type QuestionnaireStatus = 'draft' | 'published' | 'retired';

type Version = {
    id: string;
    version_no: number;
    status: QuestionnaireStatus;
    name_en: string;
    name_am: string | null;
    question_count: number;
    submission_count: number;
    published_at: string | null;
    retired_at: string | null;
    updated_at: string | null;
};

const BADGE: Record<QuestionnaireStatus, string> = { draft: 'draft', published: 'active', retired: 'inactive' };

/**
 * The versions of the organization feedback questionnaire: the one the
 * public answers now, the draft being prepared, and the withdrawn ones that
 * older submissions still point at.
 */
export default function QuestionnaireIndex({ versions, hasDraft }: { versions: Version[]; hasDraft: boolean }) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const draft = versions.find((version) => version.status === 'draft');
    const name = (version: Version) => (locale === 'am' && version.name_am ? version.name_am : version.name_en);

    function newDraft() {
        router.post(route('service-feedback.questionnaire.store'));
    }

    async function publish(version: Version) {
        const result = await confirm({ title: t('serviceFeedback.questionnairePublishConfirm'), variant: 'warning' });
        if (result.confirmed) router.post(route('service-feedback.questionnaire.publish', version.id), {}, { preserveScroll: true });
    }

    async function retire(version: Version) {
        const result = await confirm({ title: t('serviceFeedback.questionnaireRetireConfirm'), variant: 'danger' });
        if (result.confirmed) router.post(route('service-feedback.questionnaire.retire', version.id), {}, { preserveScroll: true });
    }

    async function destroy(version: Version) {
        const result = await confirm({ title: t('serviceFeedback.questionnaireDeleteConfirm'), variant: 'danger' });
        if (result.confirmed) router.delete(route('service-feedback.questionnaire.destroy', version.id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('serviceFeedback.questionnaireTitle')}
                    description={t('serviceFeedback.questionnaireIntro')}
                    actions={hasDraft && draft
                        ? <Button as={Link} href={route('service-feedback.questionnaire.edit', draft.id)} variant="primary">{t('serviceFeedback.questionnaireOpenDraft')}</Button>
                        : <Button type="button" variant="primary" onClick={newDraft}>{t('serviceFeedback.questionnaireNewDraft')}</Button>}
                />
            }
        >
            <Head title={t('serviceFeedback.questionnaireTitle')} />

            <div className="space-y-4">
                <Alert tone="info">{t('serviceFeedback.questionnaireTemplateNote')}</Alert>

                {versions.length === 0 ? (
                    <Card><EmptyState title={t('serviceFeedback.questionnaireEmpty')} /></Card>
                ) : (
                    <TableCard>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('serviceFeedback.questionnaireName')}</Th>
                                    <Th>{t('serviceFeedback.filterStatus')}</Th>
                                    <Th align="right">{t('serviceFeedback.questionnaireQuestions')}</Th>
                                    <Th align="right">{t('serviceFeedback.questionnaireSubmissions')}</Th>
                                    <Th>{t('serviceFeedback.questionnairePublishedAt')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {versions.map((version) => (
                                    <Tr key={version.id}>
                                        <Td>
                                            <Link href={route('service-feedback.questionnaire.edit', version.id)} className="font-medium text-(--app-foreground) hover:underline">{name(version)}</Link>
                                            <div className="text-xs text-(--app-muted-foreground)">{t('serviceFeedback.questionnaireVersionN').replace('{n}', String(version.version_no))}</div>
                                        </Td>
                                        <Td><StatusBadge status={BADGE[version.status]} label={t(`serviceFeedback.questionnaireStatus_${version.status}`)} /></Td>
                                        <Td align="right">{version.question_count}</Td>
                                        <Td align="right">{version.submission_count}</Td>
                                        <Td>{version.published_at ? <LocalizedDateDisplay value={version.published_at} /> : '—'}</Td>
                                        <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t(version.status === 'draft' ? 'common.edit' : 'serviceFeedback.questionnaireView'), href: route('service-feedback.questionnaire.edit', version.id) },
                                                        ...(version.status === 'draft' ? [
                                                            { label: t('serviceFeedback.questionnairePublish'), onClick: () => publish(version) },
                                                            { label: t('serviceFeedback.questionnaireDeleteDraft'), onClick: () => destroy(version), variant: 'danger' as const },
                                                        ] : []),
                                                        ...(version.status === 'published' ? [
                                                            { label: t('serviceFeedback.questionnaireRetire'), onClick: () => retire(version), variant: 'danger' as const },
                                                        ] : []),
                                                    ]}
                                                />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </TableCard>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
