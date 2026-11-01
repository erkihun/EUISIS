/**
 * Props of the public site pages (Court/Public/*), the landing-page manager
 * (Court/Landing/Index) and the staff account pages (Court/Auth/*).
 * Dates are ISO 8601 strings.
 */

/** Footer and navigation data the public layout shows (App\Support\Ui\PublicSiteChrome). */
export interface PublicSiteChrome {
    /** The landing manager's footer and contact settings in the page's language; null when none were saved. */
    footer: PublicFooter | null;
    /** The user manual is switched on, has content and is linked from the navigation. */
    userManualEnabled: boolean;
    /** The manual's title saved for the request's language; null falls back to the translation. */
    userManualTitle?: string | null;
    /** Where parties file and follow their cases (EUISIS My Portal); null while that is not set. */
    portalUrl?: string | null;
}

/** The footer and contact settings as the public pages read them (App\Support\Ui\PublicSiteChrome). */
export interface PublicFooter {
    description: string | null;
    authority_name: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    contact_address: string | null;
    office_hours: string | null;
    map_url: string | null;
    social_facebook: string | null;
    social_twitter: string | null;
    social_linkedin: string | null;
    social_youtube: string | null;
    social_telegram: string | null;
    social_instagram: string | null;
}

export interface PublicTermsProps {
    term: {
        id: number;
        title: string;
        /** Purified on the server (profile `cases`) and again by the viewer. */
        body: string;
        published_at: string | null;
    };
    publicSite: PublicSiteChrome;
}

export interface PublicUserManualProps {
    title: string;
    /** Manual HTML for the request's language, sanitized when it was saved. */
    content: string;
    publicSite: PublicSiteChrome;
}

/* ── Home and resources pages (PublicHomeController) ─────────────────── */

/**
 * One hero slide: the landing manager's slide, or one of the three built-in
 * slides (always in Amharic, whose saved slides are English-only text).
 */
export interface PublicHomeSlide {
    badge: string;
    title: string;
    description: string;
    primary_label: string;
    primary_href: string;
    /** '' hides the second button. */
    secondary_label: string;
    secondary_href: string;
    style: SlideStyle;
    image_url: string | null;
}

/** A section's heading, resolved on the server (saved text, else the translation). */
export interface PublicHomeSection {
    visible: boolean;
    title: string;
    subtitle: string;
}

export type PublicHomeSectionKey = 'about' | 'members' | 'services' | 'hearings' | 'metrics' | 'process' | 'news' | 'resources' | 'faq' | 'contact';

export interface PublicHomeMetric {
    key: MetricKey;
    label: string;
    description: string;
    value: number;
}

export interface PublicHomeStep {
    title: string;
    description: string;
    meta: string;
    duration: string;
    color: StepColor;
}

export interface PublicHomeService {
    title: string;
    description: string;
    meta: string;
    features: string[];
    icon: ServiceIcon;
    accent: ServiceAccent;
    /** Where the card leads (a site path, an anchor or a web address); null for a card without a link. */
    href: string | null;
}

export interface PublicMember {
    name: string;
    role: string;
    bio: string;
    photo_url: string | null;
}

/** A sitting of the coming days: case number and type, never a party's name. */
export interface PublicHearing {
    id: string;
    /** Y-m-d */
    date: string;
    /** HH:MM, as stored */
    time: string | null;
    case_number: string;
    case_type: string | null;
    type: string | null;
    location: string | null;
}

export interface PublicNewsCard {
    id: number;
    title: string;
    excerpt: string;
    created_at: string | null;
}

export interface PublicResource {
    id: number;
    title: string;
    type: ResourceType;
    description: string | null;
    is_featured: boolean;
    published_at: string | null;
    /** The uploaded file of a form or document. */
    download_url: string | null;
    external_url: string | null;
    cover_image_url: string | null;
}

