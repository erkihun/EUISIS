import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { useConfirm } from '@/Components/ConfirmProvider';
import { Alert, Button, Card, CardDescription, CardHeader, CardTitle, FormField, Input, Table, TableCard, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import FormRenderer from '@/Components/serviceMonitoring/builder/FormRenderer';
import type { BuiltInSection, OfficialWording, TemplateContentData } from '@/Components/serviceMonitoring/builder/TemplateEditor';
import { nameOf } from '@/Components/serviceMonitoring/builder/helpers';
import type { Named } from '@/Components/serviceMonitoring/builder/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent, JSX, ReactNode } from 'react';

type Status = 'draft' | 'published' | 'superseded' | 'archived';

type Props = {
    template: {
        id: string;
        code: string;
        version: number;
        name_am: string;
        name_en: string | null;
        description: string | null;
        status: Status;
        content: TemplateContentData;
        organization_types: Named[];
    };
    versions: Array<{ id: string; version: number; status: Status; published_at: string | null; updated_at: string | null; submissions_count: number }>;
    problems: string[];
    official: OfficialWording;
    can: { update: boolean; publish: boolean; archive: boolean; delete: boolean; new_version: boolean };
};

const SECTIONS: BuiltInSection[] = ['1', '2', '3', '4'];

/** One version of a form template: publishing checks, versions, a preview, and its lifecycle actions. */
export default function FormTemplateShow({ template, versions, problems, official, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const tt = (key: string) => t(`serviceMonitoring.templates.${key}`);
    const { confirm } = useConfirm();
    const duplicate = useForm({ code: '', name_am: '' });
    const content = template.content;
    const title = `${nameOf(template, locale)} — v${template.version}`;

    async function act(routeName: string, method: 'post' | 'delete', labelKey: string, variant: 'default' | 'warning' | 'danger' = 'default') {
        const { confirmed } = await confirm({ title: tt(labelKey), description: nameOf(template, locale), variant });
        if (!confirmed) return;
        if (method === 'delete') router.delete(route(routeName, template.id));
        else router.post(route(routeName, template.id), {}, { preserveScroll: true });
    }

    function submitDuplicate(event: FormEvent) {
        event.preventDefault();
        duplicate.post(route('service-monitoring.forms.duplicate', template.id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={template.code} backHref={route('service-monitoring.forms.index')} actions={<>
            {can.delete && <Button variant="destructiveOutline" onClick={() => act('service-monitoring.forms.destroy', 'delete', 'actions.discard', 'danger')}>{tt('actions.discard')}</Button>}
            {can.archive && <Button variant="outline" onClick={() => act('service-monitoring.forms.archive', 'post', 'actions.archive', 'warning')}>{tt('actions.archive')}</Button>}
            {template.status !== 'draft' && <Button as={Link} href={route('service-monitoring.forms.summary', template.id)} variant="outline">{tt('actions.summary')}</Button>}
            {can.new_version && <Button variant="secondary" onClick={() => act('service-monitoring.forms.new-version', 'post', 'actions.newVersion')}>{tt('actions.newVersion')}</Button>}
            {can.update && <Button as={Link} href={route('service-monitoring.forms.edit', template.id)} variant="secondary">{tt('actions.edit')}</Button>}
            {can.publish && <Button variant="primary" disabled={problems.length > 0} onClick={() => act('service-monitoring.forms.publish', 'post', 'actions.publish')}>{tt('actions.publish')}</Button>}
        </>} />}>
            <Head title={title} />
            <div className="space-y-6">
                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="space-y-3 p-4 lg:col-span-2">
                        <CardHeader className="p-0"><CardTitle>{tt('details')}</CardTitle></CardHeader>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <Detail label={tt('status')}><StatusBadge status={template.status} label={tt(`statuses.${template.status}`)} /></Detail>
                            <Detail label={tt('version')}>{template.version}</Detail>
                            <Detail label={tt('organizationTypes')} wide>
                                {template.organization_types.length === 0 ? tt('noTypes') : template.organization_types.map((type) => nameOf(type, locale)).join('، ')}
                            </Detail>
                            {template.description && <Detail label={tt('descriptionLabel')} wide><span className="whitespace-pre-line">{template.description}</span></Detail>}
                        </dl>
                        {template.status === 'draft' && (problems.length > 0
                            ? <Alert tone="warning" title={tt('problems')}><ul className="list-disc space-y-0.5 ps-5">{problems.map((problem, i) => <li key={i}>{problem}</li>)}</ul></Alert>
                            : <Alert tone="success">{tt('ready')}</Alert>)}
                    </Card>

                    <Card className="space-y-3 p-4">
                        <CardHeader className="p-0">
                            <CardTitle>{tt('duplicateTitle')}</CardTitle>
                            <CardDescription>{tt('duplicateHelp')}</CardDescription>
                        </CardHeader>
                        <form onSubmit={submitDuplicate} className="space-y-3">
                            <FormField label={tt('newCode')} required error={duplicate.errors.code}>
                                {({ id }) => <Input id={id} className="w-full" maxLength={64} value={duplicate.data.code} onChange={(e) => duplicate.setData('code', e.target.value.toUpperCase())} />}
                            </FormField>
                            <FormField label={tt('newName')} required error={duplicate.errors.name_am}>
                                {({ id }) => <Input id={id} className="w-full" maxLength={255} value={duplicate.data.name_am} onChange={(e) => duplicate.setData('name_am', e.target.value)} />}
                            </FormField>
                            <Button type="submit" variant="outline" loading={duplicate.processing}>{tt('actions.duplicate')}</Button>
                        </form>
                    </Card>
                </div>

                <TableCard title={tt('versions')}>
                    <div className="overflow-x-auto">
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{tt('version')}</Th>
                                    <Th>{tt('status')}</Th>
                                    <Th align="right">{tt('reports')}</Th>
                                    <Th>{tt('updated')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {versions.map((version) => (
                                    <Tr key={version.id}>
                                        <Td>{version.id === template.id ? <strong>v{version.version}</strong> : <Link href={route('service-monitoring.forms.show', version.id)} className="text-(--color-primary) hover:underline">v{version.version}</Link>}</Td>
                                        <Td><StatusBadge status={version.status} label={tt(`statuses.${version.status}`)} /></Td>
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
                        <CardTitle>{tt('preview')}</CardTitle>
                        <CardDescription>{tt('previewHelp')}</CardDescription>
                    </CardHeader>
                    <p className="text-base font-semibold text-(--app-foreground)">{content.title || official.title}</p>
                    <ol className="space-y-2">
                        {SECTIONS.filter((n) => content.sections[n]?.enabled ?? true).map((n) => (
                            <li key={n} className="rounded-card border border-(--app-border) p-3">
                                <p className="text-sm font-semibold text-(--app-foreground)">{content.sections[n]?.title || official.sections[n].title}</p>
                                <p className="text-xs text-(--app-muted-foreground)">{tt('officialSectionPreview')}</p>
                                {(content.sections[n]?.notes ?? official.sections[n].notes).map((note, i) => <p key={i} className="mt-1 text-xs text-(--app-muted-foreground)">{note}</p>)}
                            </li>
                        ))}
                    </ol>
                    {content.custom.sections.length > 0 && <FormRenderer schema={content.custom} data={{}} />}
                    <details className="text-sm">
                        <summary className="cursor-pointer font-medium">{tt('generalNotes')}</summary>
                        <ol className="mt-2 list-decimal space-y-1 ps-5 text-(--app-muted-foreground)">{(content.general_notes ?? official.general_notes).map((note, i) => <li key={i}>{note}</li>)}</ol>
                    </details>
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
