import { useForm } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { buttonClassName } from '@/Components/ui';

type GovernanceStep = 'review' | 'approve' | 'reject' | 'publish';

/**
 * The governed lifecycle of a hierarchy version, as the server describes it:
 * who prepared, submitted, approved and published it, what it changes against
 * the structure in force, and only the steps this administrator may take now.
 * The server decides every step again (separation of duties, effective date).
 */
export type HierarchyGovernance = {
    status: string;
    impact: Record<'organizations' | 'units' | 'positions' | 'employees' | 'assignments' | 'users', number>;
    steps: Array<{ key: 'created' | 'submitted' | 'approved' | 'published'; by: string | null; at: string | null }>;
    approval_blocked: boolean;
    publication_blocked: 'effective_date' | 'structure_changed' | null;
    can: Record<GovernanceStep, boolean>;
    urls: Record<GovernanceStep, string>;
};

const IMPACT_KEYS = ['organizations', 'units', 'positions', 'employees', 'assignments', 'users'] as const;

export default function HierarchyGovernancePanel({ governance }: { governance: HierarchyGovernance }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm({});
    const errors = form.errors as Record<string, string | undefined>;
    const error = errors.status ?? errors.effective_from ?? errors.parent_organization_id;

    const post = async (step: GovernanceStep) => {
        if (step === 'publish' || step === 'reject') {
            const { confirmed } = await confirm({
                title: t(`hierarchyVersions.governance.${step}ConfirmTitle`),
                description: t(`hierarchyVersions.governance.${step}ConfirmDescription`),
                confirmLabel: t(`hierarchyVersions.governance.${step}`),
                cancelLabel: t('confirmations.cancel'),
                variant: step === 'publish' ? 'warning' : 'danger',
            });
            if (!confirmed) return;
        }
        form.post(governance.urls[step], { preserveScroll: true });
    };

    return (
        <section className="mb-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-5" aria-labelledby="hierarchy-governance-title">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 id="hierarchy-governance-title" className="text-sm font-semibold text-(--app-foreground)">{t('hierarchyVersions.governance.title')}</h3>
                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('hierarchyVersions.governance.description')}</p>
                </div>
                <StatusBadge status={governance.status} label={t(`hierarchyVersions.governance.states.${governance.status}`)} />
            </div>

            <ol className="mt-4 grid gap-3 sm:grid-cols-4">
                {governance.steps.map((step) => (
                    <li key={step.key} className="rounded-card border border-(--app-border) px-3 py-2.5">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{t(`hierarchyVersions.governance.steps.${step.key}`)}</p>
                        {step.at ? (
                            <>
                                {step.by && <p className="mt-1 text-sm font-medium text-(--app-foreground)">{step.by}</p>}
                                <p className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={step.at} withTime /></p>
                            </>
                        ) : (
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">{t('hierarchyVersions.governance.notYet')}</p>
                        )}
                    </li>
                ))}
            </ol>

            <div className="mt-4">
                <p className="text-xs font-medium text-(--app-muted-foreground)">{t('hierarchyVersions.governance.impactTitle')}</p>
                <dl className="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {IMPACT_KEYS.map((key) => (
                        <div key={key} className="rounded-card bg-(--app-surface-muted) px-3 py-2">
                            <dt className="text-xs text-(--app-muted-foreground)">{t(`hierarchyVersions.governance.impact.${key}`)}</dt>
                            <dd className="mt-0.5 text-lg font-semibold text-(--app-foreground)">{governance.impact[key]}</dd>
                        </div>
                    ))}
                </dl>
            </div>

            {governance.approval_blocked && (
                <p className="mt-4 rounded-card bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-foreground)">{t('hierarchyVersions.governance.approvalBlocked')}</p>
            )}
            {governance.publication_blocked && (
                <p className="mt-4 rounded-card bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-foreground)">
                    {t(`hierarchyVersions.governance.publishBlocked.${governance.publication_blocked}`)}
                </p>
            )}
            {error && <p className="mt-4 text-sm text-red-600 dark:text-red-400" role="alert">{error}</p>}

            {(governance.can.review || governance.can.approve || governance.can.publish || governance.can.reject) && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {(['review', 'approve', 'publish'] as const).filter((step) => governance.can[step]).map((step) => (
                        <button key={step} type="button" className={buttonClassName({ variant: 'primary', size: 'sm' })} disabled={form.processing} onClick={() => post(step)}>
                            {t(`hierarchyVersions.governance.${step}`)}
                        </button>
                    ))}
                    {governance.can.reject && (
                        <button type="button" className={buttonClassName({ variant: 'outline', size: 'sm' })} disabled={form.processing} onClick={() => post('reject')}>
                            {t('hierarchyVersions.governance.reject')}
                        </button>
                    )}
                </div>
            )}
        </section>
    );
}
