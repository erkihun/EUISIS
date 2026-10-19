/* Support help desk (docs/assistant/support-helpdesk.md). t() has no placeholders: compose in JSX. */
const support = {
    title: 'Support tickets',
    nav: 'Support tickets',

    index: {
        description: 'Ask for help with Addis One. Your tickets, their status and the support team\'s notes stay here.',
        descriptionAll: 'Every support ticket in the help desk, with its safe diagnostic context and timeline.',
        newTicket: 'New ticket',
        newTicketHint: 'Describe what you were trying to do and what happened. Do not paste passwords, codes or other people\'s personal details.',
        filterStatus: 'Status',
        filterCategory: 'Category',
        active: 'Active',
        all: 'All',
        reference: 'Reference',
        ticket: 'Ticket',
        category: 'Category',
        priority: 'Priority',
        status: 'Status',
        reporter: 'Reporter',
        team: 'Team',
        created: 'Created',
        none: 'No tickets match this view.',
        submit: 'Send ticket',
    },

    show: {
        summary: 'Summary',
        description: 'Description',
        context: 'Where it happened',
        contextHint: 'Only the page, browser and locale recorded when the ticket was filed. No cookies, tokens, headers or form data are ever stored.',
        noContext: 'No page context was recorded.',
        timeline: 'Timeline',
        manage: 'Work this ticket',
        reporter: 'Reporter',
        team: 'Team',
        assignee: 'Assigned to',
        unassigned: 'Unassigned',
        created: 'Created',
        updated: 'Last update',
        resolved: 'Resolved',
        closed: 'Closed',
        incident: 'Linked incident',
        errorReference: 'Error reference',
        module: 'Module',
        route: 'Page',
        path: 'Path',
        system: 'System',
        save: 'Save',
    },

    categories: {
        access: 'Access or permission',
        error: 'Error message',
        data: 'Wrong or missing data',
        how_to: 'How do I…',
        performance: 'Slow or unresponsive',
        other: 'Other',
    },

    priorities: {
        low: 'Low',
        normal: 'Normal',
        high: 'High',
    },

    statuses: {
        open: 'Open',
        in_progress: 'In progress',
        resolved: 'Resolved',
        closed: 'Closed',
    },

    events: {
        created: 'Ticket created',
        status_changed: 'Status changed',
        assigned: 'Assigned',
        note: 'Note',
    },

    context: {
        route: 'Page',
        path: 'Path',
        module: 'Module',
        locale: 'Language',
        browser: 'Browser',
        os: 'Operating system',
        device: 'Device',
        error_reference: 'Error reference',
        incident_reference: 'Incident',
        timestamp: 'Recorded at',
        viewport: 'Screen size',
    },

    form: {
        title: 'Short title',
        titleHint: 'Optional. Left empty, the first words of the description are used.',
        description: 'What happened?',
        category: 'Category',
        priority: 'Priority',
        status: 'Status',
        assignee: 'Assign to',
        team: 'Team',
        note: 'Note',
        noteHint: 'Shown to the reporter on the ticket timeline.',
    },

    flash: {
        created: 'Ticket created. The support team will follow up.',
        updated: 'Ticket updated.',
    },
};

export default support;
