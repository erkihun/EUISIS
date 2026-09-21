/**
 * Interface labels for the public website and Public Site Management.
 *
 * Only chrome lives here — button captions, headings of fixed UI, empty
 * states. Every piece of public CONTENT (titles, descriptions, services,
 * announcements, FAQs) comes from Public Site Management, bilingual, and is
 * never hard-coded in a page.
 */
const publicSite = {
    admin: {
        title: 'Public Site Management', description: 'Manage content published on the institution’s public website.',
        openSite: 'Open public site', tabs: { home: 'Homepage', announcements: 'Announcements', services: 'Services', support: 'Support', faqs: 'FAQs', settings: 'Site settings & footer' },
        pageSections: 'Page content', pageHint: 'Changes are visible immediately after saving. Required sections cannot be hidden. Lower order numbers appear first.',
        contentHint: 'English is required for primary text; Amharic is supported alongside it. Long descriptions accept Markdown. New announcements and services are saved as drafts. Use a lowercase, hyphenated slug. Service actions accept one public route or an HTTPS URL.',
        settingsHint: 'Manage site availability, office information and footer text here. Shared logos, support email/phone and theme remain in System Settings.',
        newContent: 'New content', editContent: 'Edit content', add: 'Add content', saved: 'Saved successfully.', fixErrors: 'Please correct the highlighted fields.',
        none: 'None', empty: 'No content found.', noAccess: 'Your role has no content sections assigned. Ask an administrator to grant the appropriate public-site permissions.',
        discard: 'Discard unsaved changes?', publish: 'Publish now', unpublish: 'Unpublish', archive: 'Archive',
        confirmpublish: 'Publish this content now? For announcements, this starts a new publication window with no expiry.',
        confirmunpublish: 'Remove this content from the public website and return it to draft?', confirmarchive: 'Archive this content? It will no longer appear publicly.',
        disableConfirm: 'Disable public content pages? ID verification will remain available.', actionFailed: 'The action could not be completed. Refresh and try again.', pagination: 'Content pages',
        status: { draft: 'Draft', published: 'Published', scheduled: 'Scheduled', archived: 'Archived', hidden: 'Hidden' },
        sections: { intro: 'Introduction', featured_services: 'Featured services', latest_announcements: 'Latest announcements', verification: 'ID verification link', support: 'Support link', header: 'Page header', contact: 'Contact information', faq: 'FAQ heading' },
        fields: { title: 'Title', subtitle: 'Subtitle', body: 'Body', summary: 'Summary', content: 'Content', name: 'Name', short_description: 'Short description', full_description: 'Full description', eligibility: 'Eligibility', requirements: 'Requirements', steps: 'Steps', question: 'Question', answer: 'Answer', slug: 'URL slug', code: 'Code', category: 'Category', contact_info: 'Contact information', action_route: 'Public action route', action_url: 'External action URL (HTTPS)', sort_order: 'Display order', is_featured: 'Featured', is_published: 'Published', is_visible: 'Visible', max_items: 'Maximum items', primary_cta_route: 'Primary button destination', secondary_cta_route: 'Secondary button destination', primary_cta_label: 'Primary button label', secondary_cta_label: 'Secondary button label', button_label: 'Button label' },
    },
    skipToContent: 'Skip to main content',
    mainNavigation: 'Main navigation',
    breadcrumb: 'Breadcrumb',
    home: 'Home',
    search: 'Search',
    searchAnnouncements: 'Search announcements',
    searchServices: 'Search services',
    category: 'Category',
    allCategories: 'All categories',
    reset: 'Reset',
    apply: 'Apply',
    readMore: 'Read announcement',
    viewAll: 'View all',
    viewDetails: 'View details',
    published: 'Published',
    expires: 'Available until',
    attachments: 'Attachments',
    featured: 'Featured',
    backToAnnouncements: 'Back to announcements',
    backToServices: 'Back to services',
    noAnnouncements: 'No announcements found.',
    noAnnouncementsHint: 'There are no published announcements matching your search.',
    noServices: 'No services match your search.',
    noAnnouncementsYet: 'There are no announcements at the moment.',

    transferOpportunities: 'Open transfer opportunities',
    viewAllTransfers: 'All transfer announcements',
    closes: 'Closes',
    vacancies: 'Vacancies',
    openNow: 'Open',
    closed: 'Closed',

    description: 'About this service',
    eligibility: 'Who can use it',
    requirements: 'What you need',
    steps: 'How to apply',
    contact: 'Contact',
    accessService: 'Go to service',

    faqTitle: 'Frequently asked questions',
    contactTitle: 'Contact',
    email: 'Email',
    phone: 'Phone',
    officeHours: 'Office hours',
    location: 'Office location',
    helpCenter: 'Help center',

    footerNavigation: 'Site',
    footerLinks: 'Links',
    footerContact: 'Contact',
    footerLegal: 'Legal',

    unavailableTitle: 'Temporarily unavailable',
    unavailableDefault: 'This part of the website is temporarily unavailable. Please try again later.',
    unavailableVerifyNote: 'ID card verification remains available.',

    previewBanner: 'Preview — this content is not published.',

    routes: {
        idChecker: 'ID Checker',
    },
};

export default publicSite;
