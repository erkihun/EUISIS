import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors } from '@/modules/reporting/components/Notices';
import { badge, mutedCls, panelCls, primaryBtn, secondaryBtn, useReporting } from '@/modules/reporting/lib/helpers';
import type { Bilingual } from '@/modules/reporting/types';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

interface TypeRow extends Bilingual {
    id: string;
    code: string;
    frequency: string;
    submission_mode: string;
    confidentiality: string;
    is_active: boolean;
    allow_nil_report: boolean;
    published_version: number | null;
    has_draft: boolean;
}

/** Report types and the state of their templates. */
export default function Types({ types }: { types: TypeRow[] }): JSX.Element {
    const { rt, name } = useReporting();

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('types.title')} description={rt('types.description')} actions={<Link href={route('reporting.types.create')} className={primaryBtn}>{rt('types.add')}</Link>} />}>
            <Head title={rt('types.title')} />
            <ActionErrors />
            <div className="pb-4">
                {types.length === 0 ? <EmptyState title={rt('types.emptyTitle')} description={rt('types.emptyDescription')} action={<Link href={route('reporting.types.create')} className={primaryBtn}>{rt('types.add')}</Link>} /> : (
                    <ul className="space-y-3">
                        {types.map((type) => (
                            <li key={type.id} className={cx(panelCls, 'flex flex-wrap items-center justify-between gap-3 p-4')}>
                                <div className="min-w-0">
                                    <Link href={route('reporting.types.edit', type.id)} className="text-base font-semibold text-primary hover:underline">{name(type)}</Link>
                                    <p className={cx(mutedCls, 'text-xs')}>{type.code} · {rt(`frequency.${type.frequency}`)} · {rt(`types.mode.${type.submission_mode}`)}</p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {type.published_version === null
                                        ? <span className={badge('warning')}>{rt('types.notPublished')}</span>
                                        : <span className={badge('success')}>{rt('types.publishedVersion', { n: type.published_version })}</span>}
                                    {type.has_draft && type.published_version !== null && <span className={badge('info')}>{rt('types.draftPending')}</span>}
                                    {type.confidentiality === 'confidential' && <span className={badge('warning')}>{rt('types.confidential')}</span>}
                                    {!type.is_active && <span className={badge('neutral')}>{rt('common.inactive')}</span>}
                                    <a href={route('reporting.types.template.document', type.id)} className={secondaryBtn}>{rt('types.downloadTemplate')}</a>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
