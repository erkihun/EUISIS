import QueueShell, { type QueueProps } from './QueueShell';
import type { JSX } from 'react';

/** Approved Changes to Implement - the implementing unit work queue. */
export default function OrganizationalChangeRequestsPendingImplementation(props: QueueProps): JSX.Element {
    return (
        <QueueShell
            {...props}
            titleKey="implementation"
            emptyKey="implementation"
            routeName="organizational-change-requests.pending-implementation"
            showAssignee
        />
    );
}
