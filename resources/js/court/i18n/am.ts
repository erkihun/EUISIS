// court-ms's dictionary, merged over the EUISIS one (scripts/sync-from-euisis.mjs patches i18n/registry.ts).
// `cm.*` is court-ms's own lang/am/*.php, exported by `php artisan court:client-lang`, so a React
// screen says what the Blade screen it replaces said; `court.*` is text only the React screens need.
import lang from './lang/am';
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
            headline: 'አንድ መዝገብ ቤት። እያንዳንዱ መዝገብ።',
            headlineAccent: 'ከማመልከቻ እስከ ውሳኔ።',
            steps: {
                city: { title: 'ማመልከቻ', text: 'አመልካች በመስመር ላይ ወይም በመስኮት መዝገብ ያስከፍታል' },
                institutions: { title: 'ግምገማ', text: 'መዝገብ ቤቱ ፋይሉን አረጋግጦ ይመድባል' },
                employees: { title: 'ችሎት', text: 'ቀጠሮዎች ይያዛሉ፣ ተከራካሪዎች ይጠራሉ፣ ማስታወሻ ይያዛል' },
                card: { title: 'ውሳኔ', text: 'ከቅጽ ተዘጋጅቶ፣ ጸድቆ፣ ይደርሳል' },
                verified: { title: 'ይግባኝ', text: 'የተቃወሙ ውሳኔዎች እስከ መጨረሻው ይከታተላሉ' },
            },
            cardTitle: 'የመዝገብ ፋይል',
            cardVerified: 'ተወስኗል',
            services: {
                cafeteria: 'መዝገቦች',
                transport: 'ችሎቶች',
                performance: 'ውሳኔዎች',
                grievance: 'ደብዳቤዎች',
                activity: 'ምርመራዎች',
                reports: 'ሪፖርቶች',
            },
            capsLock: 'Caps Lock በርቷል',
        },
    },
    court: {
        workspaceName: 'የፍርድ ቤት ጉዳዮች አስተዳደር',
        exit: 'ከፍርድ ቤት የሥራ ቦታ ውጣ',
        nav: {
            landing: 'የመግቢያ ገጽ',
        },
        decisions: {
            deleteConfirm: 'ይህ ውሳኔ ይሰረዝ? ይህ ሊቀለበስ አይችልም።',
        },
        terms: {
            viewPublic: 'የሕዝብ ገጹን ይመልከቱ',
        },
        audit: {
            title: 'ሙሉ የኦዲት መዝገብ',
            description: 'የሥርዓት፣ የመዝገብ እና የሞዴል ክስተቶች በአንድ የጊዜ መስመር።',
            search: 'ፈልግ',
            searchPlaceholder: 'ተግባር፣ የጥያቄ መለያ፣ አይፒ…',
            source: 'ምንጭ',
            outcome: 'ውጤት',
            actorType: 'የፈጻሚ አይነት',
            method: 'ዘዴ',
            dateFrom: 'ከቀን',
            dateTo: 'እስከ ቀን',
            all: 'ሁሉም',
            sources: { system: 'የሥርዓት እንቅስቃሴ', case: 'የመዝገብ እንቅስቃሴ', legacy_model: 'የሞዴል ታሪክ' },
            columns: { time: 'ጊዜ / ምንጭ', action: 'ተግባር', actor: 'ፈጻሚ', target: 'ዒላማ', request: 'ጥያቄ', details: 'ዝርዝር' },
            viewDetails: 'ዝርዝር ይመልከቱ',
            noDetails: 'ተጨማሪ ዝርዝር የለም',
            empty: 'ተዛማጅ የኦዲት ክስተት የለም።',
        },
        caseDetail: caseDetail.am,
        accessForms: access.am,
        decisionForms: decisionForms.am,
        letterForms: letterForms.am,
        inspectionPages: inspectionPages.am,
        performancePages: performancePages.am,
        applicantCases: applicantCases.am,
        publicPages: publicPages.am,
        signIn: signIn.am,
        publicSite: publicSite.am,
        respondentPages: respondentPages.am,
        applicantPages: applicantPages.am,
        settings: {
            secretSaved: 'ተቀምጧል',
            secretNotSet: 'አልተዘጋጀም',
            secretRemove: 'የተቀመጠውን እሴት አስወግድ',
            secretKeep: 'የተቀመጠውን እሴት ለማቆየት ባዶ ይተዉት። እሴቱ በፍጹም አይታይም።',
            none: 'የለም',
        },
        appeals: {
            number: 'የይግባኝ ቁጥር',
            decidedBy: 'የወሰነው',
            newAppeal: 'አዲስ ይግባኝ',
            open: 'ክፈት',
            empty: 'እስካሁን ይግባኝ የለም።',
            editTitle: 'ይግባኝ አስተካክል',
            case: 'መዝገብ',
            chooseCase: 'መዝገብ ይምረጡ…',
            title: 'ርዕስ',
            grounds: 'ምክንያቶች',
            save: 'አስቀምጥ',
            cancel: 'ሰርዝ',
            submit: 'ይግባኝ አስገባ',
            edit: 'አስተካክል',
            documents: 'ሰነዶች',
            documentLabel: 'መለያ',
            documentFile: 'ፋይል',
            upload: 'ጫን',
            download: 'አውርድ',
            remove: 'አስወግድ',
            removeConfirm: 'ይህ ሰነድ ይወገድ?',
            noDocuments: 'የተያያዘ ሰነድ የለም።',
            decide: 'ውሳኔ',
            decision: 'ውሳኔ',
            decisionNotes: 'ማስታወሻ (አማራጭ)',
            record: 'ውሳኔ መዝግብ',
            decisionApproved: 'ተፈቅዷል',
            decisionRejected: 'ውድቅ ተደርጓል',
            decisionClosed: 'ተዘግቷል',
            decidedAt: 'የተወሰነበት',
            submittedAt: 'የቀረበበት',
        },
        hearings: {
            date: 'ቀን',
            dateTime: 'ቀን እና ሰዓት',
            type: 'የችሎት ስም',
            hearing: 'ችሎት',
            createdBy: 'የተፈጠረው በ',
            location: 'ቦታ',
            notes: 'ማስታወሻ',
        },
        dashboard: {
            period: 'ጊዜ',
            ranges: {
                all: 'ሁሉም ጊዜ',
                '7d': 'ያለፉት 7 ቀናት',
                '30d': 'ያለፉት 30 ቀናት',
                '90d': 'ያለፉት 90 ቀናት',
                '6m': 'ያለፉት 6 ወራት',
                '12m': 'ያለፉት 12 ወራት',
            },
            channels: {
                mail: 'ኢሜይል',
                sms: 'ኤስኤምኤስ',
                telegram: 'ቴሌግራም',
                in_app: 'በሲስተሙ ውስጥ',
            },
        },
    },
};
