import { CheckCircle, XCircle } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { cx } from '@/Components/ui';
import { APPROVAL_ORDER, APPROVAL_STATUS, type ApprovalStatus, type ApprovalType, type TransferApplicationStatus } from './types';

type StepState = 'done' | 'current' | 'upcoming' | 'stopped';

interface Step {
    key: string;
    label: string;
    state: StepState;
}

interface Props {
    status: TransferApplicationStatus;
    approvals: { approval_type: ApprovalType | null; status: ApprovalStatus | null }[];
    /** How the stopped status reads; the portal words it for the applicant. */
    statusLabel?: string;
}

const STOPPED: TransferApplicationStatus[] = ['rejected', 'withdrawn', 'cancelled'];

/**
 * Where an application stands: received, reviewed, selected, each approval
 * the transfer needs, then the move itself. The approval steps come from the
 * application's own approval records, so a chain without a final approval
 * shows none. Before selection those records do not exist yet and the chain
 * is one "Approvals" step. A rejected or withdrawn application stops at the
 * step where it ended, marked with a cross and named in text.
 */
export default function TransferProgress({ status, approvals, statusLabel }: Props) {
    const { t } = useLocale();

    const approvalTypes = APPROVAL_ORDER.filter((type) => approvals.some((approval) => approval.approval_type === type));
    const keys = ['submitted', 'review', 'selected', ...(approvalTypes.length ? approvalTypes : ['approvals']), 'transferred'];
    const labels: Record<string, string> = {
        submitted: t('transfers.progress.submitted'),
        review: t('transfers.progress.review'),
        selected: t('transfers.progress.selected'),
        approvals: t('transfers.progress.approvals'),
        release: t('transfers.approvalTypeRelease'),
        receiving: t('transfers.approvalTypeReceiving'),
        final: t('transfers.approvalTypeFinal'),
        transferred: t('transfers.progress.transferred'),
    };

    const currentIndex = ((): number => {
        switch (status) {
            case 'submitted': return 0;
            case 'under_review':
            case 'verified': return 1;
            case 'selected': return 2;
            case 'release_pending':
            case 'receiving_pending':
            case 'final_approval_pending': {
                const type = APPROVAL_ORDER.find((candidate) => APPROVAL_STATUS[candidate] === status);
                const index = type ? keys.indexOf(type) : -1;

                return index === -1 ? 3 : index;
            }
            case 'approved':
            case 'implementation_failed': return keys.length - 1;
            case 'transferred': return keys.length;
            default: {
                // Stopped: at the approval that refused it, otherwise at review
                // (withdrawal and rejection both happen before selection).
                const refused = approvals.find((approval) => approval.status === 'rejected')?.approval_type;

                return refused ? keys.indexOf(refused) : 1;
            }
        }
    })();

    const stopped = STOPPED.includes(status);
    const steps: Step[] = keys.map((key, index) => ({
        key,
        label: labels[key],
        state: index < currentIndex ? 'done' : index === currentIndex ? (stopped ? 'stopped' : 'current') : 'upcoming',
    }));

    return (
        <div className="@container">
            <ol className="grid gap-3 @2xl:flex @2xl:gap-0" aria-label={t('transfers.progress.title')}>
                {steps.map((step, index) => (
                    <li
                        key={step.key}
                        aria-current={step.state === 'current' ? 'step' : undefined}
                        className="flex min-w-0 items-center gap-3 @2xl:flex-1 @2xl:flex-col @2xl:items-start @2xl:gap-2"
                    >
                        <div className="flex shrink-0 items-center @2xl:w-full">
                            <span
                                className={cx(
                                    'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                    step.state === 'done' && 'bg-primary text-white',
                                    step.state === 'current' && 'bg-(--app-surface) text-primary ring-2 ring-primary',
                                    step.state === 'upcoming' && 'bg-(--app-surface-muted) text-(--app-muted-foreground) ring-1 ring-(--app-border-strong)',
                                    step.state === 'stopped' && 'bg-red-50 text-red-700 ring-2 ring-red-600 dark:bg-red-950/40 dark:text-red-300',
                                )}
                            >
                                {step.state === 'done' ? <CheckCircle className="size-4" aria-hidden="true" />
                                    : step.state === 'stopped' ? <XCircle className="size-4" aria-hidden="true" />
                                    : index + 1}
                            </span>
                            {index < steps.length - 1 && (
                                <span
                                    aria-hidden="true"
                                    className={cx('mx-2 hidden h-0.5 flex-1 rounded-full @2xl:block', step.state === 'done' ? 'bg-primary' : 'bg-(--app-border)')}
                                />
                            )}
                        </div>
                        <span className="min-w-0 flex-1 @2xl:flex-none @2xl:pe-3">
                            <span className={cx('block text-sm wrap-break-word', step.state === 'upcoming' ? 'text-(--app-muted-foreground)' : 'font-medium text-(--app-foreground)')}>
                                {step.label}
                            </span>
                            <span className="sr-only">{t(`transfers.progress.state.${step.state}`)}</span>
                            {step.state === 'stopped' && (
                                <span className="block text-xs text-red-700 dark:text-red-300">{statusLabel ?? t(`status.${status}`)}</span>
                            )}
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}