export interface PublicHomeProps {
    slides: PublicHomeSlide[];
    sections: Record<PublicHomeSectionKey, PublicHomeSection>;
    /** The mandate (paragraphs split by an empty line) and key facts; null while the section is hidden. */
    about: { body: string; facts: Array<{ label: string; value: string }> } | null;
    /** Empty while the section is hidden, as are the lists below. */
    members: PublicMember[];
    hearings: PublicHearing[];
    today: { date: string; hearings: number };
    news: PublicNewsCard[];
    contactVisible: boolean;
    metrics: PublicHomeMetric[];
    steps: PublicHomeStep[];
    services: PublicHomeService[];
    /** The first six active resources. */
    resources: PublicResource[];
    hasMoreResources: boolean;
    faqs: Array<{ question: string; answer: string }>;
    publicSite: PublicSiteChrome;
}

export interface PublicResourcesProps {
    resources: PublicResource[];
    publicSite: PublicSiteChrome;
}

export interface PublicNewsProps {
    items: PublicNewsCard[];
    pagination: { current: number; last: number; total: number };
    publicSite: PublicSiteChrome;
}

export interface PublicNewsItemProps {
    item: { id: number; title: string; content: string; created_at: string | null };
    publicSite: PublicSiteChrome;
}

export interface SignageHearing {
    id: number;
    /** The stored time of day (HH:MM), as the public site's hearing list shows it. */
    time: string | null;
    case_number: string | null;
    case_type: string | null;
    /** The sitting (a free-text kind of hearing) and the room. */
    type: string | null;
    location: string | null;
    /** The bench (ችሎት) the case is assigned to; null while it has none. */
    bench_id: number | null;
    bench: string | null;
    /** The parties, sent for the next sitting only: the plaintiff (and how many more on that side) and the defendant. */
    parties?: { plaintiff: string | null; plaintiff_others: number; defendant: string | null };
}

/** One day's cause list: its date (YYYY-MM-DD, the court's zone) and its sittings in time order. */
export interface SignageDay {
    date: string;
    hearings: SignageHearing[];
}

export interface PublicSignageProps {
    /** The server's clock (ISO 8601, the court's time zone) when the board was read. */
    now: string;
    timezone: string;
    /** The court's benches in a fixed order, so each keeps its colour. */
    benches: Array<{ id: number; name: string }>;
    today: SignageDay;
    /** The next day with sittings within two weeks, shown once today's are over; null when none. */
    nextDay: SignageDay | null;
    /** Cases whose decision was released to the parties lately (published and approved). */
    decisions: Array<{ case_number: string; released: string }>;
    /** The court's figures; counts only. */
    stats: {
        hearings_today: number;
        hearings_week: number;
        filed_today: number;
        decisions_month: number;
        total_cases: number;
        by_status: Array<{ label: string; total: number }>;
    };
    /** The coming seven days with sittings, today first, and how many each. */
    week: Array<{ date: string; count: number }>;
    announcements: Array<{ id: number; title: string; summary: string; created_at: string | null }>;
    /** The registry's office hours, phone and address (landing manager contact settings); null when not set. */
    registry: { office_hours: string | null; phone: string | null; address: string | null };
    /** Where parties follow their case, with a QR code (SVG markup) to it. */
    portal: { url: string; qr: string | null };
    /** Seconds between automatic refreshes of the board. */
    refreshSeconds: number;
}

/* ── Landing-page manager ─────────────────────────────────────────────── */

export type LandingTab = 'slides' | 'about' | 'members' | 'metrics' | 'timeline' | 'services' | 'resources' | 'manual' | 'faqs' | 'footer' | 'sections';

/** A row's Amharic text, field => text ('' when not written; features one per array item). */
export type AmharicText<F extends string> = Record<F, string>;

export type SlideStyle = 'blue' | 'orange' | 'emerald';
export type StepColor = 'blue' | 'orange' | 'emerald' | 'violet' | 'amber';
export type ServiceAccent = StepColor | 'pink';
export type ServiceIcon = 'document' | 'calendar' | 'lock' | 'chart' | 'database' | 'chat';
export type ResourceType = 'post' | 'form' | 'document' | 'link';

export interface LandingSlide {
    id: number;
    badge: string | null;
    title: string;
    description: string | null;
    primary_label: string;
    primary_href: string;
    secondary_label: string | null;
    secondary_href: string | null;
    bg_style: SlideStyle;
    bg_image: string | null;
    bg_image_url: string | null;
    is_active: boolean;
    am: AmharicText<'badge' | 'title' | 'description' | 'primary_label' | 'secondary_label'>;
}

