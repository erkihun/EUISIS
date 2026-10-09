import Button from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import SettingField from '@/Components/settings/SettingField';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import type { SettingsField } from '@/lib/settings';
import { Head, Link, router, useForm, useRemember } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';

type Scalar = string | number | boolean | null;
type Values = Record<string, Scalar | Record<string, Scalar>>;
type Field = { key: string; type: string; required?: boolean; max?: number };
type Section = { key: string; definition: { fields: string[]; options: Record<string, string[]>; hideable: boolean; sortable: boolean }; values: Values };
type RecordRow = Record<string, Scalar> & { id: string; status?: string };
type Props = {
    tabs: string[]; tab: string; search: string; sections: Section[]; fields: Field[];
    records: { data: RecordRow[]; total: number; current_page: number; last_page: number } | null;
    can: Record<string, boolean>; settingsFields: SettingsField[]; publicRoutes: Record<string, string>;
};
const surface = 'min-w-0 rounded-card border border-gray-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 sm:p-5';
const input = 'w-full min-w-0 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] disabled:opacity-60 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100';

function useUnsavedChanges(dirty: boolean) {
    const { t } = useLocale();
    useEffect(() => {
        if (!dirty) return;
        const unload = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            if (event.detail.visit.method.toLowerCase() === 'get' && !window.confirm(t('publicSite.admin.discard'))) event.preventDefault();
        });
        return () => { remove(); window.removeEventListener('beforeunload', unload); };
    }, [dirty, t]);
}

function EditorField({ field, value, error, onChange, routes }: { field: Field; value: Scalar; error?: string; onChange: (value: Scalar) => void; routes: Record<string, string> }) {
    const { t } = useLocale();
    const id = useId();
    const base = field.key.replace(/_(en|am)$/, '');
    const language = field.key.endsWith('_am') ? ' (አማርኛ)' : field.key.endsWith('_en') ? ' (English)' : '';
    const label = t(`publicSite.admin.fields.${base}`) + language;
    const shared = { id, 'aria-invalid': Boolean(error), 'aria-describedby': error ? `${id}-error` : undefined };
    return <div className="min-w-0 space-y-1.5">
        {field.type === 'boolean' ? <label className="flex min-h-11 items-center gap-2 text-sm" htmlFor={id}>
            <input {...shared} type="checkbox" checked={Boolean(value)} onChange={e => onChange(e.target.checked)} className="rounded border-gray-300" />{label}
        </label> : <>
            <label htmlFor={id} className="block text-sm font-medium">{label}{field.required && <span aria-hidden="true"> *</span>}</label>
            {field.type === 'route' ? <select {...shared} className={input} value={String(value ?? '')} onChange={e => onChange(e.target.value)}>
                <option value="">{t('publicSite.admin.none')}</option>
                {Object.entries(routes).map(([name, key]) => <option key={name} value={name}>{t(key)}</option>)}
            </select> : field.type === 'textarea' ? <textarea {...shared} className={input} rows={field.max && field.max > 500 ? 5 : 3} required={field.required} maxLength={field.max} value={String(value ?? '')} onChange={e => onChange(e.target.value)} />
                : <input {...shared} className={input} type={field.type === 'number' ? 'number' : 'text'} min={field.type === 'number' ? 0 : undefined} max={field.type === 'number' ? field.max : undefined} maxLength={field.type === 'number' ? undefined : field.max} required={field.required} value={String(value ?? '')} onChange={e => onChange(e.target.value)} />}
        </>}
        {error && <p id={`${id}-error`} className="text-sm text-red-700 dark:text-red-300">{error}</p>}
    </div>;
}

function SaveState({ processing, saved, errors }: { processing: boolean; saved: boolean; errors: Record<string, string> }) {
    const { t } = useLocale();
    return <div className="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-slate-700">
        <Button type="submit" disabled={processing}>{t(processing ? 'common.loading' : 'common.save')}</Button>
        <span role="status" className="text-sm text-gray-600 dark:text-slate-300">{saved ? t('publicSite.admin.saved') : ''}</span>
        {Object.keys(errors).length > 0 && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{t('publicSite.admin.fixErrors')}</p>}
    </div>;
}

