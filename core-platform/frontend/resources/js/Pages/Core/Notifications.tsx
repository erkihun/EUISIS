import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { AppTabNav, ListPagination, TableCard, actionLinkClassName, laravelPageMeta } from '@/Components/ui';
import { Button, cx, EmptyState, type PaginationMeta } from '@euisis/ui';

type Item = { id: string; event: string; title: string; body: string; url: string | null; read: boolean; created_at: string | null };

/** Core › Notifications (ADR 0011): what the reader must act on or know about, in the page language. */
export default function Notifications({ notifications, unread, unread_only, read_url, url }: {
    notifications: { data: Item[]; meta: PaginationMeta };
    unread: number;
    unread_only: boolean;
    read_url: string;
    url: string;
}) {
    const { t } = useLocale();
    const label = (key: string) => t(`core.notifications.${key}`);
    const markRead = (ids?: string[]) => router.post(read_url, ids ? { ids } : {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')}
            actions={unread > 0 ? <Button variant="outline" onClick={() => markRead()}>{label('markAllRead')}</Button> : undefined} />}>
            <Head title={label('title')} />
            <div className="space-y-4">
                <AppTabNav
                    label={label('title')}
                    current={unread_only ? 'unread' : 'all'}
                    preserveScroll={false}
                    items={[
                        { key: 'all', label: label('all'), href: url },
                        { key: 'unread', label: label('unread'), count: unread, href: url, data: { unread: 1 } },
                    ]}
                />
                <TableCard>
                    {notifications.data.length === 0 ? <EmptyState title={label('empty')} /> : (
                        <ul className="divide-y divide-(--app-border)">
                            {notifications.data.map((item) => (
                                <li key={item.id} className={cx('flex items-start justify-between gap-3 px-4 py-4', !item.read && 'bg-(--app-surface-muted)')}>
                                    <div className="min-w-0">
                                        <p className={cx('text-sm text-(--app-foreground)', !item.read && 'font-semibold')}>{item.title}</p>
                                        <p className="mt-1 text-sm text-(--app-muted-foreground)">{item.body}</p>
                                        <p className="mt-1 text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={item.created_at} withTime /></p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {item.url && <Link href={item.url} onClick={() => !item.read && markRead([item.id])} className={actionLinkClassName({ size: 'sm' })}>{label('open')}</Link>}
                                        {!item.read && <Button size="sm" variant="outline" onClick={() => markRead([item.id])}>{label('markRead')}</Button>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    <ListPagination meta={laravelPageMeta(notifications.meta)} onPageChange={(page) => router.get(url, { unread: unread_only ? 1 : undefined, page }, { preserveScroll: true })} />
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