export interface LandingStep {
    id: number;
    title: string;
    description: string | null;
    meta: string | null;
    duration: string | null;
    color: StepColor;
    is_active: boolean;
    am: AmharicText<'title' | 'description' | 'meta' | 'duration'>;
}

export interface LandingService {
    id: number;
    title: string;
    description: string | null;
    meta: string | null;
    features: string[];
    icon_type: ServiceIcon;
    accent: ServiceAccent;
    href: string | null;
    is_active: boolean;
    am: AmharicText<'title' | 'description' | 'meta'> & { features: string[] };
}

export interface LandingResource {
    id: number;
    title: string;
    type: ResourceType;
    description: string | null;
    external_url: string | null;
    is_featured: boolean;
    is_active: boolean;
    published_at: string | null;
    /** `published_at` as a datetime-local value (Y-m-d\TH:i). */
    published_at_local: string | null;
    is_scheduled: boolean;
    file_path: string | null;
    cover_image: string | null;
    cover_image_url: string | null;
    am: AmharicText<'title' | 'description'>;
}

export interface LandingFaq {
    id: number;
    question: string;
    answer: string;
    is_active: boolean;
    am: AmharicText<'question' | 'answer'>;
}

export interface LandingMember {
    id: number;
    name: string;
    role: string | null;
    bio: string | null;
    photo_url: string | null;
    is_active: boolean;
    am: AmharicText<'name' | 'role' | 'bio'>;
}

export interface LandingFact {
    label: string;
    value: string;
    label_am: string;
    value_am: string;
}

export interface LandingAbout {
    body: string;
    body_am: string;
    facts: LandingFact[];
}

export type MetricKey = 'total_cases' | 'resolved_cases' | 'pending_cases' | 'active_caseload' | 'hearings_this_week' | 'avg_resolution_time';

export interface MetricText {
    label: string;
    description: string;
    label_am?: string;
    description_am?: string;
}

export interface SectionSetting {
    visible: boolean;
    title?: string | null;
    subtitle?: string | null;
    title_am?: string | null;
    subtitle_am?: string | null;
    description?: string | null;
    primary_label?: string | null;
    primary_href?: string | null;
    secondary_label?: string | null;
    secondary_href?: string | null;
}

export type SectionKey = 'about' | 'members' | 'services' | 'hearings' | 'metrics' | 'process' | 'news' | 'resources' | 'faq' | 'contact' | 'cases' | 'cta';

/** The sections whose title and subtitle the manager edits, in page order. */
export const TITLED_SECTIONS = ['about', 'members', 'services', 'hearings', 'metrics', 'process', 'news', 'resources', 'faq', 'contact'] as const;

export interface LandingFooter {
    description: string | null;
    description_am: string | null;
    authority_name: string | null;
    authority_name_am: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    contact_address: string | null;
    contact_address_am: string | null;
    office_hours: string | null;
    office_hours_am: string | null;
    map_url: string | null;
    social_facebook: string | null;
    social_twitter: string | null;
    social_linkedin: string | null;
    social_youtube: string | null;
    social_telegram: string | null;
    social_instagram: string | null;
}

export interface LandingUserManual {
    title_en: string;
    title_am: string;
    content_en: string;
    content_am: string;
    is_active: boolean;
}

export interface LandingIndexProps {
    initialTab: LandingTab;
    homeUrl: string;
    slides: LandingSlide[];
    steps: LandingStep[];
    services: LandingService[];
    resources: LandingResource[];
    faqs: LandingFaq[];
    members: LandingMember[];
    about: LandingAbout;
    /** Live figures the statistics cards show. */
    stats: Record<MetricKey, number>;
    /** Saved card labels and descriptions ('' when not overridden). */
    metrics: Record<MetricKey, MetricText>;
    /** The English defaults, shown as placeholders. */
    metricDefaults: Record<MetricKey, MetricText>;
    sections: Record<SectionKey, SectionSetting>;
    footer: Record<keyof LandingFooter, string>;
    userManual: LandingUserManual;
}

/* ── Staff account pages ──────────────────────────────────────────────── */

export interface ForgotPasswordProps {
    status: string | null;
    email: string;
}
