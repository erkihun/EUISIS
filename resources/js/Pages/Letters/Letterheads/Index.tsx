import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import { inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, useLetters } from '@/Components/letters/helpers';
import { TBody, Table, Td, Th, THead, Tr, badgeClassName, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { LetterLayoutRow, OrganizationOption } from '@/types/letters';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

interface Props {
    organizations: OrganizationOption[];
    platform: boolean;
    selected_organization_id: string;
    layouts: LetterLayoutRow[];
    inherits_platform: boolean;
}

/** The header-and-footer layouts of one institution (or the platform's), with "Add new" on top. */
export default function LetterheadsIndex({ organizations, platform, selected_organization_id, layouts, inherits_platform }: Props): JSX.Element {
    const { lt, pick } = useLetters();
    const [deleting, setDeleting] = useState<LetterLayoutRow | null>(null);
    const [busy, setBusy] = useState(false);
    const scope = selected_organization_id ? { organization_id: selected_organization_id } : {};

    const addNew = <Link className={primaryBtn} href={route('letters.letterheads.create', scope)}>{lt('letterheads.addNew')}</Link>;

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('letterheads.title')} description={lt('letterheads.description')} backHref={route('letters.dashboard')} actions={addNew} />}>
            <Head title={lt('letterheads.title')} />
            <ActionErrors />
            <div className="space-y-4">
                {(organizations.length > 1 || platform) && (
                    <div className={cx(panelCls, 'p-4 sm:max-w-xl')}>
                        <label htmlFor="lh-org" className={labelCls}>{lt('letterheads.institution')}</label>
                        <select id="lh-org" className={inputCls} value={selected_organization_id}
                            onChange={(e) => router.get(route('letters.letterheads.index'), e.target.value ? { organization_id: e.target.value } : {}, { preserveState: false })}>
                            {platform && <option value="">{lt('letterheads.platformLayouts')}</option>}
                            {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                        </select>
                    </div>
                )}

                {inherits_platform && <p className={cx(panelCls, 'p-4', mutedCls)}>{lt('letterheads.inheritsPlatform')}</p>}

                {layouts.length === 0 ? (
                    <div className={cx(panelCls, 'flex flex-col items-start gap-3 p-6')}>
                        <p className={mutedCls}>{lt('letterheads.empty')}</p>
                        {addNew}
                    </div>
                ) : (
                    <div className={cx(panelCls, 'overflow-x-auto')}>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{lt('letterheads.columns.name')}</Th>
                                    <Th>{lt('letterheads.columns.paper')}</Th>
                                    <Th>{lt('letterheads.columns.rows')}</Th>
                                    <Th>{lt('letterheads.columns.letters')}</Th>
                                    <Th>{lt('letterheads.columns.status')}</Th>
                                    <Th>{lt('letterheads.columns.updated')}</Th>
                                    <Th>{lt('letterheads.columns.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {layouts.map((l) => (
                                    <Tr key={l.id}>
                                        <Td className="font-medium">
                                            <Link className="underline" href={route('letters.letterheads.edit', l.id)}>{l.name}</Link>
                                            {l.is_default && <span className={badgeClassName('info', 'ms-2')}>{lt('letterheads.isDefault')}</span>}
                                        </Td>
                                        <Td>{lt(`letterheads.papers.${l.paper}`)} · {lt(`letterheads.orientations.${l.orientation}`)}</Td>
                                        <Td>{l.header_rows} / {l.footer_rows}{l.has_stamp && <span className={mutedCls}> · {lt('letterheads.withStamp')}</span>}</Td>
                                        <Td>{l.letters_count}</Td>
                                        <Td><span className={badgeClassName(l.active ? 'success' : 'neutral')}>{lt(l.active ? 'letterheads.active' : 'letterheads.inactive')}</span></Td>
                                        <Td>{l.updated_at ? <LocalizedDateDisplay value={l.updated_at} /> : '—'}{l.updated_by && <span className={mutedCls}> · {l.updated_by}</span>}</Td>
                                        <Td>
                                            <div className="flex flex-wrap gap-2">
                                                <Link className={secondaryBtn} href={route('letters.letterheads.edit', l.id)}>{lt('letterheads.edit')}</Link>
                                                <a className={secondaryBtn} href={route('letters.letterheads.preview', l.id)} target="_blank" rel="noopener">{lt('letterheads.previewPdf')}</a>
                                                <button type="button" className={secondaryBtn} onClick={() => router.post(route('letters.letterheads.duplicate', l.id))}>{lt('letterheads.duplicate')}</button>
                                                {!l.is_default && <button type="button" className={secondaryBtn} onClick={() => router.post(route('letters.letterheads.default', l.id), {}, { preserveScroll: true })}>{lt('letterheads.makeDefault')}</button>}
                                                {l.letters_count === 0 && <button type="button" className={cx(secondaryBtn, 'text-red-700 dark:text-red-400')} onClick={() => setDeleting(l)}>{lt('letterheads.delete')}</button>}
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                )}
            </div>
            <ConfirmationDialog open={deleting !== null} onClose={() => setDeleting(null)} loading={busy} variant="danger" title={lt('letterheads.deleteTitle')}
                description={lt('letterheads.deleteBody', { name: deleting?.name ?? '' })} confirmLabel={lt('letterheads.delete')}
                onConfirm={() => {
                    if (!deleting) return;
                    setBusy(true);
                    router.delete(route('letters.letterheads.destroy', deleting.id), { preserveScroll: true, onFinish: () => { setBusy(false); setDeleting(null); } });
                }} />
        </AuthenticatedLayout>
    );
}
