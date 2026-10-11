import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

import { actionLinkClassName, buttonClassName } from '@/Components/ui';
type Establishment = {
    id: string;
    establishment_number: string;
    status: string;
    approved_slots: number;
    effective_from: string;
    effective_to: string | null;
    approval_reference: string | null;
    notes: string | null;
    approved_at: string | null;
    organization: { id: string; name_en: string } | null;
    organization_unit: { name_en: string } | null;
    position: { id: string; title_en: string } | null;
    occupation: { title_en: string } | null;
    approved_by: { name: string } | null;
    occupancies: Array<{ id: string; status: string; employee: { name_en: string } | null; occupied_from: string }>;
    vacancy_announcements: Array<{ id: string; announcement_number: string; status: string; title_en: string }>;
    can: { update: boolean; approve: boolean; archive: boolean };
};

export default function PositionEstablishmentsShow({ establishment }: { establishment: Establishment }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('position-establishments.index')}
                    title={establishment.establishment_number}
                    description={establishment.position?.title_en}
                    actions={
                        <div className="flex gap-2">
                            {establishment.can.update && (
                                <Link href={route('position-establishments.edit', establishment.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                    {t('common.edit')}
                                </Link>
                            )}
                            {establishment.can.approve && establishment.status === 'draft' && (
                                <button
                                    type="button"
                                    onClick={() => router.post(route('position-establishments.approve', establishment.id))}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    {t('positionEstablishments.approve')}
                                </button>
                            )}
                            {establishment.can.archive && (
                                <button
                                    type="button"
                                    onClick={() => { if (confirm(t('common.cannotUndo'))) router.delete(route('position-establishments.archive', establishment.id)); }}
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                >
                                    {t('common.archive')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={establishment.establishment_number} />

            <div className="grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <StatusBadge status={establishment.status} />
                    <dl className="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
                        {[
                            { label: t('positionEstablishments.organization'), value: establishment.organization?.name_en ?? '—' },
                            { label: t('positionEstablishments.organizationUnit'), value: establishment.organization_unit?.name_en ?? '—' },
                            { label: t('positionEstablishments.position'), value: establishment.position?.title_en ?? '—' },
                            { label: t('positionEstablishments.occupation'), value: establishment.occupation?.title_en ?? '—' },
                            { label: t('positionEstablishments.approvedSlots'), value: establishment.approved_slots },
                            { label: t('positionEstablishments.approvalReference'), value: establishment.approval_reference ?? '—' },
                            { label: t('positionEstablishments.approvedBy'), value: establishment.approved_by?.name ?? '—' },
                        ].map(({ label, value }) => (
                            <div key={label}>
                                <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{label}</dt>
                                <dd className="mt-1 text-[color:var(--app-foreground)]">{value}</dd>
                            </div>
                        ))}
                        <div>
                            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('positionEstablishments.effectiveFrom')}</dt>
                            <dd className="mt-1 text-[color:var(--app-foreground)]"><LocalizedDateDisplay value={establishment.effective_from} /></dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('positionEstablishments.effectiveTo')}</dt>
                            <dd className="mt-1 text-[color:var(--app-foreground)]">
                                {establishment.effective_to ? <LocalizedDateDisplay value={establishment.effective_to} /> : t('common.current')}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('positionEstablishments.approvedAt')}</dt>
                            <dd className="mt-1 text-[color:var(--app-foreground)]"><LocalizedDateDisplay value={establishment.approved_at} /></dd>
                        </div>
                    </dl>
                    {establishment.notes && (
                        <div className="mt-4">
                            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('positionEstablishments.notes')}</dt>
                            <dd className="mt-1 text-sm text-[color:var(--app-foreground)] whitespace-pre-line">{establishment.notes}</dd>
                        </div>
                    )}
                </section>

                <div className="space-y-6">
                    <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                        <h3 className="font-semibold text-[color:var(--app-foreground)]">{t('positionEstablishments.activeOccupancies')}</h3>
                        {establishment.occupancies.filter(o => o.status === 'active').length === 0 ? (
                            <p className="mt-3 text-sm text-[color:var(--app-muted-foreground)]">{t('common.none')}</p>
                        ) : (
                            <ul className="mt-3 space-y-1 text-sm">
                                {establishment.occupancies.filter(o => o.status === 'active').map(o => (
                                    <li key={o.id} className="flex justify-between">
                                        <span>{o.employee?.name_en ?? '—'}</span>
                                        <span className="text-[color:var(--app-muted-foreground)]">{o.occupied_from}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                        <div className="flex items-center justify-between">
                            <h3 className="font-semibold text-[color:var(--app-foreground)]">{t('vacancies.announcements')}</h3>
                            {establishment.status === 'approved' && (
                                <Link
                                    href={route('vacancy-announcements.create') + `?establishment=${establishment.id}`}
                                    className={actionLinkClassName()}
                                >
                                    {t('vacancies.createAnnouncement')}
                                </Link>
                            )}
                        </div>
                        {establishment.vacancy_announcements.length === 0 ? (
                            <p className="mt-3 text-sm text-[color:var(--app-muted-foreground)]">{t('common.none')}</p>
                        ) : (
                            <ul className="mt-3 space-y-1.5 text-sm">
                                {establishment.vacancy_announcements.map(a => (
                                    <li key={a.id} className="flex items-center justify-between">
                                        <Link href={route('vacancy-announcements.show', a.id)} className="text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]">
                                            {a.title_en}
                                        </Link>
                                        <StatusBadge status={a.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
