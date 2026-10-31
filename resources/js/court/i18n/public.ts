/**
 * Text the React public pages, the landing-page manager and the staff account
 * pages need that no lang/{en,am}/*.php key provides (the Blade pages
 * hard-coded it in English). To be merged into court/i18n/{en,am}.ts under
 * `court.publicPages`; until then court/i18n/usePublicText.ts reads it
 * directly as the fallback.
 */
const publicPages = {
    en: {
        // Landing-page manager
        tabs_label: 'Landing page sections',
        hidden: 'Hidden',
        hide: 'Hide',
        show: 'Show',
        scheduled: 'Scheduled',
        current_image: 'Current image',
        current_file: 'Current file',
        services_default_cards: 'No service cards saved yet — the page shows the 6 built-in default cards.',
        features_placeholder: 'Feature one\nFeature two\nFeature three',
        footer_description_placeholder: 'A brief description of the organization shown in the footer…',
        address_placeholder: 'Addis Ababa, Ethiopia',
        cta_title_placeholder: 'Ready to engage with the tribunal?',
        cta_description_placeholder: 'File a case, track an existing matter…',
        section_visible: 'Show this section on the home page',
        // Public layout
        staff_sign_in: 'Staff sign-in',
        follow_us: 'Follow us',
        // Home page carousel
        slides_label: 'Highlights',
        slide_position: 'Slide :number of :total',
        slide_previous: 'Previous slide',
        slide_next: 'Next slide',
        slide_go_to: 'Go to slide :number',
        slides_pause: 'Pause the slideshow',
        slides_play: 'Play the slideshow',
        // Resources page (the Blade page's English words)
        resources_page_badge: 'Court Resources',
        resources_page_title: 'Resources & Publications',
        resources_page_description: 'Official publications, downloadable forms, court documents, and updates.',
        resources_filter_label: 'Show resources of type',
        resources_filter_all: 'All',
        resources_filter_post: 'Posts',
        resources_filter_form: 'Forms',
        resources_filter_document: 'Documents',
        resources_filter_link: 'Links',
        resources_empty: 'No resources published yet.',
        resources_empty_type: 'No resources of this type are published.',
        // Staff account pages
        secure_access: 'Secure staff access',
    },
    am: {
        tabs_label: 'የመነሻ ገጽ ክፍሎች',
        hidden: 'የተደበቀ',
        hide: 'ደብቅ',
        show: 'አሳይ',
        scheduled: 'የታቀደ፦',
        current_image: 'የአሁኑ ምስል',
        current_file: 'የአሁኑ ፋይል',
        services_default_cards: 'እስካሁን የተቀመጠ የአገልግሎት ካርድ የለም፤ ገጹ አብረው የተዘጋጁትን 6 ነባሪ ካርዶች ያሳያል።',
        features_placeholder: 'የመጀመሪያ ባህሪ\nሁለተኛ ባህሪ\nሦስተኛ ባህሪ',
        footer_description_placeholder: 'በገጹ ግርጌ የሚታይ የተቋሙ አጭር መግለጫ…',
        address_placeholder: 'አዲስ አበባ፣ ኢትዮጵያ',
        cta_title_placeholder: 'ከፍርድ ቤቱ ጋር ለመገናኘት ዝግጁ ነዎት?',
        cta_description_placeholder: 'ጉዳይ ያቅርቡ፣ ያለ ጉዳይን ይከታተሉ…',
        section_visible: 'ይህን ክፍል በመነሻ ገጹ ላይ አሳይ',
        staff_sign_in: 'የሠራተኞች መግቢያ',
        follow_us: 'ይከተሉን',
        slides_label: 'ዋና ማስታወቂያዎች',
        slide_position: 'ማስታወቂያ :number ከ:total',
        slide_previous: 'ያለፈው ማስታወቂያ',
        slide_next: 'ቀጣዩ ማስታወቂያ',
        slide_go_to: 'ወደ ማስታወቂያ :number ይሂዱ',
        slides_pause: 'የማስታወቂያዎቹን መቀያየር ያቁሙ',
        slides_play: 'የማስታወቂያዎቹን መቀያየር ያስጀምሩ',
        resources_page_badge: 'የችሎቱ መረጃዎች',
        resources_page_title: 'መረጃዎችና ህትመቶች',
        resources_page_description: 'ይፋዊ ህትመቶች፣ የሚወርዱ ቅጾች፣ የችሎቱ ሰነዶችና ወቅታዊ መረጃዎች።',
        resources_filter_label: 'መረጃዎችን በዓይነት ያሳዩ',
        resources_filter_all: 'ሁሉም',
        resources_filter_post: 'ማሳወቂያዎች',
        resources_filter_form: 'ቅጾች',
        resources_filter_document: 'ሰነዶች',
        resources_filter_link: 'አገናኞች',
        resources_empty: 'እስካሁን የታተመ መረጃ የለም።',
        resources_empty_type: 'በዚህ ዓይነት የታተመ መረጃ የለም።',
        secure_access: 'ደህንነቱ የተጠበቀ የሠራተኞች መግቢያ',
    },
} as const;

export type PublicPagesTextKey = keyof typeof publicPages.en;

export default publicPages as { en: Record<PublicPagesTextKey, string>; am: Record<PublicPagesTextKey, string> };
