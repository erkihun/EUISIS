import QueueShell, { type QueueProps } from './QueueShell';
import type { JSX } from 'react';

/** Everything awaiting a review decision inside the reviewer scope. */
export default function OrganizationalChangeRequestsReviewQueue(props: QueueProps): JSX.Element {
    return (
        <QueueShell
            {...props}
            titleKey="review"
            emptyKey="review"
            routeName="organizational-change-requests.review-queue"
        />
    );
}
