/**
 * The court staff sign-in (Pages/Court/Auth/Login). Merged into
 * court/i18n/{en,am}.ts as `court.signIn`.
 */
const signIn = {
    en: {
        eyebrow: 'Addis Ababa City Administration',
        title: 'Court staff sign-in',
        subtitle: 'For the registry, judges, inspectors and administrators of the tribunal.',
        panelHeading: 'One record for every case',
        panelText: 'From filing to the final decision, kept in the register of the tribunal.',
        steps: {
            filing: { title: 'Filing', text: 'Filed by the employee in the Agelgay employee portal' },
            review: { title: 'Review', text: 'The registry checks the file and assigns it' },
            hearing: { title: 'Hearing', text: 'Hearings scheduled and the parties notified' },
            decision: { title: 'Decision', text: 'Drafted, published and served on the parties' },
            appeal: { title: 'Appeal', text: 'Contested decisions followed to their end' },
        },
        securityNote: 'Authorised tribunal staff only. Every sign-in is recorded.',
        partiesTitle: 'Applicant or employee?',
        partiesText: 'Applicants file and follow their cases in the Agelgay employee portal, under My Portal › My court cases.',
        partiesLink: 'Go to My Portal',
        lawyerText: 'A lawyer in the court\'s register?',
        lawyerLink: 'Lawyers sign in here',
    },
    am: {
        eyebrow: 'የአዲስ አበባ ከተማ አስተዳደር',
        title: 'የፍርድ ቤት ሠራተኞች መግቢያ',
        subtitle: 'ለፍርድ ቤቱ መዝገብ ቤት ሠራተኞች፣ ዳኞች፣ ኢንስፔክተሮችና አስተዳዳሪዎች።',
        panelHeading: 'ለእያንዳንዱ ጉዳይ አንድ መዝገብ',
        panelText: 'ከክስ አቀራረብ እስከ የመጨረሻ ውሳኔ፣ በፍርድ ቤቱ መዝገብ ውስጥ ይያዛል።',
        steps: {
            filing: { title: 'ክስ ማቅረብ', text: 'ሠራተኛው ክሱን በአገልጋይ የሠራተኛ ፖርታል ያቀርባል' },
            review: { title: 'ግምገማ', text: 'መዝገብ ቤቱ መዝገቡን መርምሮ ይመድባል' },
            hearing: { title: 'ችሎት', text: 'ችሎት ይቀጠራል፤ ተከራካሪዎችም እንዲያውቁት ይደረጋል' },
            decision: { title: 'ውሳኔ', text: 'ውሳኔ ተጽፎ ይታተማል፤ ለተከራካሪዎች ይደርሳል' },
            appeal: { title: 'ይግባኝ', text: 'ይግባኝ የተባለባቸው ውሳኔዎች እስከ መጨረሻው ይከታተላሉ' },
        },
        securityNote: 'ለተፈቀደላቸው የፍርድ ቤት ሠራተኞች ብቻ። እያንዳንዱ መግቢያ ይመዘገባል።',
        partiesTitle: 'አመልካች ወይም ሠራተኛ ነዎት?',
        partiesText: 'አመልካቾች ጉዳያቸውን በአገልጋይ የሠራተኛ ፖርታል፣ በየእኔ ፖርታል › የፍርድ ቤት ጉዳዮቼ ያቀርባሉ፤ ይከታተላሉም።',
        partiesLink: 'ወደ የእኔ ፖርታል ይሂዱ',
        lawyerText: 'በፍርድ ቤቱ የተመዘገቡ ጠበቃ ነዎት?',
        lawyerLink: 'ጠበቆች እዚህ ይግቡ',
    },
};

export default signIn;
