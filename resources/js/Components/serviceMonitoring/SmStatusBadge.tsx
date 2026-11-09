import StatusBadge from '@/Components/StatusBadge';
import type { JSX } from 'react';
import type { ComplianceStatus, PeriodStatus, SubmissionStatus } from '@/types/serviceMonitoring';
import { useSm } from './helpers';

/** Platform status tones for the module's states (lib/statusStyles); labels from the module dictionary. */
const TONE: Record<string, string> = {
    draft: 'draft', submitted: 'pending', returned: 'returned', accepted: 'approved', superseded: 'archived',
    scheduled: 'draft', open: 'active', closed: 'inactive', locked: 'archived',
    on_time: 'approved', late_extension: 'pending', in_review: 'pending', pending: 'draft', missing: 'rejected', not_submitted: 'inactive',
};

export default function SmStatusBadge({ kind, status }: { kind: 'submission' | 'period' | 'compliance'; status: SubmissionStatus | PeriodStatus | ComplianceStatus }): JSX.Element {
    const { sm } = useSm();
    const group = kind === 'submission' ? 'status' : kind === 'period' ? 'periodStatus' : 'compliance';

    return <StatusBadge status={TONE[status] ?? status} label={sm(`${group}.${status}`)} />;
}
