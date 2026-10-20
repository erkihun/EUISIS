import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

import { actionLinkClassName, buttonClassName } from '@/Components/ui';
export default function OccupationsShow({ occupation }: { occupation: any }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();

    async function handleArchive() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('occupations.archive', occupation.id));
    }

    async function handleRestore() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (confirmed) router.post(route('occupations.restore', occupation.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('occupations.index')}
                    title={`${occupation.isco_code} · ${occupation.name_en ?? occupation.name_am ?? ''}`}
                    actions={
                        <div className="flex gap-3">
                            {occupation.can?.update && (
                                <Link
                                    href={route('occupations.edit', occupation.id)}
                                    className={buttonClassName({ variant: 'primary' })}
                                >
                                    {t('common.edit')}
                                </Link>
                            )}
                            {occupation.can?.archive && !occupation.deleted_at && (
                                <button
                                    type="button"
                                    onClick={handleArchive}
                                    className={buttonClassName({ variant: 'destructive' })}
                                >
                                    {t('occupations.archiveOccupation')}
                                </button>
                            )}
                            {occupation.can?.restore && occupation.deleted_at && (
                                <button
                                    type="button"
                                    onClick={handleRestore}
                                    className={buttonClassName({ variant: 'primary' })}
                                >
                                    {t('occupations.restoreOccupation')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={occupation.name_en ?? occupation.isco_code} />
            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5 lg:col-span-2">
                    <div className="grid gap-4 md:grid-cols-2 text-sm">
                        <Field label={t('occupations.iscoCode')} value={occupation.isco_code} mono />
                        <Field
                            label={t('common.status')}
                            value={<StatusBadge status={occupation.is_active ? 'active' : 'inactive'} />}
                        />
                        <Field label={t('occupations.nameEn')} value={occupation.name_en} />
                        <Field label={t('occupations.nameAm')} value={occupation.name_am} />
                        <Field label={t('occupations.skillSpecialization')} value={occupation.skill_specialization} />
                    </div>
                    {(occupation.description_en || occupation.description_am) && (
                        <div className="mt-4 space-y-3 border-t border-(--app-border) pt-4">
                            {occupation.description_en && (
                                <div>
                                    <div className="text-xs text-(--app-muted-foreground)">
                                        {t('occupations.descriptionEn')}
                                    </div>
                                    <div className="mt-1 text-sm text-(--app-foreground) whitespace-pre-wrap">
                                        {occupation.description_en}
                                    </div>
                                </div>
                            )}
                            {occupation.description_am && (
                                <div>
                                    <div className="text-xs text-(--app-muted-foreground)">
                                        {t('occupations.descriptionAm')}
                                    </div>
                                    <div className="mt-1 text-sm text-(--app-foreground) whitespace-pre-wrap">
                                        {occupation.description_am}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </section>
                <aside className="space-y-4">
                    <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                        <div className="text-xs text-(--app-muted-foreground)">{t('common.createdAt')}</div>
                        <div className="mt-1 text-sm text-(--app-foreground)">
                            <LocalizedDateDisplay value={occupation.created_at} withTime />
                        </div>
                        <div className="mt-3 text-xs text-(--app-muted-foreground)">{t('common.updatedAt')}</div>
                        <div className="mt-1 text-sm text-(--app-foreground)">
                            <LocalizedDateDisplay value={occupation.updated_at} withTime />
                        </div>
                    </section>
                    <div>
                        <Link
                            href={route('occupations.index')}
                            className={actionLinkClassName({ size: 'sm' })}
                        >
                            {t('occupations.backToList')}
                        </Link>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, value, mono }: { label: string; value: any; mono?: boolean }) {
    return (
        <div>
            <div className="text-xs text-(--app-muted-foreground)">{label}</div>
            <div className={`mt-1 ${mono ? 'font-mono' : ''}`}>{value ?? '—'}</div>
        </div>
    );
}
