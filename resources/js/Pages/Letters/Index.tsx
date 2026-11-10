import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import LetterTable from '@/Components/letters/LetterTable';
import { inputCls, labelCls, panelCls, primaryBtn, secondaryBtn, useLetters } from '@/Components/letters/helpers';
import { AppTabNav, ListPagination, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { FileClassOption, LetterRow, OrganizationOption, Paginated } from '@/types/letters';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type Filters = { tab: string; direction: string; status: string; organization_id: string; q: string; from: string; to: string; file_class_id: string };

interface Props {
    rows: Paginated<LetterRow>;
    filters: Filters;
    organizations: OrganizationOption[];
    directions: string[];
    registration_statuses: string[];
    letter_statuses: string[];
    file_classes: FileClassOption[];
    can: { register: boolean; compose: boolean; export: boolean };
}

/** The registry of every institution in scope, with the inbox of letters awaiting receipt, my routings and the drafts. */
export default function Index({ rows, filters, organizations, directions, registration_statuses, letter_statuses, file_classes, can }: Props): JSX.Element {
    const { lt, pick, locale } = useLetters();
    const [form, setForm] = useState<Filters>(filters);
    const tab = filters.tab || 'registry';
    const statuses = tab === 'drafts' ? letter_statuses.filter((s) => s !== 'dispatched' && s !== 'received') : registration_statuses.filter((s) => s !== 'pending_receipt');

    const tabs = [
        { key: 'registry', label: lt('index.tabs.registry'), href: route('letters.index') },
        { key: 'inbox', label: lt('index.tabs.inbox'), href: route('letters.index', { tab: 'inbox' }) },
        { key: 'mine', label: lt('index.tabs.mine'), href: route('letters.index', { tab: 'mine' }) },
        ...(can.compose ? [{ key: 'drafts', label: lt('index.tabs.drafts'), href: route('letters.index', { tab: 'drafts' }) }] : []),
    ];

    function apply(event: FormEvent) {
        event.preventDefault();
        const params = Object.fromEntries(Object.entries({ ...form, tab }).filter(([, v]) => v !== '' && v !== null));
        router.get(route('letters.index'), params, { preserveState: true, preserveScroll: true });
    }

    function reset() {
        router.get(route('letters.index'), tab === 'registry' ? {} : { tab }, { preserveState: false });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('index.title')} description={lt('index.description')}
            actions={(can.register || can.compose || can.export) ? (
                <div className="flex flex-wrap gap-2">
                    {can.export && tab === 'registry' && <a className={secondaryBtn} href={route('letters.export', Object.fromEntries(Object.entries(filters).filter(([k, v]) => k !== 'tab' && v !== '')))}>{lt('index.export')}</a>}
                    {can.register && <Link className={secondaryBtn} href={route('letters.register')}>{lt('nav.register')}</Link>}
                    {can.compose && <Link className={primaryBtn} href={route('letters.compose')}>{lt('nav.compose')}</Link>}
                </div>
            ) : undefined} />}>
            <Head title={lt('index.title')} />
            <ActionErrors />
            <div className="space-y-4">
                <AppTabNav items={tabs} current={tab} label={lt('index.title')} />

                {tab !== 'mine' && (
                    <form onSubmit={apply} className={cx(panelCls, 'grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6')} aria-label={lt('common.filter')}>
                        <div className="lg:col-span-2">
                            <label htmlFor="lt-q" className={labelCls}>{lt('common.search')}</label>
                            <input id="lt-q" type="search" className={inputCls} value={form.q} placeholder={lt('index.searchPlaceholder')} onChange={(e) => setForm({ ...form, q: e.target.value })} />
                        </div>
                        {tab === 'registry' && (
                            <div>
                                <label htmlFor="lt-dir" className={labelCls}>{lt('common.direction')}</label>
                                <select id="lt-dir" className={inputCls} value={form.direction} onChange={(e) => setForm({ ...form, direction: e.target.value })}>
                                    <option value="">{lt('common.all')}</option>
                                    {directions.map((d) => <option key={d} value={d}>{lt(`direction.${d}`)}</option>)}
                                </select>
                            </div>
                        )}
                        {tab !== 'inbox' && (
                            <div>
                                <label htmlFor="lt-status" className={labelCls}>{lt('common.status')}</label>
                                <select id="lt-status" className={inputCls} value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                                    <option value="">{lt('common.all')}</option>
                                    {statuses.map((s) => <option key={s} value={s}>{lt(`${tab === 'drafts' ? 'status' : 'registrationStatus'}.${s}`)}</option>)}
                                </select>
                            </div>
                        )}
                        {organizations.length > 1 && (
                            <div>
                                <label htmlFor="lt-org" className={labelCls}>{lt('common.institution')}</label>
                                <select id="lt-org" className={inputCls} value={form.organization_id} onChange={(e) => setForm({ ...form, organization_id: e.target.value })}>
                                    <option value="">{lt('common.all')}</option>
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                                </select>
                            </div>
                        )}
                        {tab === 'registry' && file_classes.length > 0 && (
                            <div>
                                <label htmlFor="lt-fc" className={labelCls}>{lt('show.fileClass')}</label>
                                <select id="lt-fc" className={inputCls} value={form.file_class_id} onChange={(e) => setForm({ ...form, file_class_id: e.target.value })}>
                                    <option value="">{lt('common.all')}</option>
                                    {file_classes.map((c) => <option key={c.id} value={c.id}>{c.code} · {(locale === 'am' ? c.title_am ?? c.title_en : c.title_en ?? c.title_am) ?? ''}</option>)}
                                </select>
                            </div>
                        )}
                        {tab === 'registry' && (
                            <>
                                <div>
                                    <label htmlFor="lt-from" className={labelCls}>{lt('common.from')}</label>
                                    <LocalizedDatePicker id="lt-from" className={inputCls} value={form.from} onChange={(iso) => setForm({ ...form, from: iso })} />
                                </div>
                                <div>
                                    <label htmlFor="lt-to" className={labelCls}>{lt('common.to')}</label>
                                    <LocalizedDatePicker id="lt-to" className={inputCls} value={form.to} onChange={(iso) => setForm({ ...form, to: iso })} />
                                </div>
                            </>
                        )}
                        <div className="flex items-end gap-2">
                            <button type="submit" className={primaryBtn}>{lt('common.filter')}</button>
                            <button type="button" className={secondaryBtn} onClick={reset}>{lt('common.reset')}</button>
                        </div>
                    </form>
                )}

                <LetterTable rows={rows.data} showInstitution={organizations.length !== 1} empty={lt(`index.empty.${tab}`)} />
                <ListPagination meta={rows} />
            </div>
        </AuthenticatedLayout>
    );
}
