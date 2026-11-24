<?php

declare(strict_types=1);

/*
 * Institutional Reporting & Compliance permissions (Modules/Reporting/README.md).
 *
 * A permission alone is never enough: each is exercised for an institution in
 * the holder's organization scope, on the reporting side (the institution
 * that owes the report) or on the receiving side (the institution that
 * reviews it). The names are published by the module in
 * Modules\Reporting\Contracts\Data\ReportingRoles.
 */
$entry = static fn (string $name, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm): array => [
    'name' => $name,
    'group' => 'reporting',
    'sort_order' => $sortOrder,
    'is_system' => false,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    $entry('reporting.view', 10, 'View institutional reports', 'የተቋም ሪፖርቶችን ይመልከቱ',
        'See the reporting obligations, deadlines, statuses and compliance figures of the institutions in scope.', 'በወሰን ውስጥ ያሉ ተቋማትን የሪፖርት ግዴታዎች፣ የማቅረቢያ ቀኖች፣ ሁኔታዎችና የአፈጻጸም አሃዞች ይመልከቱ።'),
    $entry('reporting.prepare', 20, 'Prepare reports', 'ሪፖርቶችን ያዘጋጁ',
        'Write the narrative, upload the Word report and its annexes, and save drafts for the institutions in scope. Does not submit.', 'በወሰን ውስጥ ላሉ ተቋማት የሪፖርቱን ጽሑፍ ይጻፉ፣ የዎርድ ሪፖርቱንና አባሪዎቹን ይጫኑ፣ ረቂቆችን ያስቀምጡ። ማቅረብን አይጨምርም።'),
    $entry('reporting.submit', 30, 'Submit reports', 'ሪፖርቶችን ያቅርቡ',
        'Submit the institution\'s report: the version becomes final, is time-stamped and goes to review. Also asks for deadline extensions.', 'የተቋሙን ሪፖርት ያቅርቡ፤ ዕትሙ የመጨረሻ ይሆናል፣ ጊዜው ይመዘገባል፣ ወደ ግምገማ ይሄዳል። የጊዜ ማራዘሚያም ይጠይቃል።'),
    $entry('reporting.review', 40, 'Review reports', 'ሪፖርቶችን ይገምግሙ',
        'Review reports submitted to the institutions in scope: comment, return for revision, pass to approval, and create follow-up actions.', 'በወሰን ውስጥ ላሉ ተቋማት የቀረቡ ሪፖርቶችን ይገምግሙ፤ አስተያየት ይስጡ፣ ለማስተካከያ ይመልሱ፣ ወደ ማጽደቅ ያስተላልፉ፣ የክትትል ተግባራትን ይፍጠሩ።'),
    $entry('reporting.approve', 50, 'Approve reports', 'ሪፖርቶችን ያጽድቁ',
        'Take the approval step: approve, return or reject a reviewed report. A person never approves a report they submitted themselves.', 'የማጽደቅ ደረጃውን ያከናውኑ፤ የተገመገመ ሪፖርትን ያጽድቁ፣ ይመልሱ ወይም ውድቅ ያድርጉ። አንድ ሰው ራሱ ያቀረበውን ሪፖርት አያጸድቅም።'),
    $entry('reporting.reopen', 55, 'Reopen decided reports', 'የተወሰኑ ሪፖርቶችን እንደገና ይክፈቱ',
        'Reopen an approved or rejected report for revision, with a recorded reason. The decided versions stay as they were.', 'የጸደቀ ወይም ውድቅ የተደረገ ሪፖርትን ምክንያቱን በመመዝገብ ለማስተካከያ እንደገና ይክፈቱ። የተወሰኑት ዕትሞች እንዳሉ ይቆያሉ።'),
    $entry('reporting.schedules.publish', 60, 'Publish reporting schedules', 'የሪፖርት መርሐ ግብሮችን ያውጡ',
        'Create schedules, choose the institutions that must report, publish reporting periods and special reports, and name responsible officers.', 'መርሐ ግብሮችን ይፍጠሩ፣ ሪፖርት ማቅረብ ያለባቸውን ተቋማት ይምረጡ፣ የሪፖርት ጊዜዎችንና ልዩ ሪፖርቶችን ያውጡ፣ ኃላፊዎችን ይሰይሙ።'),
    $entry('reporting.templates.manage', 70, 'Manage report types and templates', 'የሪፖርት ዓይነቶችንና አብነቶችን ያስተዳድሩ',
        'Define report types, their narrative sections, required attachments and review steps, and publish template versions.', 'የሪፖርት ዓይነቶችን፣ የጽሑፍ ክፍሎቻቸውን፣ አስገዳጅ አባሪዎችንና የግምገማ ደረጃዎችን ይወስኑ፤ የአብነት ዕትሞችን ያውጡ።'),
    $entry('reporting.extensions.grant', 80, 'Grant extensions and exemptions', 'የጊዜ ማራዘሚያና ነፃ ማድረግ ይወስኑ',
        'Decide deadline extension requests and exempt, cancel or reinstate a reporting obligation, each with a recorded reason.', 'የጊዜ ማራዘሚያ ጥያቄዎችን ይወስኑ፤ የሪፖርት ግዴታን ምክንያቱን በመመዝገብ ነፃ ያድርጉ፣ ይሰርዙ ወይም ይመልሱ።'),
    $entry('reporting.consolidate', 90, 'Compile consolidated reports', 'የተጠቃለሉ ሪፖርቶችን ያጠናቅሩ',
        'Compile the approved reports of the institutions under a parent institution into one Word report.', 'በአንድ የበላይ ተቋም ሥር ያሉ ተቋማትን የጸደቁ ሪፖርቶች ወደ አንድ የዎርድ ሪፖርት ያጠናቅሩ።'),
    $entry('reporting.export', 100, 'Export the compliance register', 'የአፈጻጸም መዝገቡን ወደ ፋይል ያውጡ',
        'Download the compliance register of the institutions in scope as CSV or Excel. Each export is recorded.', 'በወሰን ውስጥ ያሉ ተቋማትን የአፈጻጸም መዝገብ በCSV ወይም በኤክሴል ያውርዱ። እያንዳንዱ ማውረድ ይመዘገባል።'),
    $entry('reporting.view_confidential', 110, 'Read confidential reports', 'ሚስጥራዊ ሪፖርቶችን ያንብቡ',
        'Open the narrative and files of reports whose type is confidential. The reporting institution and its reviewers read them without this grant.', 'ዓይነታቸው ሚስጥራዊ የሆኑ ሪፖርቶችን ጽሑፍና ፋይሎች ይክፈቱ። ሪፖርቱን ያቀረበው ተቋምና ገምጋሚዎቹ ያለዚህ መብት ያነቧቸዋል።'),
    $entry('reporting.audit.view', 120, 'Inspect reporting operations', 'የሪፖርት ሥርዓቱን አሠራር ይመርምሩ',
        'See the module\'s operational health: scheduled runs, events not yet delivered, failed files and notices.', 'የሞጁሉን የአሠራር ሁኔታ ይመልከቱ፦ በመርሐ ግብር የሚሠሩ ሥራዎች፣ ያልደረሱ መልእክቶች፣ ያልተሳኩ ፋይሎችና ማሳወቂያዎች።'),
    $entry('reporting.settings.manage', 130, 'Manage reporting settings', 'የሪፖርት ሥርዓቱን ቅንብሮች ያስተዳድሩ',
        'Change the module\'s settings, including the maintenance switch that pauses all writes and scheduled work.', 'የሞጁሉን ቅንብሮች ይለውጡ፤ ሁሉንም መጻፍና በመርሐ ግብር የሚሠሩ ሥራዎችን የሚያቆመውን የጥገና ማብሪያ ጨምሮ።'),
];
