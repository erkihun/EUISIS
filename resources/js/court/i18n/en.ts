// court-ms's dictionary, merged over the EUISIS one (scripts/sync-from-euisis.mjs patches i18n/registry.ts).
// `cm.*` is court-ms's own lang/en/*.php, exported by `php artisan court:client-lang`, so a React
// screen says what the Blade screen it replaces said; `court.*` is text only the React screens need.
import lang from './lang/en';
import caseDetail from './caseDetail';
import access from './access';
import decisionForms from './decisions';
import letterForms from './letters';
import inspectionPages from './inspections';
import performancePages from './performance';
import applicantCases from './applicantCases';
import respondentPages from './respondent';
import applicantPages from './applicant';
import publicPages from './public';
import signIn from './signIn';
import publicSite from './publicSite';

export default {
    cm: lang,
    // The sign-in page's story panel (EUISIS Pages/Auth/Login reads these fixed keys, so they keep EUISIS's
    // key names while telling the court's own story: a case from filing to appeal, and what the system holds).
    auth: {
        stage: {
            headline: 'One register. Every case.',
            headlineAccent: 'From filing to decision.',
            steps: {
                city: { title: 'Filing', text: 'An applicant files a case, online or at the counter' },
                institutions: { title: 'Review', text: 'The registry checks the file and assigns it' },
                employees: { title: 'Hearing', text: 'Hearings scheduled, parties notified, notes kept' },
                card: { title: 'Decision', text: 'Drafted from a template, published, served' },
                verified: { title: 'Appeal', text: 'Contested decisions followed to their end' },
            },
            cardTitle: 'Case file',
            cardVerified: 'Decided',
            services: {
                cafeteria: 'Cases',
                transport: 'Hearings',
                performance: 'Decisions',
                grievance: 'Letters',
                activity: 'Inspections',
                reports: 'Reports',
            },
            capsLock: 'Caps Lock is on',
        },
    },
    court: {
        workspaceName: 'Court Case Management',
        exit: 'Leave the court workspace',
        nav: {
            landing: 'Landing Page',
        },
        decisions: {
            deleteConfirm: 'Delete this decision? This cannot be undone.',
        },
        terms: {
            viewPublic: 'View public page',
        },
        audit: {
            title: 'End-to-End Audit Trail',
            description: 'System, case and model events in one timeline.',
            search: 'Search',
            searchPlaceholder: 'Action, request id, IP…',
            source: 'Source',
            outcome: 'Outcome',
            actorType: 'Actor type',
            method: 'Method',
            dateFrom: 'Date from',
            dateTo: 'Date to',
            all: 'All',
            sources: { system: 'System activity', case: 'Case activity', legacy_model: 'Model history' },
            columns: { time: 'Time / source', action: 'Action', actor: 'Actor', target: 'Target', request: 'Request', details: 'Details' },
            viewDetails: 'View details',
            noDetails: 'No additional details',
            empty: 'No audit events match.',
        },
        caseDetail: caseDetail.en,
        accessForms: access.en,
        decisionForms: decisionForms.en,
        letterForms: letterForms.en,
        inspectionPages: inspectionPages.en,
        performancePages: performancePages.en,
        applicantCases: applicantCases.en,
        publicPages: publicPages.en,
        signIn: signIn.en,
        publicSite: publicSite.en,
        respondentPages: respondentPages.en,
        applicantPages: applicantPages.en,
        settings: {
            secretSaved: 'Saved',
            secretNotSet: 'Not set',
            secretRemove: 'Remove the saved value',
            secretKeep: 'Leave blank to keep the saved value. It is never displayed.',
            none: 'None',
        },
        appeals: {
            number: 'Appeal #',
            decidedBy: 'Decided by',
            newAppeal: 'New appeal',
            open: 'Open',
            empty: 'No appeals yet.',
            editTitle: 'Edit appeal',
            case: 'Case',
            chooseCase: 'Choose a case…',
            title: 'Title',
            grounds: 'Grounds',
            save: 'Save',
            cancel: 'Cancel',
            submit: 'Submit appeal',
            edit: 'Edit',
            documents: 'Documents',
            documentLabel: 'Label',
            documentFile: 'File',
            upload: 'Upload',
            download: 'Download',
            remove: 'Remove',
            removeConfirm: 'Remove this document?',
            noDocuments: 'No documents attached.',
            decide: 'Decision',
            decision: 'Decision',
            decisionNotes: 'Notes (optional)',
            record: 'Record decision',
            decisionApproved: 'Approved',
            decisionRejected: 'Rejected',
            decisionClosed: 'Closed',
            decidedAt: 'Decided',
            submittedAt: 'Submitted',
        },
        hearings: {
            date: 'Date',
            dateTime: 'Date & time',
            type: 'Hearing name',
            hearing: 'Hearing',
            createdBy: 'Created by',
            location: 'Location',
            notes: 'Notes',
        },
        dashboard: {
            period: 'Period',
            ranges: {
                all: 'All time',
                '7d': 'Last 7 days',
                '30d': 'Last 30 days',
                '90d': 'Last 90 days',
                '6m': 'Last 6 months',
                '12m': 'Last 12 months',
            },
            channels: {
                mail: 'Email',
                sms: 'SMS',
                telegram: 'Telegram',
                in_app: 'In-app',
            },
        },
    },
};
