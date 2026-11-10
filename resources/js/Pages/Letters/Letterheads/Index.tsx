import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import { inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, useLetters } from '@/Components/letters/helpers';
import { AppActionMenu, badgeClassName, cx } from '@/Components/ui';
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
                    <ul className="space-y-3">
                        {layouts.map((l) => (
                            <li key={l.id} className={cx(panelCls, 'flex flex-wrap items-center gap-x-4 gap-y-3 p-4')}>
                                <div className="min-w-0 flex-1">
                                    <p className="flex flex-wrap items-center gap-2">
                                        <Link className="text-base font-semibold underline-offset-2 hover:underline" href={route('letters.letterheads.edit', l.id)}>{l.name}</Link>
                                        {l.is_default && <span className={badgeClassName('info')}>{lt('letterheads.isDefault')}</span>}
                                        {!l.active && <span className={badgeClassName('neutral')}>{lt('letterheads.inactive')}</span>}
                                    </p>
                                    <p className={cx(mutedCls, 'mt-0.5 text-xs')}>
                                        {lt(`letterheads.papers.${l.paper}`)} · {lt(`letterheads.orientations.${l.orientation}`)}
                                        {l.has_stamp && <> · {lt('letterheads.withStamp')}</>}
                                        {l.letters_count > 0 && <> · {lt('letterheads.usedBy', { count: l.letters_count })}</>}
                                        {l.updated_at && <> · <LocalizedDateDisplay value={l.updated_at} /></>}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Link className={primaryBtn} href={route('letters.letterheads.edit', l.id)}>{lt('letterheads.edit')}</Link>
                                    <a className={secondaryBtn} href={route('letters.letterheads.preview', l.id)} target="_blank" rel="noopener">{lt('letterheads.previewPdf')}</a>
                                    <AppActionMenu items={[
                                        { label: lt('letterheads.makeDefault'), onClick: () => router.post(route('letters.letterheads.default', l.id), {}, { preserveScroll: true }), show: !l.is_default },
                                        { label: lt('letterheads.duplicate'), onClick: () => router.post(route('letters.letterheads.duplicate', l.id)) },
                                        { label: lt('letterheads.delete'), variant: 'danger', onClick: () => setDeleting(l), show: l.letters_count === 0, separatorBefore: true },
                                    ]} />
                                </div>
                            </li>
                        ))}
                    </ul>
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
