/**
 * የህዝብ ድረ-ገጽ እና የህዝብ ድረ-ገጽ አስተዳደር የመገናኛ ጽሑፎች።
 * ይዘት እዚህ አይገባም — ከህዝብ ድረ-ገጽ አስተዳደር ይመጣል።
 */
const publicSite = {
    admin: {
        title: 'የህዝብ ድረ-ገጽ አስተዳደር', description: 'በተቋሙ ህዝባዊ ድረ-ገጽ የሚታዩ ይዘቶችን ያስተዳድሩ።',
        openSite: 'ድረ-ገጹን ክፈት', tabs: { home: 'መነሻ ገጽ', announcements: 'ማስታወቂያዎች', services: 'አገልግሎቶች', support: 'ድጋፍ', faqs: 'ተደጋጋሚ ጥያቄዎች', settings: 'ቅንብሮች እና ግርጌ' },
        pageSections: 'የገጽ ይዘት', pageHint: 'ለውጦች ከተቀመጡ በኋላ ወዲያውኑ ይታያሉ። አስፈላጊ ክፍሎችን መደበቅ አይቻልም። ዝቅተኛ የቅደም ተከተል ቁጥር ቀድሞ ይታያል።',
        contentHint: 'ዋና ጽሑፍ በእንግሊዝኛ ያስፈልጋል፤ አማርኛም መጨመር ይቻላል። ረጅም መግለጫዎች Markdown ይቀበላሉ። አዲስ ማስታወቂያና አገልግሎት እንደ ረቂቅ ይቀመጣሉ። የURL ስም በትንሽ የላቲን ፊደላትና በሰረዝ ይጻፍ። ለአገልግሎት አንድ የህዝብ ገጽ ወይም HTTPS አድራሻ ይምረጡ።',
        settingsHint: 'የድረ-ገጽ መገኘትን፣ የቢሮ መረጃንና የግርጌ ጽሑፍን እዚህ ያስተዳድሩ። አርማ፣ የድጋፍ ኢሜይል፣ ስልክና ገጽታ በስርዓት ቅንብሮች ውስጥ ይቀየራሉ።',
        newContent: 'አዲስ ይዘት', editContent: 'ይዘት አርትዕ', add: 'ይዘት ጨምር', saved: 'በተሳካ ሁኔታ ተቀምጧል።', fixErrors: 'እባክዎ የተጠቆሙ መስኮችን ያስተካክሉ።',
        none: 'የለም', empty: 'ይዘት አልተገኘም።', noAccess: 'ለእርስዎ ሚና የይዘት ክፍሎች አልተመደቡም። አስተዳዳሪውን ተገቢውን ፈቃድ ይጠይቁ።',
        discard: 'ያልተቀመጡ ለውጦች ይተዉ?', publish: 'አሁን አትም', unpublish: 'ህትመት አንሳ', archive: 'አህድር',
        confirmpublish: 'ይዘቱ አሁን ይታተም? ማስታወቂያው ያለ ማብቂያ ቀን አዲስ የህትመት ጊዜ ይጀምራል።',
        confirmunpublish: 'ይዘቱ ከህዝብ ድረ-ገጽ ተነስቶ ወደ ረቂቅ ይመለስ?', confirmarchive: 'ይዘቱ ይታህደር? በህዝብ ድረ-ገጽ ላይ አይታይም።',
        disableConfirm: 'ህዝባዊ የይዘት ገጾች ይዘጉ? የመታወቂያ ማረጋገጫ ይቀጥላል።', actionFailed: 'እርምጃው አልተጠናቀቀም። ገጹን አድስና እንደገና ሞክር።', pagination: 'የይዘት ገጾች',
        status: { draft: 'ረቂቅ', published: 'የታተመ', scheduled: 'የታቀደ', archived: 'የታህደረ', hidden: 'የተደበቀ' },
        sections: { intro: 'መግቢያ', featured_services: 'ተመራጭ አገልግሎቶች', latest_announcements: 'የቅርብ ጊዜ ማስታወቂያዎች', verification: 'የመታወቂያ ማረጋገጫ አገናኝ', support: 'የድጋፍ አገናኝ', header: 'የገጽ ርዕስ', contact: 'የመገናኛ መረጃ', faq: 'የተደጋጋሚ ጥያቄዎች ርዕስ' },
        fields: { title: 'ርዕስ', subtitle: 'ንዑስ ርዕስ', body: 'ዋና ጽሑፍ', summary: 'ማጠቃለያ', content: 'ይዘት', name: 'ስም', short_description: 'አጭር መግለጫ', full_description: 'ሙሉ መግለጫ', eligibility: 'ብቁነት', requirements: 'መስፈርቶች', steps: 'ደረጃዎች', question: 'ጥያቄ', answer: 'መልስ', slug: 'የURL ስም', code: 'ኮድ', category: 'ምድብ', contact_info: 'የመገናኛ መረጃ', action_route: 'የህዝብ ገጽ አገናኝ', action_url: 'ውጫዊ አድራሻ (HTTPS)', sort_order: 'የማሳያ ቅደም ተከተል', is_featured: 'ተመራጭ', is_published: 'የታተመ', is_visible: 'የሚታይ', max_items: 'ከፍተኛ ብዛት', primary_cta_route: 'የዋና አዝራር መዳረሻ', secondary_cta_route: 'የሁለተኛ አዝራር መዳረሻ', primary_cta_label: 'የዋና አዝራር ጽሑፍ', secondary_cta_label: 'የሁለተኛ አዝራር ጽሑፍ', button_label: 'የአዝራር ጽሑፍ' },
    },
    skipToContent: 'ወደ ዋናው ይዘት ይዝለሉ',
    mainNavigation: 'ዋና አሰሳ',
    breadcrumb: 'የመንገድ ምልክት',
    home: 'መነሻ',
    search: 'ፈልግ',
    searchAnnouncements: 'ማስታወቂያዎችን ይፈልጉ',
    searchServices: 'አገልግሎቶችን ይፈልጉ',
    category: 'ምድብ',
    allCategories: 'ሁሉም ምድቦች',
    reset: 'አጽዳ',
    apply: 'ተግብር',
    readMore: 'ማስታወቂያውን ያንብቡ',
    viewAll: 'ሁሉንም ይመልከቱ',
    viewDetails: 'ዝርዝሩን ይመልከቱ',
    published: 'የታተመበት',
    expires: 'የሚያበቃበት',
    attachments: 'አባሪዎች',
    featured: 'ተለይቶ የቀረበ',
    backToAnnouncements: 'ወደ ማስታወቂያዎች ይመለሱ',
    backToServices: 'ወደ አገልግሎቶች ይመለሱ',
    noAnnouncements: 'ምንም ማስታወቂያ አልተገኘም።',
    noAnnouncementsHint: 'ከፍለጋዎ ጋር የሚዛመድ የታተመ ማስታወቂያ የለም።',
    noServices: 'ከፍለጋዎ ጋር የሚዛመድ አገልግሎት የለም።',
    noAnnouncementsYet: 'በአሁኑ ጊዜ ምንም ማስታወቂያ የለም።',

    transferOpportunities: 'ክፍት የዝውውር እድሎች',
    viewAllTransfers: 'ሁሉም የዝውውር ማስታወቂያዎች',
    closes: 'የሚዘጋበት',
    vacancies: 'ክፍት ቦታዎች',
    openNow: 'ክፍት',
    closed: 'ተዘግቷል',

    description: 'ስለዚህ አገልግሎት',
    eligibility: 'ማን መጠቀም ይችላል',
    requirements: 'የሚያስፈልጉ ነገሮች',
    steps: 'እንዴት ማመልከት እንደሚቻል',
    contact: 'አድራሻ',
    accessService: 'ወደ አገልግሎቱ ይሂዱ',

    faqTitle: 'ተደጋግመው የሚጠየቁ ጥያቄዎች',
    contactTitle: 'አድራሻ',
    email: 'ኢሜይል',
    phone: 'ስልክ',
    officeHours: 'የሥራ ሰዓት',
    location: 'የቢሮ አድራሻ',
    helpCenter: 'የእገዛ ማዕከል',

    footerNavigation: 'ድረ-ገጽ',
    footerLinks: 'ሊንኮች',
    footerContact: 'አድራሻ',
    footerLegal: 'ሕጋዊ',

    unavailableTitle: 'ለጊዜው አይገኝም',
    unavailableDefault: 'ይህ የድረ-ገጹ ክፍል ለጊዜው አይገኝም። እባክዎ ቆይተው ይሞክሩ።',
    unavailableVerifyNote: 'የመታወቂያ ካርድ ማረጋገጫ አሁንም ይሰራል።',

    previewBanner: 'ቅድመ እይታ — ይህ ይዘት አልታተመም።',

    routes: {
        idChecker: 'የመታወቂያ ማረጋገጫ',
    },
};

export default publicSite;