function SectionEditor({ section, page, editable, routes }: { section: Section; page: string; editable: boolean; routes: Record<string, string> }) {
    const { t } = useLocale();
    const options = Object.fromEntries(Object.keys(section.definition.options).map(key => [key, (section.values.options as Record<string, Scalar> | undefined)?.[key] ?? (key === 'max_items' ? 4 : '')]));
    const initial: Values = { is_visible: section.values.is_visible ?? true };
    for (const field of section.definition.fields) for (const lang of ['en', 'am']) initial[`${field}_${lang}`] = section.values[`${field}_${lang}`] ?? '';
    if (section.definition.sortable) initial.sort_order = section.values.sort_order ?? 0;
    if (Object.keys(options).length) initial.options = options;
    const form = useForm<Values>(`public-site:section:${page}:${section.key}`, initial);
    useUnsavedChanges(form.isDirty);
    return <details className={surface}>
        <summary className="cursor-pointer text-base font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4">{t(`publicSite.admin.sections.${section.key}`)}</summary>
        <form className="mt-4 space-y-4" onSubmit={e => {
            e.preventDefault(); form.put(route('public-site-management.section', { page, section: section.key }), { preserveScroll: true, onSuccess: () => form.setDefaults(form.data) });
        }}>
            <fieldset disabled={!editable || form.processing} className="grid min-w-0 gap-4 md:grid-cols-2">
                {section.definition.fields.flatMap(field => ['en', 'am'].map(lang => {
                    const key = `${field}_${lang}`;
                    return <EditorField key={key} field={{ key, type: field === 'body' ? 'textarea' : 'text', max: field === 'body' ? 20000 : field === 'subtitle' ? 500 : 255 }} value={form.data[key] as Scalar} error={form.errors[key]} onChange={value => form.setData(key, value)} routes={routes} />;
                }))}
                {Object.keys(options).map(key => <EditorField key={key} field={{ key, type: key.endsWith('_route') ? 'route' : key === 'max_items' ? 'number' : 'text', max: key === 'max_items' ? (section.key === 'latest_announcements' ? 10 : 12) : 60, required: key === 'max_items' }} value={(form.data.options as Record<string, Scalar>)[key]} error={form.errors[`options.${key}`]} onChange={value => form.setData('options', { ...(form.data.options as Record<string, Scalar>), [key]: value })} routes={routes} />)}
                {section.definition.hideable && <EditorField field={{ key: 'is_visible', type: 'boolean' }} value={form.data.is_visible as Scalar} onChange={value => form.setData('is_visible', value)} routes={routes} />}
                {section.definition.sortable && <EditorField field={{ key: 'sort_order', type: 'number', max: 10000, required: true }} value={form.data.sort_order as Scalar} onChange={value => form.setData('sort_order', value)} routes={routes} />}
            </fieldset>
            {editable && <SaveState processing={form.processing} saved={form.recentlySuccessful} errors={form.errors} />}
        </form>
    </details>;
}

function RecordEditor({ row, fields, kind, routes, close, session }: { row: RecordRow | null; fields: Field[]; kind: string; routes: Record<string, string>; close: () => void; session: number }) {
    const { t } = useLocale();
    const form = useForm<Record<string, Scalar>>(`public-site:record:${kind}:${row?.id ?? 'new'}:${session}`, Object.fromEntries(fields.map(field => [field.key, row?.[field.key] ?? (field.type === 'boolean' ? false : field.type === 'number' ? 0 : '')])));
    useUnsavedChanges(form.isDirty);
    return <section className={surface} aria-labelledby="content-editor-title">
        <h2 id="content-editor-title" className="text-base font-semibold">{t(row ? 'publicSite.admin.editContent' : 'publicSite.admin.newContent')}</h2>
        <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">{t('publicSite.admin.contentHint')}</p>
        <form className="mt-4 space-y-4" onSubmit={e => {
            e.preventDefault();
            const options = { preserveScroll: true, onSuccess: () => { form.setDefaults(form.data); close(); } };
            if (row) form.put(route('public-site-management.update', { kind, id: row.id }), options);
            else form.post(route('public-site-management.store', { kind }), options);
        }}>
            <fieldset disabled={form.processing} className="grid min-w-0 gap-4 md:grid-cols-2">
                {fields.map(field => <EditorField key={field.key} field={field} value={form.data[field.key]} error={form.errors[field.key]} onChange={value => form.setData(field.key, value)} routes={routes} />)}
            </fieldset>
            <SaveState processing={form.processing} saved={form.recentlySuccessful} errors={form.errors} />
            <Button type="button" variant="secondary" disabled={form.processing} onClick={() => { if (!form.isDirty || window.confirm(t('publicSite.admin.discard'))) close(); }}>{t('common.cancel')}</Button>
        </form>
    </section>;
}

