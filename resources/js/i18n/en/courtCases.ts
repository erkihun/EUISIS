/* courtCases — planned standalone module (docs/court-cases.md). Placeholder page only. */
const courtCases = {
    title: 'Court Cases',
    description: 'Management of court-related cases involving employees and institutions will be implemented as a dedicated module in a future phase.',
    planned: 'Planned Module',
    notice: 'No court case records are kept in {brand} yet. This page will open the module once it has been designed and approved.',

    /* My Portal > My court cases: the employee's cases at the administrative tribunal (court-ms, Flow 4). */
    portal: {
        nav: 'My court cases',
        title: 'My court cases',
        description: 'Cases you filed at the administrative tribunal. Filing, replying and following a case open the court portal, already signed in.',
        openPortal: 'Open the court portal',
        fileCase: 'File a new case',
        openCase: 'Open',
        viewNotices: 'View in the court portal',
        opensInNewTab: 'Opens the court portal in a new tab',
        counts: {
            cases: 'Cases',
            open: 'Open',
            awaitingReview: 'Awaiting review',
            upcomingHearings: 'Upcoming hearings',
            unreadNotices: 'Unread notices',
        },
        table: {
            caseNumber: 'Case number',
            title: 'Title',
            type: 'Type',
            status: 'Status',
            review: 'Review',
            filed: 'Filed',
            nextHearing: 'Next hearing',
            actions: 'Actions',
        },
        status: {
            pending: 'Pending',
            active: 'Active',
            adjourned: 'Adjourned',
            dismissed: 'Dismissed',
            closed: 'Closed',
        },
        review: {
            awaiting_review: 'Awaiting review',
            returned: 'Returned for correction',
            rejected: 'Rejected',
            accepted: 'Accepted',
        },
        noHearing: 'None scheduled',
        noCases: 'You have no court cases yet.',
        noticesTitle: 'Unread notices',
        noNotices: 'No unread notices.',
        notLinkedTitle: 'No court account yet',
        notLinked: 'The first time you open the court portal, your court account is created from your employee record (name, national ID, phone and email). You will not need a separate password.',
        disabled: 'The court portal is not connected to {brand} yet.',
        unavailable: 'The court system cannot be reached right now, so your cases cannot be shown. You can still try to open the court portal.',
        noEmployee: 'Your account is not linked to an employee record, so it has no court cases.',
        errors: {
            linked_to_another_employee: 'The court account with your national ID or email belongs to another employee. Contact the court registry.',
            identity_mismatch: 'A court account uses your email with a different national ID. Contact the court registry.',
            employee_record_incomplete: 'Your employee record needs a national ID, phone number and email before a court account can be opened. Ask HR to complete it.',
            phone_in_use: 'Another court account uses your phone number. Contact the court registry.',
            account_inactive: 'Your court account is deactivated. Contact the court registry.',
            unknown_target: 'That case is not one of yours in the court system.',
            unavailable: 'The court system cannot be reached right now. Try again shortly.',
        },
    },
};

export default courtCases;
