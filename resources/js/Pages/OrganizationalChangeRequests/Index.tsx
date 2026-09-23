import QueueShell, { type QueueProps } from './QueueShell';
import type { JSX } from 'react';

/** My Requests: what the signed-in user has raised. */
export default function OrganizationalChangeRequestsIndex(props: QueueProps): JSX.Element {
    return (
        <QueueShell
            {...props}
            titleKey="mine"
            emptyKey="mine"
            routeName="organizational-change-requests.index"
            showCreate
        />
    );
}
