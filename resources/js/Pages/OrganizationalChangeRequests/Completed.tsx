import QueueShell, { type QueueProps } from './QueueShell';
import type { JSX } from 'react';

/** Implemented, completed, rejected and cancelled requests. */
export default function OrganizationalChangeRequestsCompleted(props: QueueProps): JSX.Element {
    return (
        <QueueShell
            {...props}
            titleKey="completed"
            emptyKey="completed"
            routeName="organizational-change-requests.completed"
        />
    );
}
