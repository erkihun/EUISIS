import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import { StatusBadge } from '@/Components/letters/LetterBadges';
import { inputCls, labelCls, panelCls, primaryBtn, secondaryBtn, useLetters } from '@/Components/letters/helpers';
import { ListPagination, TBody, Table, Td, Th, THead, Tr, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Paginated, TemplateRow } from '@/types/letters';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Props {
    templates: Paginated<TemplateRow>;
    filters: { q: string; status: string };
    can: { create: boolean };
}

/** The template catalogue: platform templates first, then those of the institutions in scope. */
export default function TemplatesIndex({ templates, filters, can }: Props): JSX.Element {
    const { lt, locale, pick } = useLetters();
    const [form, setForm] = useState(filters);

    function apply(event: FormEvent) {
        event.preventDefault();
        router.get(route('letters.templates.index'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), { preserveState: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('templates.title')} description={lt('templates.description')} backHref={route('letters.dashboard')}
            actions={can.create ? <Link className={primaryBtn} href={route('letters.templates.create')}>{lt('templates.create')}</Link> : undefined} />}>
            <Head title={lt('templates.title')} />
            <ActionErrors />
            <div className="space-y-4">
                <form onSubmit={apply} className={cx(panelCls, 'grid gap-3 p-4 sm:grid-cols-4')} aria-label={lt('common.filter')}>
                    <div className="sm:col-span-2">
                        <label htmlFor="tpl-q" className={labelCls}>{lt('common.search')}</label>
                        <input id="tpl-q" type="search" className={inputCls} value={form.q} placeholder={lt('templates.searchPlaceholder')} onChange={(e) => setForm({ ...form, q: e.target.value })} />
                    </div>
                    <div>
                        <label htmlFor="tpl-status" className={labelCls}>{lt('common.status')}</label>
                        <select id="tpl-status" className={inputCls} value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                            <option value="">{lt('common.all')}</option>
                            {['draft', 'published', 'archived'].map((s) => <option key={s} value={s}>{lt(`templateStatus.${s}`)}</option>)}
                        </select>
                    </div>
                    <div className="flex items-end gap-2">
                        <button type="submit" className={primaryBtn}>{lt('common.filter')}</button>
                        <button type="button" className={secondaryBtn} onClick={() => router.get(route('letters.templates.index'))}>{lt('common.reset')}</button>
                    </div>
                </form>

                {templates.data.length === 0 ? <p className={cx(panelCls, 'p-4 text-sm text-(--app-muted-foreground)')}>{lt('templates.empty')}</p> : (
                    <div className={cx(panelCls, 'overflow-x-auto')}>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{lt('templates.code')}</Th>
                                    <Th>{lt('common.title')}</Th>
                                    <Th>{lt('templates.owner')}</Th>
                                    <Th>{lt('common.category')}</Th>
                                    <Th>{lt('common.language')}</Th>
                                    <Th>{lt('common.version')}</Th>
                                    <Th>{lt('common.status')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {templates.data.map((t) => (
                                    <Tr key={t.id}>
                                        <Td className="font-medium"><Link className="underline" href={route('letters.templates.show', t.id)}>{t.code}</Link></Td>
                                        <Td>{locale === 'am' && t.title_am ? t.title_am : t.title_en}</Td>
                                        <Td>{t.platform ? lt('templates.platform') : pick(t.owner)}</Td>
                                        <Td>{lt(`templateCategory.${t.category}`)}</Td>
                                        <Td>{lt(`language.${t.language}`)}</Td>
                                        <Td>{t.current_version_no > 0 ? `v${t.current_version_no}` : '—'}</Td>
                                        <Td><StatusBadge kind="templateStatus" value={t.status} /></Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                )}
                <ListPagination meta={templates} />
            </div>
        </AuthenticatedLayout>
    );
}
