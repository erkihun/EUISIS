import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    AppActionMenu, AppMetricCard, FilterField, FilterPanel, Input, ListPagination, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
    type AppActionItem,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import type { LetterRow, LettersIndexProps } from '@/court/types/letters';

const STATUS_TONES: Record<string, 'warning' | 'success' | 'danger' | 'neutral'> = { pending: 'warning', approved: 'success', returned: 'warning', rejected: 'danger' };
const FILTERS = ['pending', 'approved', 'rejected'] as const;

/**
 * Letters: the figures (total, approved, rejected, categories), a status
 * filter, and every letter with its parties, author, date and approval, with
 * view, edit, approve/return/reject and delete by permission.
 */
export default function LettersIndex({ letters, stats, filters, can }: LettersIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const [q, setQ] = useState(filters.q);
    const title = t('cm.letters.titles.index');
    // The status links keep the search, and the search keeps the status.
    const searchParams = filters.q ? { q: filters.q } : {};
    const statusParams = filters.status ? { status: filters.status } : {};

    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.letters.index'), { ...statusParams, ...(q.trim() ? { q: q.trim() } : {}) }, { preserveState: true });
    };

    const decide = (letter: LetterRow, status: 'approved' | 'returned' | 'rejected') =>
        router.post(route('court.letters.approve', letter.id), { status }, { preserveScroll: true, onStart: () => setBusy(letter.id), onFinish: () => setBusy(null) });
    const remove = async (letter: LetterRow) => {
        const { confirmed } = await confirm({
            title: t('cm.letters.actions.delete'),
            description: t('cm.letters.confirm.delete_letter'),
            confirmLabel: t('cm.letters.actions.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.letters.destroy', letter.id), { preserveScroll: true, onStart: () => setBusy(letter.id), onFinish: () => setBusy(null) });
    };
    const actionsFor = (letter: LetterRow): AppActionItem[] => {
        // The letter preview is a document page of its own, opened in a new tab as before.
        const items: AppActionItem[] = [{ label: t('cm.letters.actions.view'), onClick: () => window.open(route('court.letters.show', letter.id), '_blank', 'noopener') }];
        if (letter.approval_status !== 'approved' && can.update) items.push({ label: t('cm.letters.actions.edit'), href: route('court.letters.edit', letter.id) });
        // An approved letter has gone to the parties; it is not decided again.
        if (can.approve && letter.approval_status !== 'approved') {
            items.push({ label: t('cm.letters.table.status_approved'), onClick: () => decide(letter, 'approved') });
            items.push({ label: t('cm.letters.table.status_returned'), onClick: () => decide(letter, 'returned') });
            items.push({ label: t('cm.letters.table.status_rejected'), onClick: () => decide(letter, 'rejected') });
        }
        if (can.delete) items.push({ label: t('cm.letters.actions.delete'), onClick: () => void remove(letter), variant: 'danger' });
        return items;
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.letters.description.index')}
                actions={can.create ? <Link href={route('court.letters.compose')} className={buttonClassName({})}>{t('cm.app.compose_letter')}</Link> : undefined} />
        )}>
            <Head title={title} />

            <div className="space-y-6">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <AppMetricCard label={t('cm.letters.cards.total_letters')} value={formatNumber(stats.total)} variant="primary" />
                    <AppMetricCard label={t('cm.letters.cards.approved_letters')} value={formatNumber(stats.approved)} detail={t('cm.letters.cards.approved_letters_hint')} variant="success" />
                    <AppMetricCard label={t('cm.letters.cards.rejected_letters')} value={formatNumber(stats.rejected)} detail={t('cm.letters.cards.rejected_letters_hint')} variant="warning" />
                    <AppMetricCard label={t('cm.letters.cards.letter_categories')} value={formatNumber(stats.categories)}
                        detail={stats.top_category ? t('cm.letters.cards.top_category').replace(':category', stats.top_category) : t('cm.letters.cards.empty_top_category')} variant="neutral" />
                </div>

                <FilterPanel onSubmit={search} onReset={() => router.get(route('court.letters.index', statusParams))} hasActiveFilters={Boolean(filters.q)} label={title}>
                    <FilterField label={t('cm.app.Search')} className="sm:col-span-2">
                        <Input value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('cm.letters.table.search_placeholder')} />
                    </FilterField>
                </FilterPanel>

                <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('cm.letters.table.filter')}>
                    <Link href={route('court.letters.index', searchParams)} className={buttonClassName({ variant: filters.status === '' ? 'primary' : 'outline', size: 'sm' })} preserveScroll>{t('cm.letters.table.all_letters')}</Link>
                    {FILTERS.map((status) => (
                        <Link key={status} href={route('court.letters.index', { ...searchParams, status })} className={buttonClassName({ variant: filters.status === status ? 'primary' : 'outline', size: 'sm' })} preserveScroll>
                            {t(`cm.letters.table.status_${status}`)}
                        </Link>
                    ))}
                </div>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[t('cm.letters.table.reference'), t('cm.letters.table.applicant'), t('cm.letters.table.respondent'), t('cm.letters.table.author'), t('cm.letters.table.created'), t('cm.app.Status'), t('cm.letters.table.actions')].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {letters.data.map((letter) => (
                                    <tr key={letter.id} className={cx(tableRowClassName, busy === letter.id && 'opacity-60')}>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <div className="font-mono font-semibold text-(--app-foreground)">{letter.reference_number ?? t('cm.letters.cards.missing')}</div>
                                            {letter.subject && <div className="mt-1 max-w-xs truncate text-xs text-(--app-muted-foreground)">{letter.subject}</div>}
                                            {letter.case_number && <div className="mt-1 font-mono text-xs text-(--app-muted-foreground)">{letter.case_number}</div>}
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <div className="text-(--app-foreground)">{letter.send_to_applicant ? (letter.applicant_name ?? t('cm.letters.cards.missing')) : t('cm.letters.cards.missing')}</div>
                                            {letter.send_to_applicant && letter.applicant_lawyer_name && <div className="text-xs text-indigo-700 dark:text-indigo-300">{t('cm.dashboard.submitted_by_lawyer').replace(':name', letter.applicant_lawyer_name)}</div>}
                                            <div className="text-xs text-(--app-muted-foreground)">{t('cm.letters.form.deliver_applicant')}</div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <div className="text-(--app-foreground)">{letter.send_to_respondent ? (letter.respondent_name ?? t('cm.letters.cards.missing')) : t('cm.letters.cards.missing')}</div>
                                            <div className="text-xs text-(--app-muted-foreground)">{t('cm.letters.form.deliver_respondent')}</div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <div className="text-(--app-foreground)">{letter.author_name ?? t('cm.letters.cards.missing')}</div>
                                            <div className="text-xs text-(--app-muted-foreground)">{letter.author_position ?? t('cm.letters.table.author')}</div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top whitespace-nowrap')}><LocalizedDateDisplay value={letter.created_at} withTime /></td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            {letter.approval_status ? (
                                                <>
                                                    <span className={badgeClassName(STATUS_TONES[letter.approval_status] ?? 'neutral')}>{t(`cm.letters.table.status_${letter.approval_status}`)}</span>
                                                    {letter.approved_by_name && <div className="mt-1 text-xs text-(--app-muted-foreground)">{letter.approved_by_name}</div>}
                                                </>
                                            ) : <span className="text-(--app-muted-foreground)">—</span>}
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <AppActionMenu items={actionsFor(letter)} label={t('cm.letters.table.actions')} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {letters.data.length === 0 && <EmptyState title={t('cm.letters.cards.no_letters_yet')} description={t('cm.letters.cards.empty_description')} />}
                    <ListPagination meta={letters} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
