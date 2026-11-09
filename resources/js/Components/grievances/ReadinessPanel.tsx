import { Link } from '@inertiajs/react';
import { useState, type JSX } from 'react';
import { TBody, THead, Table, Td, Th, Tr, cx } from '@euisis/ui';
import { AlertTriangle, CheckCircle, ChevronRight, XCircle } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

export type ReadinessCheck = 'committee' | 'approval_rule' | 'seal' | 'appeal_route' | 'member_accounts';

export type Readiness = {
    system: { directorate: boolean; appeal_deadline: boolean };
    organizations: { id: string; name: string; checks: Record<ReadinessCheck, boolean>; missing_accounts: number }[];
};

const CHECKS: ReadinessCheck[] = ['committee', 'approval_rule', 'seal', 'appeal_route', 'member_accounts'];

/** Where each missing piece is set up. */
const FIX: Record<ReadinessCheck | 'directorate' | 'appeal_deadline', () => string> = {
    committee: () => route('grievances.committees.index'),
    approval_rule: () => route('grievances.settings.index', { tab: 'approval_rules' }),
    seal: () => route('grievances.settings.index', { tab: 'seals' }),
    appeal_route: () => route('grievances.routes.index'),
    member_accounts: () => route('grievances.committees.index'),
    directorate: () => route('grievances.directorate.index'),
    appeal_deadline: () => route('grievances.sla.index'),
};

/**
 * Setup readiness (owner request 2026-11-08): what each institution still
 * needs before a grievance can run from filing to an issued decision and an
 * appeal. Folded to one line when everything is ready.
 */
export default function ReadinessPanel({ readiness }: { readiness: Readiness }): JSX.Element {
    const { t } = useLocale();
    const r = (key: string) => t(`grievanceCases.readiness.${key}`);
    const gaps = readiness.organizations.filter((org) => CHECKS.some((c) => !org.checks[c]));
    const systemGaps = (['directorate', 'appeal_deadline'] as const).filter((k) => !readiness.system[k]);
    const ready = gaps.length === 0 && systemGaps.length === 0;
    const [open, setOpen] = useState(!ready);
    const total = readiness.organizations.length;

    return (
        <section aria-labelledby="grievance-readiness" className={cx('rounded-panel border bg-(--app-surface)', ready ? 'border-(--app-border)' : 'border-amber-300 dark:border-amber-900/70')}>
            <h2 id="grievance-readiness" className="m-0">
                <button type="button" aria-expanded={open} onClick={() => setOpen(!open)} className="flex w-full items-center gap-3 px-5 py-4 text-left">
                    {ready ? <CheckCircle className="h-5 w-5 shrink-0 text-emerald-600" aria-hidden /> : <AlertTriangle className="h-5 w-5 shrink-0 text-amber-600" aria-hidden />}
                    <span className="min-w-0 flex-1">
                        <span className="block text-[15px] font-semibold text-(--app-foreground)">{r('title')}</span>
                        <span className="block text-[13px] text-(--app-muted-foreground)">
                            {ready ? r('allReady') : r('summary').replace('{ready}', String(total - gaps.length)).replace('{total}', String(total))}
                        </span>
                    </span>
                    <ChevronRight className={cx('h-5 w-5 shrink-0 text-(--app-muted-foreground) transition-transform', open && 'rotate-90')} aria-hidden />
                </button>
            </h2>
            {open && <div className="space-y-4 border-t border-(--app-border) px-5 py-4">
                <p className="text-sm text-(--app-muted-foreground)">{r('intro')}</p>

                {systemGaps.length > 0 && <ul className="space-y-2">
                    {systemGaps.map((k) => <li key={k} className="flex flex-wrap items-start gap-2 text-sm">
                        <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-red-600" aria-hidden />
                        <span className="flex-1"><span className="font-medium text-(--app-foreground)">{r(`system.${k}`)}</span> <span className="text-(--app-muted-foreground)">— {r(`system.${k}Help`)}</span></span>
                        <Link href={FIX[k]()} className="text-[13px] font-semibold text-primary hover:underline">{r('fix')}</Link>
                    </li>)}
                </ul>}

                {readiness.organizations.length > 0 && <div className="overflow-x-auto">
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{r('organization')}</Th>
                                {CHECKS.map((c) => <Th key={c} align="center">{r(`checks.${c}`)}</Th>)}
                            </Tr>
                        </THead>
                        <TBody>
                            {(gaps.length > 0 ? gaps : readiness.organizations).slice(0, 25).map((org) => (
                                <Tr key={org.id}>
                                    <Td><span className="font-medium text-(--app-foreground)">{org.name}</span></Td>
                                    {CHECKS.map((c) => <Td key={c} align="center">
                                        {org.checks[c]
                                            ? <CheckCircle className="mx-auto h-4 w-4 text-emerald-600" aria-label={r('ok')} />
                                            : <Link href={FIX[c]()} className="inline-flex items-center gap-1 text-[13px] font-semibold text-red-700 hover:underline dark:text-red-400" title={r(`checks.${c}Help`)}>
                                                <XCircle className="h-4 w-4" aria-hidden />{c === 'member_accounts' && org.missing_accounts > 0 ? org.missing_accounts : r('fix')}
                                            </Link>}
                                    </Td>)}
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </div>}

                <dl className="grid gap-2 text-xs text-(--app-muted-foreground) sm:grid-cols-2">
                    {CHECKS.map((c) => <div key={c}><dt className="inline font-semibold text-(--app-foreground)">{r(`checks.${c}`)}: </dt><dd className="inline">{r(`checks.${c}Help`)}</dd></div>)}
                </dl>
            </div>}
        </section>
    );
}
