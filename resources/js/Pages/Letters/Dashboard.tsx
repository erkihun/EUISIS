import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import LetterTable from '@/Components/letters/LetterTable';
import { StatusBadge } from '@/Components/letters/LetterBadges';
import { daysUntil, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useLetters } from '@/Components/letters/helpers';
import { AppMetricCard, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { LetterRow, Named, RoutingStatus } from '@/types/letters';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

type MyRouting = { id: string; letter_id: string; subject: string | null; number: string | null; instruction: string; due_on: string | null; status: RoutingStatus; organization: Named };

interface Props {
    counts: { awaiting_receipt: number; open: number; overdue: number; registered_today: number; my_routings: number; my_overdue: number; drafts: number; awaiting_signature: number };
    recent: LetterRow[];
    my_routings: MyRouting[];
    can: { register: boolean; compose: boolean; templates: boolean; numbering: boolean; reports: boolean };
}

/** The letters desk: what waits for the institutions in scope and for the signed-in person. */
export default function Dashboard({ counts, recent, my_routings, can }: Props): JSX.Element {
    const { lt, name } = useLetters();

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('dashboard.title')} description={lt('dashboard.description')}
            actions={(can.register || can.compose) ? (
                <div className="flex flex-wrap gap-2">
                    {can.register && <Link className={secondaryBtn} href={route('letters.register')}>{lt('nav.register')}</Link>}
                    {can.compose && <Link className={primaryBtn} href={route('letters.compose')}>{lt('nav.compose')}</Link>}
                </div>
            ) : undefined} />}>
            <Head title={lt('dashboard.title')} />
            <ActionErrors />
            <div className="space-y-6">
                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label={lt('dashboard.title')}>
                    <Link href={route('letters.index', { tab: 'inbox' })}><AppMetricCard label={lt('dashboard.awaitingReceipt')} value={counts.awaiting_receipt} variant={counts.awaiting_receipt > 0 ? 'warning' : 'neutral'} /></Link>
                    <Link href={route('letters.index')}><AppMetricCard label={lt('dashboard.open')} value={counts.open} variant="primary" detail={lt('dashboard.registeredToday', { count: counts.registered_today })} /></Link>
                    <Link href={route('letters.index')}><AppMetricCard label={lt('dashboard.overdue')} value={counts.overdue} variant={counts.overdue > 0 ? 'danger' : 'neutral'} /></Link>
                    <Link href={route('letters.index', { tab: 'mine' })}><AppMetricCard label={lt('dashboard.myRoutings')} value={counts.my_routings} variant={counts.my_overdue > 0 ? 'danger' : 'accent'} detail={counts.my_overdue > 0 ? lt('dashboard.myOverdue', { count: counts.my_overdue }) : undefined} /></Link>
                    {can.compose && <Link href={route('letters.index', { tab: 'drafts' })}><AppMetricCard label={lt('dashboard.drafts')} value={counts.drafts} variant="neutral" /></Link>}
                    {counts.awaiting_signature > 0 && <Link href={route('letters.index', { tab: 'drafts', status: 'pending_approval' })}><AppMetricCard label={lt('dashboard.awaitingSignature')} value={counts.awaiting_signature} variant="warning" /></Link>}
                </section>

                {my_routings.length > 0 && (
                    <section aria-labelledby="letters-my-routings">
                        <h2 id="letters-my-routings" className={cx(sectionTitleCls, 'mb-2')}>{lt('dashboard.myRoutingsTitle')}</h2>
                        <ul className={cx(panelCls, 'divide-y divide-(--app-border)')}>
                            {my_routings.map((r) => {
                                const overdue = r.due_on !== null && daysUntil(r.due_on) < 0;

                                return (
                                    <li key={r.id} className="flex flex-wrap items-start justify-between gap-2 px-4 py-3">
                                        <div className="min-w-0">
                                            <Link href={route('letters.show', r.letter_id)} className="text-sm font-medium underline">{r.number ? `${r.number} · ` : ''}{r.subject}</Link>
                                            <p className="mt-0.5 text-sm text-(--app-foreground)">{r.instruction}</p>
                                            <p className="text-xs text-(--app-muted-foreground)">{name(r.organization)}</p>
                                        </div>
                                        <div className="text-end text-xs">
                                            <StatusBadge kind="routingStatus" value={r.status} />
                                            {r.due_on && <p className={cx('mt-1', overdue && 'font-semibold text-red-700 dark:text-red-400')}>{lt('common.dueOn')}: <LocalizedDateDisplay value={r.due_on} /></p>}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                )}

                <section aria-labelledby="letters-recent">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 id="letters-recent" className={sectionTitleCls}>{lt('dashboard.recent')}</h2>
                        <Link href={route('letters.index')} className="text-sm underline">{lt('nav.registry')}</Link>
                    </div>
                    <LetterTable rows={recent} empty={lt('dashboard.recentEmpty')} />
                </section>

                {(can.templates || can.numbering || can.reports) && (
                    <section className="flex flex-wrap gap-2" aria-label={lt('dashboard.quickActions')}>
                        {can.templates && <Link className={secondaryBtn} href={route('letters.templates.index')}>{lt('nav.templates')}</Link>}
                        {can.numbering && <Link className={secondaryBtn} href={route('letters.numbering.index')}>{lt('nav.numbering')}</Link>}
                        {can.reports && <Link className={secondaryBtn} href={route('letters.reports')}>{lt('nav.reports')}</Link>}
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
