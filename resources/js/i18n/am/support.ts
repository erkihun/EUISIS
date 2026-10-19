/* የድጋፍ ማዕከል (docs/assistant/support-helpdesk.md)። t() ቦታ ያዥ የለውም፤ በJSX ውስጥ ያዋህዱ። */
const support = {
    title: 'የድጋፍ ጥያቄዎች',
    nav: 'የድጋፍ ጥያቄዎች',

    index: {
        description: 'ስለ Addis One እገዛ ይጠይቁ። የእርስዎ ጥያቄዎች፣ ሁኔታቸው እና የድጋፍ ቡድኑ ማስታወሻዎች እዚህ ይቆያሉ።',
        descriptionAll: 'በድጋፍ ማዕከሉ ውስጥ ያሉ ሁሉም የድጋፍ ጥያቄዎች፣ ከደህንነቱ የተጠበቀ የምርመራ አውድ እና የጊዜ መስመር ጋር።',
        newTicket: 'አዲስ ጥያቄ',
        newTicketHint: 'ምን ለማድረግ እየሞከሩ እንደነበር እና ምን እንደተከሰተ ይግለጹ። የይለፍ ቃላትን፣ ኮዶችን ወይም የሌሎችን የግል መረጃ አይለጥፉ።',
        filterStatus: 'ሁኔታ',
        filterCategory: 'ምድብ',
        active: 'ንቁ',
        all: 'ሁሉም',
        reference: 'መለያ ቁጥር',
        ticket: 'ጥያቄ',
        category: 'ምድብ',
        priority: 'ቅድሚያ',
        status: 'ሁኔታ',
        reporter: 'አቅራቢ',
        team: 'ቡድን',
        created: 'የተፈጠረበት',
        none: 'በዚህ እይታ የሚመጥን ጥያቄ የለም።',
        submit: 'ጥያቄ ይላኩ',
    },

    show: {
        summary: 'ማጠቃለያ',
        description: 'መግለጫ',
        context: 'የተከሰተበት ቦታ',
        contextHint: 'ጥያቄው ሲቀርብ የተመዘገቡት ገጽ፣ አሳሽ እና ቋንቋ ብቻ ናቸው። ኩኪዎች፣ ቶከኖች፣ የራስጌ መረጃዎች ወይም የቅጽ መረጃ በፍጹም አይከማቹም።',
        noContext: 'የገጽ አውድ አልተመዘገበም።',
        timeline: 'የጊዜ መስመር',
        manage: 'ይህን ጥያቄ ያስተናግዱ',
        reporter: 'አቅራቢ',
        team: 'ቡድን',
        assignee: 'የተመደበለት',
        unassigned: 'አልተመደበም',
        created: 'የተፈጠረበት',
        updated: 'የመጨረሻ ዝመና',
        resolved: 'የተፈታበት',
        closed: 'የተዘጋበት',
        incident: 'የተያያዘ ክስተት',
        errorReference: 'የስህተት መለያ',
        module: 'ሞጁል',
        route: 'ገጽ',
        path: 'መንገድ',
        system: 'ሥርዓት',
        save: 'አስቀምጥ',
    },

    categories: {
        access: 'መዳረሻ ወይም የመዳረሻ መብት',
        error: 'የስህተት መልዕክት',
        data: 'የተሳሳተ ወይም የጎደለ መረጃ',
        how_to: 'እንዴት…',
        performance: 'ዘገምተኛ ወይም ምላሽ የማይሰጥ',
        other: 'ሌላ',
    },

    priorities: {
        low: 'ዝቅተኛ',
        normal: 'መደበኛ',
        high: 'ከፍተኛ',
    },

    statuses: {
        open: 'ክፍት',
        in_progress: 'በሂደት ላይ',
        resolved: 'የተፈታ',
        closed: 'የተዘጋ',
    },

    events: {
        created: 'ጥያቄ ተፈጥሯል',
        status_changed: 'ሁኔታ ተቀይሯል',
        assigned: 'ተመድቧል',
        note: 'ማስታወሻ',
    },

    context: {
        route: 'ገጽ',
        path: 'መንገድ',
        module: 'ሞጁል',
        locale: 'ቋንቋ',
        browser: 'አሳሽ',
        os: 'ኦፐሬቲንግ ሲስተም',
        device: 'መሣሪያ',
        error_reference: 'የስህተት መለያ',
        incident_reference: 'ክስተት',
        timestamp: 'የተመዘገበበት',
        viewport: 'የማያ ገጽ መጠን',
    },

    form: {
        title: 'አጭር ርዕስ',
        titleHint: 'አማራጭ ነው። ባዶ ከተተወ የመግለጫው የመጀመሪያ ቃላት ይወሰዳሉ።',
        description: 'ምን ተከሰተ?',
        category: 'ምድብ',
        priority: 'ቅድሚያ',
        status: 'ሁኔታ',
        assignee: 'መድብ ለ',
        team: 'ቡድን',
        note: 'ማስታወሻ',
        noteHint: 'በጥያቄው የጊዜ መስመር ላይ ለአቅራቢው ይታያል።',
    },

    flash: {
        created: 'ጥያቄው ተፈጥሯል። የድጋፍ ቡድኑ ይከታተላል።',
        updated: 'ጥያቄው ተዘምኗል።',
    },
};

export default support;
