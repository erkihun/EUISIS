<?php

declare(strict_types=1);

/*
 * Service Delivery Monitoring & Support permissions (docs/service-monitoring-security.md).
 *
 * Every permission is exercised inside OrganizationScopeService scope (the
 * participant's organization); city-level review, verification, locking and
 * reopening also need city-wide reach. Named Section I data has its own two
 * grants, withheld from City Admin and Public Service Bureau Admin.
 */
$entry = static fn (string $name, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm): array => [
    'name' => $name,
    'group' => 'service_monitoring',
    'sort_order' => $sortOrder,
    'is_system' => false,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    $entry('service_monitoring.view', 10, 'View service delivery monitoring', 'የአገልግሎት አሰጣጥ ክትትልን ይመልከቱ',
        'Open the module and see the reports of the institutions in scope, without names in Section I.',
        'ሞጁሉን መክፈትና በወሰኑ ውስጥ ያሉ ተቋማትን ሪፖርቶች ያለ ክፍል አንድ ስሞች ማየት።'),
    $entry('service_monitoring.submit', 20, 'Prepare service delivery reports', 'የአገልግሎት አሰጣጥ ሪፖርት ያዘጋጁ',
        'Enter and correct the quarterly report of an institution in scope while it is a draft or returned.',
        'በወሰኑ ውስጥ ያለ ተቋምን የሩብ ዓመት ሪፖርት ረቂቅ ወይም የተመለሰ ሲሆን ማስገባትና ማረም።'),
    $entry('service_monitoring.attest', 30, 'Attest and submit reports', 'ሪፖርትን አረጋግጠው ያቅርቡ',
        'Attest the institution\'s quarterly report under the declaration and submit it.',
        'የተቋሙን የሩብ ዓመት ሪፖርት በማረጋገጫ ቃሉ አረጋግጦ ማቅረብ።'),
    $entry('service_monitoring.review', 40, 'Review reports', 'ሪፖርቶችን ይገምግሙ',
        'Return or accept submitted reports: woreda reports within the sub-city\'s scope; sub-city and center reports with city-wide reach.',
        'የቀረቡ ሪፖርቶችን መመለስ ወይም መቀበል፡ የወረዳ ሪፖርቶችን በክ/ከተማው ወሰን፤ የክ/ከተማና የማዕከል ሪፖርቶችን በከተማ አቀፍ ወሰን።'),
    $entry('service_monitoring.consolidate', 50, 'Consolidate and certify', 'ማጠቃለልና ማረጋገጥ',
        'See the sub-city consolidation, record a woreda as not submitted and certify the consolidation.',
        'የክ/ከተማውን ማጠቃለያ ማየት፣ ወረዳን እንዳላቀረበ መመዝገብና ማጠቃለያውን ማረጋገጥ።'),
    $entry('service_monitoring.periods.manage', 60, 'Manage reporting periods', 'የሪፖርት ጊዜያትን ያስተዳድሩ',
        'Create fiscal years, change period schedules and lock periods.',
        'በጀት ዓመታትን መፍጠር፣ የጊዜ ሰሌዳዎችን መቀየርና ጊዜያትን መቆለፍ።'),
    $entry('service_monitoring.periods.reopen', 70, 'Reopen locked periods', 'የተቆለፉ ጊዜያትን እንደገና ይክፈቱ',
        'Reopen a locked period, with a recorded reason.',
        'የተቆለፈን ጊዜ በተመዘገበ ምክንያት እንደገና መክፈት።'),
    $entry('service_monitoring.participants.manage', 80, 'Manage reporting institutions', 'ሪፖርት የሚያቀርቡ ተቋማትን ያስተዳድሩ',
        'Keep the registry of reporting institutions, their levels and their support officers.',
        'ሪፖርት የሚያቀርቡ ተቋማትን መዝገብ፣ ደረጃቸውንና ደጋፊ ባለሙያዎቻቸውን መያዝ።'),
    $entry('service_monitoring.extensions.grant', 90, 'Grant deadline extensions', 'የጊዜ ማራዘሚያ ይስጡ',
        'Grant or revoke a time-boxed extension of one institution\'s deadline, with a reason.',
        'የአንድ ተቋምን የጊዜ ገደብ በምክንያት ለተወሰነ ጊዜ ማራዘም ወይም ማራዘሚያውን መሰረዝ።'),
    $entry('service_monitoring.accountability.view_named', 100, 'View names in Section I', 'በክፍል አንድ ስሞችን ይመልከቱ',
        'See the names, reasons and actions of the people held accountable, within scope. Every view is audited.',
        'በወሰኑ ውስጥ ተጠያቂ የተደረጉ ሰዎችን ስም፣ ምክንያትና እርምጃ ማየት። እያንዳንዱ እይታ ይመዘገባል።'),
    $entry('service_monitoring.accountability.export_named', 110, 'Export names in Section I', 'የክፍል አንድ ስሞችን ወደ ውጭ ይላኩ',
        'Include the names of the people held accountable in exports; without it, exports show the role category instead.',
        'ተጠያቂ የተደረጉ ሰዎችን ስም በሚላኩ ፋይሎች ውስጥ ማካተት፤ ያለዚህ ፈቃድ ፋይሎቹ የሥራ ድርሻውን ብቻ ያሳያሉ።'),
    $entry('service_monitoring.technology.verify', 120, 'Verify digital services', 'ዲጂታል አገልግሎቶችን ያረጋግጡ',
        'Innovation & Technology Bureau: verify or reject the digital services the institutions report, city-wide.',
        'የኢኖቬሽንና ቴክኖሎጂ ቢሮ፡ ተቋማት የሚያቀርቧቸውን ዲጂታል አገልግሎቶች በከተማ አቀፍ ደረጃ ማረጋገጥ ወይም ውድቅ ማድረግ።'),
    $entry('service_monitoring.support.respond', 130, 'Answer clarifications', 'የማብራሪያ ጥያቄዎችን ይመልሱ',
        'Answer and close the clarification questions of the institutions you support.',
        'የሚደግፏቸውን ተቋማት የማብራሪያ ጥያቄዎች መመለስና መዝጋት።'),
    $entry('service_monitoring.visits.manage', 140, 'Manage monitoring visits', 'የክትትል ጉብኝቶችን ያስተዳድሩ',
        'Record monitoring visits, their findings and action items.',
        'የክትትል ጉብኝቶችን፣ ግኝቶቻቸውንና የድርጊት ተግባሮቻቸውን መመዝገብ።'),
    $entry('service_monitoring.reports.view', 150, 'View monitoring reports', 'የክትትል ሪፖርቶችን ይመልከቱ',
        'See compliance, accountability, digital service and standard performance reports within scope.',
        'በወሰኑ ውስጥ የተገዢነት፣ የተጠያቂነት፣ የዲጂታል አገልግሎትና የስታንዳርድ አፈፃፀም ሪፖርቶችን ማየት።'),
    $entry('service_monitoring.reports.export', 160, 'Export official forms', 'ይፋዊ ቅጾችን ወደ ውጭ ይላኩ',
        'Export the official forms (XLSX, PDF) for an institution, a sub-city or the city.',
        'ይፋዊ ቅጾችን (XLSX፣ PDF) ለአንድ ተቋም፣ ለክ/ከተማ ወይም ለከተማው ወደ ውጭ መላክ።'),
    $entry('service_monitoring.settings.manage', 170, 'Manage monitoring settings', 'የክትትል ቅንብሮችን ያስተዳድሩ',
        'Change the declaration text, the stamped-copy requirement and the driver occupations.',
        'የማረጋገጫ ቃሉን፣ የማህተም ቅጂ መስፈርቱንና የሹፌር ሙያዎችን መቀየር።'),
];
