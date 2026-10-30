import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { useConfirm } from '@/Components/ConfirmProvider';
import { Alert, Button, Card, CardDescription, CardHeader, CardTitle, FormField, Input, Table, TableCard, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import FormRenderer from '@/Components/serviceMonitoring/FormRenderer';
import { nameOf } from '@/Components/serviceMonitoring/helpers';
import type { FormSchema, Named } from '@/Components/serviceMonitoring/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent, JSX, ReactNode } from 'react';

type Status = 'draft' | 'published' | 'superseded' | 'archived';

type Props = {
    form: {
        id: string;
        code: string;
        version: number;
        name_am: string;
        name_en: string | null;
        description: string | null;
        status: Status;
        published_at: string | null;
        schema: FormSchema;
        organization_types: Named[];
    };
    versions: Array<{ id: string; version: number; status: Status; published_at: string | null; updated_at: string | null; submissions_count: number }>;
    problems: Record<string, string>;
    can: { update: boolean; publish: boolean; archive: boolean; delete: boolean; newVersion: boolean };
};

/** One version of a monitoring form: its details, publishing checks, versions and a preview. */
export default function ServiceMonitoringFormShow({ form, versions, problems, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const duplicate = useForm({ code: '', name_am: '' });
    const problemList = Object.values(problems);
    const title = `${nameOf(form, locale)} — v${form.version}`;

    async function act(routeName: string, method: 'post' | 'delete', titleKey: string, variant: 'default' | 'warning' | 'danger' = 'default') {
        const { confirmed } = await confirm({ title: t(titleKey), description: nameOf(form, locale), variant });
        if (!confirmed) return;
        if (method === 'delete') router.delete(route(routeName, form.id));
        else router.post(route(routeName, form.id), {}, { preserveScroll: true });
    }

    function submitDuplicate(event: FormEvent) {
        event.preventDefault();
        duplicate.post(route('service-monitoring.forms.duplicate', form.id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={form.code} backHref={route('service-monitoring.forms.index')} actions={<>
            {can.delete && <Button variant="destructiveOutline" onClick={() => act('service-monitoring.forms.destroy', 'delete', 'serviceMonitoring.actions.discard', 'danger')}>{t('serviceMonitoring.actions.discard')}</Button>}
            {can.archive && form.status !== 'archived' && <Button variant="outline" onClick={() => act('service-monitoring.forms.archive', 'post', 'serviceMonitoring.actions.archive', 'warning')}>{t('serviceMonitoring.actions.archive')}</Button>}
            {can.newVersion && <Button variant="secondary" onClick={() => act('service-monitoring.forms.new-version', 'post', 'serviceMonitoring.actions.newVersion')}>{t('serviceMonitoring.actions.newVersion')}</Button>}
            {can.update && <Button as={Link} href={route('service-monitoring.forms.edit', form.id)} variant="secondary">{t('serviceMonitoring.actions.edit')}</Button>}
            {can.publish && <Button variant="primary" disabled={problemList.length > 0} onClick={() => act('service-monitoring.forms.publish', 'post', 'serviceMonitoring.actions.publish')}>{t('serviceMonitoring.actions.publish')}</Button>}
        </>} />}>
            <Head title={title} />
            <div className="space-y-6">
                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="space-y-3 p-4 lg:col-span-2">
                        <CardHeader className="p-0"><CardTitle>{t('serviceMonitoring.forms.details')}</CardTitle></CardHeader>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <Detail label={t('serviceMonitoring.forms.status')}><StatusBadge status={form.status} label={t(`serviceMonitoring.formStatus.${form.status}`)} /></Detail>
                            <Detail label={t('serviceMonitoring.forms.version')}>{form.version}</Detail>
                            <Detail label={t('serviceMonitoring.forms.organizationTypes')} wide>
                                {form.organization_types.length === 0 ? t('serviceMonitoring.forms.noTypes') : form.organization_types.map((type) => nameOf(type, locale)).join('، ')}
                            </Detail>
                            {form.description && <Detail label={t('serviceMonitoring.forms.descriptionLabel')} wide><span className="whitespace-pre-line">{form.description}</span></Detail>}
                        </dl>
                        {form.status === 'draft' && (problemList.length > 0
                            ? <Alert tone="warning" title={t('serviceMonitoring.forms.problems')}><ul className="list-disc space-y-0.5 ps-5">{problemList.map((problem, i) => <li key={i}>{problem}</li>)}</ul></Alert>
                            : <Alert tone="success">{t('serviceMonitoring.forms.ready')}</Alert>)}
                    </Card>

                    <Card className="space-y-3 p-4">
                        <CardHeader className="p-0">
                            <CardTitle>{t('serviceMonitoring.forms.duplicateTitle')}</CardTitle>
                            <CardDescription>{t('serviceMonitoring.forms.duplicateHelp')}</CardDescription>
                        </CardHeader>
                        <form onSubmit={submitDuplicate} className="space-y-3">
                            <FormField label={t('serviceMonitoring.forms.newCode')} required error={duplicate.errors.code}>
                                {({ id }) => <Input id={id} className="w-full" maxLength={64} value={duplicate.data.code} onChange={(e) => duplicate.setData('code', e.target.value.toUpperCase())} />}
                            </FormField>
                            <FormField label={t('serviceMonitoring.forms.newName')} required error={duplicate.errors.name_am}>
                                {({ id }) => <Input id={id} className="w-full" maxLength={255} value={duplicate.data.name_am} onChange={(e) => duplicate.setData('name_am', e.target.value)} />}
                            </FormField>
                            <Button type="submit" variant="outline" loading={duplicate.processing}>{t('serviceMonitoring.actions.duplicate')}</Button>
                        </form>
                    </Card>
                </div>

                <TableCard title={t('serviceMonitoring.forms.versions')}>
                    <div className="overflow-x-auto">
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('serviceMonitoring.forms.version')}</Th>
                                    <Th>{t('serviceMonitoring.forms.status')}</Th>
                                    <Th align="right">{t('serviceMonitoring.forms.reports')}</Th>
                                    <Th>{t('serviceMonitoring.forms.updated')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {versions.map((version) => (
                                    <Tr key={version.id}>
                                        <Td>{version.id === form.id ? <strong>v{version.version}</strong> : <Link href={route('service-monitoring.forms.show', version.id)} className="text-(--color-primary) hover:underline">v{version.version}</Link>}</Td>
                                        <Td><StatusBadge status={version.status} label={t(`serviceMonitoring.formStatus.${version.status}`)} /></Td>
                                        <Td numeric>{version.submissions_count}</Td>
                                        <Td muted><LocalizedDateDisplay value={version.published_at ?? version.updated_at} /></Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                </TableCard>

                <Card className="space-y-4 p-4">
                    <CardHeader className="p-0">
                        <CardTitle>{t('serviceMonitoring.forms.preview')}</CardTitle>
                        <CardDescription>{t('serviceMonitoring.forms.previewHelp')}</CardDescription>
                    </CardHeader>
                    <FormRenderer schema={form.schema} data={{}} />
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function Detail({ label, wide = false, children }: { label: string; wide?: boolean; children: ReactNode }): JSX.Element {
    return (
        <div className={wide ? 'sm:col-span-2' : undefined}>
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 text-(--app-foreground)">{children}</dd>
        </div>
    );
}
