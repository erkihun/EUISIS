const ui = {
    // Table
    showingResults: 'ውጤቶችን እያሳየ ነው',
    noResults: 'ምንም ውጤት አልተገኘም',
    noData: 'ምንም ውሂብ የለም',
    loading: 'በመጫን ላይ…',
    loadingData: 'ውሂቡን በመጫን ላይ…',
    // Pagination
    previous: 'ቀዳሚ',
    next: 'ቀጣይ',
    page: 'ገጽ',
    of: 'ከ',
    rowsPerPage: 'በእያንዳንዱ ገጽ ላይ ያሉ ረድፎች',
    // Filters
    filter: 'ማጣሪያ',
    clearFilters: 'ማጣሪያዎችን አጽዳ',
    applyFilters: 'ማጣሪያዎችን ተግብር',
    searchPlaceholder: 'ፈልግ…',
    allStatuses: 'ሁሉም ሁኔታዎች',
    allTypes: 'ሁሉም ዓይነቶች',
    // Actions
    actions: 'ድርጊቶች',
    moreActions: 'ተጨማሪ ድርጊቶች',
    viewDetails: 'ዝርዝር ይመልከቱ',
    // Dialogs / modals
    confirmTitle: 'እርግጠኛ ነዎት?',
    confirmDelete: 'ይህን ንጥል ይሰርዙ?',
    confirmArchive: 'ይህን ንጥል ያስቀምጡ?',
    confirmRestore: 'ይህን ንጥል ይመልሱ?',
    confirmApprove: 'ይህን ንጥል ያፅድቁ?',
    confirmReject: 'ይህን ንጥል ይቃወሙ?',
    cannotUndo: 'ይህ ድርጊት መቀልበስ አይቻልም።',
    // States
    emptyStateTitle: 'እስካሁን ምንም የለም',
    emptyStateDescription: 'ምንም መዝገቦች ከአሁኑ ማጣሪያዎቹ ጋር አይዛመዱም።',
    errorTitle: 'ችግር ተፈጥሯል',
    errorDescription: 'ያልተጠበቀ ስህተት ተፈጥሯል። እባክዎ እንደገና ይሞክሩ።',
    // Sidebar
    collapseSidebar: 'የጎን አሞሌ ሰብስብ',
    expandSidebar: 'የጎን አሞሌ ዘርጋ',
    openMenu: 'ምናሌ ክፈት',
    closeMenu: 'ምናሌ ዝጋ',
    // Theme / language
    switchTheme: 'ገጽታ ቀይር',
    switchLanguage: 'ቋንቋ ቀይር',
    lightMode: 'ብርሃን',
    darkMode: 'ጨለማ',
    systemMode: 'ስርዓት',
    // Command palette
    commandMenu: 'ትዕዛዝ ምናሌ',
    typeCommandOrSearch: 'ትዕዛዝ ፃፉ ወይም ፈልጉ…',
    noCommandResults: 'ምንም ውጤት አልተገኘም',
    // Form helpers
    required: 'ያስፈልጋል',
    optional: 'አማራጭ',
    characters: 'ፊደላት',
    selectOption: 'አማራጭ ይምረጡ',
    // Status generic
    active: 'ንቁ',
    inactive: 'ንቁ ያልሆነ',
    pending: 'በመጠባበቅ ላይ',
    approved: 'ፀድቋል',
    rejected: 'ውድቅ ተደርጓል',
    archived: 'ተቀምጧል',
    draft: 'ረቂቅ',
} as const;

export default ui;