function Collection({ props }: { props: Props }) {
    const { t, locale } = useLocale();
    const [editing, setEditing] = useRemember<{ row: RecordRow | null; session: number } | null>(null, `public-site:editing:${props.tab}`);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const search = useForm({ search: props.search, tab: props.tab });
    const rows = props.records!;
    const transition = (row: RecordRow, action: string) => {
        if (!window.confirm(t(`publicSite.admin.confirm${action}`))) return;
        router.post(route('public-site-management.transition', { kind: props.tab, id: row.id }), { action }, {
            preserveScroll: true, onStart: () => { setBusy(true); setError(''); }, onFinish: () => setBusy(false), onError: () => setError(t('publicSite.admin.actionFailed')),
        });
    };
    if (editing !== null) return <RecordEditor row={editing.row} session={editing.session} fields={props.fields} kind={props.tab} routes={props.publicRoutes} close={() => setEditing(null)} />;
    return <section className={surface}>
        <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-base font-semibold">{t(`publicSite.admin.tabs.${props.tab}`)} <span className="text-sm font-normal text-gray-500 dark:text-slate-400">({new Intl.NumberFormat(locale).format(rows.total)})</span></h2>
            {props.can.create && <Button type="button" onClick={() => setEditing({ row: null, session: Date.now() })}>{t('publicSite.admin.add')}</Button>}
        </div>
        <form className="my-4 flex flex-wrap items-end gap-2" onSubmit={e => { e.preventDefault(); search.get(route('public-site-management.index'), { preserveScroll: true }); }}>
            <label className="min-w-0 flex-1 space-y-1 text-sm">{t('publicSite.search')}<input className={input} type="search" maxLength={160} value={search.data.search} onChange={e => search.setData('search', e.target.value)} /></label>
            <Button type="submit" variant="secondary" disabled={search.processing}>{t('publicSite.search')}</Button>
        </form>
        {error && <p role="alert" className="mb-3 text-sm text-red-700 dark:text-red-300">{error}</p>}
        <ul className="divide-y divide-gray-200 dark:divide-slate-700" aria-busy={busy}>
            {rows.data.map(row => {
                const base = props.tab === 'services' ? 'name' : props.tab === 'faqs' ? 'question' : 'title';
                const title = String(row[`${base}_${locale}`] || row[`${base}_en`] || '');
                const live = row.status === 'published' || row.status === 'scheduled';
                const status = props.tab === 'faqs' ? (row.is_published ? 'published' : 'draft') : row.status;
                return <li key={row.id} className="flex flex-col gap-2 py-3 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <p className="break-words text-sm font-medium">{title}</p>
                        <span className="text-xs text-gray-500 dark:text-slate-400">{t(`publicSite.admin.status.${status}`)}{row.is_featured ? ` · ${t('publicSite.featured')}` : ''}</span>
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                        {props.can.update && (!live || props.can.publish) && <Button type="button" variant="secondary" disabled={busy} onClick={() => setEditing({ row, session: Date.now() })} aria-label={`${t('common.edit')}: ${title}`}>{t('common.edit')}</Button>}
                        {props.tab !== 'faqs' && <>
                            {props.can.publish && <Button type="button" variant="secondary" disabled={busy} onClick={() => transition(row, live ? 'unpublish' : 'publish')}>{t(live ? 'publicSite.admin.unpublish' : 'publicSite.admin.publish')}</Button>}
                            {props.can.archive && row.status !== 'archived' && <Button type="button" variant="secondary" disabled={busy} onClick={() => transition(row, 'archive')}>{t('publicSite.admin.archive')}</Button>}
                        </>}
                    </div>
                </li>;
            })}
        </ul>
        {rows.data.length === 0 && <p className="py-6 text-sm text-gray-500 dark:text-slate-400">{t('publicSite.admin.empty')}</p>}
        {rows.last_page > 1 && <nav aria-label={t('publicSite.admin.pagination')} className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4 text-sm dark:border-slate-700">
            <Button type="button" variant="secondary" disabled={busy || rows.current_page <= 1} onClick={() => router.get(route('public-site-management.index'), { tab: props.tab, search: props.search, page: rows.current_page - 1 })}>{t('common.previous')}</Button>
            <span>{rows.current_page} / {rows.last_page}</span>
            <Button type="button" variant="secondary" disabled={busy || rows.current_page >= rows.last_page} onClick={() => router.get(route('public-site-management.index'), { tab: props.tab, search: props.search, page: rows.current_page + 1 })}>{t('common.next')}</Button>
        </nav>}
    </section>;
}

