import EmptyState from '@/Components/EmptyState';
import { PencilIcon } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { AppActionMenu, FilterField, FilterPanel, ListPagination, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, badgeClassName, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    ActionErrors, ExperiencePopup, Notice, errorCls, inputCls, labelCls, mutedCls, primaryBtn, secondaryBtn, useExperience,
    type Bilingual, type Paginated,
} from '@/modules/work-experience/lib';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Office extends Bilingual {
    id: string;
    signatory_name_am: string | null;
    signatory_name_en: string | null;
    signatory_title_am: string | null;
    signatory_title_en: string | null;
    include_salary: boolean;
    include_external: boolean;
    configured: boolean;
}

interface Props {
    experienceOffices: Paginated<Office>;
    filters: { search: string };
}

const FIELDS = ['signatory_name_am', 'signatory_name_en', 'signatory_title_am', 'signatory_title_en', 'include_salary', 'include_external'] as const;

function Toggle({ id, label, help, checked, onChange }: { id: string; label: string; help: string; checked: boolean; onChange: (value: boolean) => void }): JSX.Element {
    return (
        <label htmlFor={id} className="flex cursor-pointer items-start gap-3 rounded-panel border border-(--app-border) p-3">
            <input id={id} type="checkbox" className="mt-1" checked={checked} onChange={(e) => onChange(e.target.checked)} />
            <span>
                <span className="block text-sm font-medium text-(--app-foreground)">{label}</span>
                <span className={cx(mutedCls, 'block text-xs')}>{help}</span>
            </span>
        </label>
    );
}

function OfficeForm({ office, onDone }: { office: Office; onDone: () => void }): JSX.Element {
    const { wt } = useExperience();
    const form = useForm({
        signatory_name_am: office.signatory_name_am ?? '',
        signatory_name_en: office.signatory_name_en ?? '',
        signatory_title_am: office.signatory_title_am ?? '',
        signatory_title_en: office.signatory_title_en ?? '',
        include_salary: office.include_salary,
        include_external: office.include_external,
    });
    const text = (field: 'signatory_name_am' | 'signatory_name_en' | 'signatory_title_am' | 'signatory_title_en') => (
        <div>
            <label htmlFor={`wx-${field}`} className={labelCls}>{wt(`settings.${field}`)}</label>
            <input id={`wx-${field}`} type="text" maxLength={255} className={inputCls} value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)} />
            {form.errors[field] && <p className={errorCls}>{form.errors[field]}</p>}
        </div>
    );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('work-experience.settings.update', office.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <Notice tone="info">{wt('settings.formHelp')}</Notice>
            <div className="grid gap-4 sm:grid-cols-2">
                {text('signatory_name_am')}{text('signatory_title_am')}{text('signatory_name_en')}{text('signatory_title_en')}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Toggle id="wx-include-salary" label={wt('settings.includeSalary')} help={wt('settings.includeSalaryHelp')} checked={form.data.include_salary} onChange={(v) => form.setData('include_salary', v)} />
                <Toggle id="wx-include-external" label={wt('settings.includeExternal')} help={wt('settings.includeExternalHelp')} checked={form.data.include_external} onChange={(v) => form.setData('include_external', v)} />
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" className={secondaryBtn} onClick={onDone}>{wt('common.close')}</button>
                <button type="submit" className={primaryBtn} disabled={form.processing}>{wt('common.save')}</button>
            </div>
        </form>
    );
}

/**
 * Employee management > Work experience settings: for each institution, who
 * signs the certificate, and what the certificate carries.
 */
export default function Settings({ experienceOffices, filters }: Props): JSX.Element {
    const { wt, name, am } = useExperience();
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<Office | null>(null);
    const apply = () => router.get(route('work-experience.settings'), search.trim() === '' ? {} : { search: search.trim() }, { preserveState: true });
    const signer = (office: Office) => (am ? office.signatory_name_am || office.signatory_name_en : office.signatory_name_en || office.signatory_name_am);
    const title = (office: Office) => (am ? office.signatory_title_am || office.signatory_title_en : office.signatory_title_en || office.signatory_title_am);

    return (
        <AuthenticatedLayout header={<PageHeader title={wt('settings.title')} description={wt('settings.description')} backHref={route('work-experience.requests.index')} />}>
            <Head title={wt('settings.title')} />
            <div className="space-y-4 pb-4">
                <FilterPanel onSubmit={apply} onReset={() => router.get(route('work-experience.settings'))} hasActiveFilters={filters.search !== ''}>
                    <FilterField label={wt('settings.search')} wide><input type="search" className={inputCls} value={search} onChange={(e) => setSearch(e.target.value)} /></FilterField>
                </FilterPanel>

                <TableCard>
                    <div className="border-b border-(--app-border) px-4 py-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{wt('settings.tableTitle')}</h2>
                        <p className={cx(mutedCls, 'mt-0.5 text-xs')}>{wt('settings.tableDescription')}</p>
                    </div>
                    {experienceOffices.data.length === 0 ? <EmptyState title={wt('settings.emptyTitle')} /> : (
                        <TableTools id="work-experience-settings"><Table>
                            <THead>
                                <Tr>
                                    <Th>{wt('requests.institution')}</Th><Th>{wt('settings.signatory')}</Th><Th>{wt('settings.salary')}</Th><Th>{wt('settings.external')}</Th>
                                    <Th>{wt('requests.status')}</Th><Th className="text-end">{wt('common.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {experienceOffices.data.map((office) => (
                                    <Tr key={office.id}>
                                        <Td className="font-medium text-(--app-foreground)">{name(office)}</Td>
                                        <Td>
                                            <div>{signer(office) ?? '—'}</div>
                                            {title(office) && <div className={cx(mutedCls, 'text-xs')}>{title(office)}</div>}
                                        </Td>
                                        <Td>{wt(office.include_salary ? 'settings.written' : 'settings.leftOut')}</Td>
                                        <Td>{wt(office.include_external ? 'settings.written' : 'settings.leftOut')}</Td>
                                        <Td><span className={badgeClassName(office.configured ? 'success' : 'warning')}>{wt(office.configured ? 'settings.ready' : 'settings.noSignatory')}</span></Td>
                                        <Td className="whitespace-nowrap text-end">
                                            <div className="flex justify-end">
                                                <AppActionMenu items={[{ label: wt('settings.edit'), onClick: () => setEditing(office), icon: <PencilIcon className="h-4 w-4" /> }]} />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table></TableTools>
                    )}
                </TableCard>
                <ListPagination meta={experienceOffices} />
            </div>

            <ExperiencePopup show={editing !== null} onClose={() => setEditing(null)} title={wt('settings.popupTitle')} subtitle={editing ? name(editing) : null}>
                <ActionErrors except={[...FIELDS]} />
                {editing && <OfficeForm key={editing.id} office={editing} onDone={() => setEditing(null)} />}
            </ExperiencePopup>
        </AuthenticatedLayout>
    );
}
