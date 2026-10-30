import PageHeader from '@/Components/PageHeader';
import { errorCls, inputCls, labelCls, panelCls, primaryBtn, useSm } from '@/Components/serviceMonitoring/helpers';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { NamedWithId } from '@/types/serviceMonitoring';
import { Head, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent, type JSX } from 'react';

interface Props {
    settings: { require_stamped_copy: boolean; declaration_text_en: string; declaration_text_am: string; owner_organization_id: string | null; driver_occupation_ids: string[] };
    occupations: (NamedWithId & { code: string })[];
    organizations: NamedWithId[];
}

/** Module settings (D11, D18): declaration, stamped-copy rule, issuing organization of city exports, driver occupations. */
export default function ServiceMonitoringSettings({ settings, occupations, organizations }: Props): JSX.Element {
    const { sm, name } = useSm();
    const [query, setQuery] = useState('');
    const form = useForm({ ...settings, owner_organization_id: settings.owner_organization_id ?? '' });
    const shown = useMemo(() => {
        const q = query.trim().toLowerCase();
        return occupations.filter((o) => form.data.driver_occupation_ids.includes(o.id) || q === '' || `${o.code} ${o.name_en ?? ''} ${o.name_am ?? ''}`.toLowerCase().includes(q)).slice(0, 200);
    }, [occupations, query, form.data.driver_occupation_ids]);

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, owner_organization_id: d.owner_organization_id || null }));
        form.put(route('service-monitoring.settings.update'), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('settings.title')} description={sm('settings.description')} />}>
            <Head title={sm('settings.title')} />
            <form onSubmit={save} className="space-y-4">
                <section className={`${panelCls} space-y-3 p-4`}>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.require_stamped_copy} onChange={(e) => form.setData('require_stamped_copy', e.target.checked)} />
                        {sm('settings.requireStamped')}
                    </label>
                    {(['declaration_text_am', 'declaration_text_en'] as const).map((key) => (
                        <div key={key}>
                            <label htmlFor={`sm-${key}`} className={labelCls}>{sm(key === 'declaration_text_am' ? 'settings.declarationAm' : 'settings.declarationEn')}</label>
                            <textarea id={`sm-${key}`} rows={3} className={inputCls} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} required />
                            {form.errors[key] && <p className={errorCls}>{form.errors[key]}</p>}
                        </div>
                    ))}
                    <div>
                        <label htmlFor="sm-owner" className={labelCls}>{sm('settings.owner')}</label>
                        <select id="sm-owner" className={inputCls} value={form.data.owner_organization_id} onChange={(e) => form.setData('owner_organization_id', e.target.value)}>
                            <option value="">—</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{name(o)}</option>)}
                        </select>
                    </div>
                </section>
                <section className={`${panelCls} space-y-2 p-4`} aria-labelledby="sm-drivers">
                    <h2 id="sm-drivers" className="text-sm font-semibold">{sm('settings.drivers')}</h2>
                    <p className="text-xs text-(--app-muted-foreground)">{sm('settings.driversHint')}</p>
                    <input type="search" aria-label={sm('settings.searchOccupation')} placeholder={sm('settings.searchOccupation')} className={inputCls} value={query} onChange={(e) => setQuery(e.target.value)} />
                    <fieldset className="grid max-h-80 gap-1 overflow-y-auto sm:grid-cols-2">
                        <legend className="sr-only">{sm('settings.drivers')}</legend>
                        {shown.map((o) => (
                            <label key={o.id} className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.data.driver_occupation_ids.includes(o.id)}
                                    onChange={(e) => form.setData('driver_occupation_ids', e.target.checked ? [...form.data.driver_occupation_ids, o.id] : form.data.driver_occupation_ids.filter((x) => x !== o.id))} />
                                <span>{name(o)} <span className="text-xs text-(--app-muted-foreground)">{o.code}</span></span>
                            </label>
                        ))}
                    </fieldset>
                </section>
                <button type="submit" className={primaryBtn} disabled={form.processing}>{sm('common.save')}</button>
            </form>
        </AuthenticatedLayout>
    );
}