function SiteSettings({ fields, editable }: { fields: SettingsField[]; editable: boolean }) {
    const { t, locale } = useLocale();
    const form = useForm<Record<string, string | number | boolean | string[] | File | null>>('public-site:settings', Object.fromEntries(fields.map(field => {
        const value = field.value ?? field.default ?? '';
        return [field.key, typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string' ? value : ''];
    })));
    useUnsavedChanges(form.isDirty);
    return <form className={`${surface} space-y-4`} onSubmit={e => {
        e.preventDefault();
        if (form.data.enabled === false && !window.confirm(t('publicSite.admin.disableConfirm'))) return;
        form.put(route('public-site-management.settings'), { preserveScroll: true, onSuccess: () => form.setDefaults(form.data) });
    }}>
        <h2 className="text-base font-semibold">{t('publicSite.admin.tabs.settings')}</h2>
        <p className="text-sm text-gray-500 dark:text-slate-400">{t('publicSite.admin.settingsHint')}</p>
        <div className="grid min-w-0 gap-4 md:grid-cols-2">
            {fields.map(field => <SettingField key={field.key} field={field} locale={locale} value={form.data[field.key]} error={form.errors[field.key]} disabled={!editable || form.processing} onChange={value => form.setData(field.key, value)} />)}
        </div>
        {editable && <SaveState processing={form.processing} saved={form.recentlySuccessful} errors={form.errors} />}
    </form>;
}

export default function Index(props: Props) {
    const { t } = useLocale();
    return <AuthenticatedLayout>
        <Head title={t('publicSite.admin.title')} />
        <div className="min-w-0 space-y-5 text-gray-900 dark:text-slate-100">
            <PageHeader title={t('publicSite.admin.title')} description={t('publicSite.admin.description')} actions={<a href={route('home')} target="_blank" rel="noopener noreferrer" className="rounded-md border border-gray-300 px-3 py-2 text-sm focus-visible:outline focus-visible:outline-2 dark:border-slate-600">{t('publicSite.admin.openSite')}</a>} />
            <nav aria-label={t('publicSite.admin.title')} className="flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-slate-700">
                {props.tabs.map(tab => <Link key={tab} href={route('public-site-management.index', { tab })} aria-current={tab === props.tab ? 'page' : undefined} className={`rounded-md px-3 py-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 ${tab === props.tab ? 'bg-gray-100 text-gray-950 dark:bg-slate-700 dark:text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-slate-300 dark:hover:bg-slate-800'}`}>{t(`publicSite.admin.tabs.${tab}`)}</Link>)}
            </nav>
            {props.tabs.length === 0 && <p className={surface}>{t('publicSite.admin.noAccess')}</p>}
            {props.records && <Collection key={`${props.tab}-${props.records.current_page}-${props.search}`} props={props} />}
            {props.sections.length > 0 && <section aria-labelledby="page-sections-title" className="space-y-3">
                <h2 id="page-sections-title" className="text-base font-semibold">{t('publicSite.admin.pageSections')}</h2>
                <p className="text-sm text-gray-500 dark:text-slate-400">{t('publicSite.admin.pageHint')}</p>
                {props.sections.map(section => <SectionEditor key={`${props.tab}-${section.key}`} section={section} page={props.tab} editable={props.can.updatePage} routes={props.publicRoutes} />)}
            </section>}
            {props.tab === 'settings' && <SiteSettings fields={props.settingsFields} editable={props.can.update} />}
        </div>
    </AuthenticatedLayout>;
}
