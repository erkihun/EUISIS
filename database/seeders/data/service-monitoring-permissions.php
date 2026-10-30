<?php

declare(strict_types=1);

/*
 * Service Delivery Monitoring permissions (docs/service-monitoring.md).
 *
 * Separated duties:
 *   forms     build, version and publish the monitoring forms (bureau)
 *   periods   open and close reporting periods (bureau)
 *   submit    fill and submit an institution's report, inside scope
 *   review    accept or return a submitted report, inside scope
 *   view      read reports and the consolidated summary, inside scope
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
    $entry('service_monitoring.forms.manage', 10, 'Build monitoring forms', 'የክትትል ቅጾችን ያዘጋጁ',
        'Create, edit, version, publish and archive service delivery monitoring forms and assign them to organization types.',
        'የአገልግሎት አሰጣጥ ክትትል ቅጾችን ይፍጠሩ፣ ያሻሽሉ፣ አዲስ እትም ያውጡ፣ ያትሙ፣ ያህደሩ እና ለተቋም ዓይነቶች ይመድቡ።'),
    $entry('service_monitoring.periods.manage', 20, 'Manage reporting periods', 'የሪፖርት ወቅቶችን ያስተዳድሩ',
        'Open and close the reporting periods institutions report against.',
        'ተቋማት ሪፖርት የሚያቀርቡባቸውን የሪፖርት ወቅቶች ይክፈቱ እና ይዝጉ።'),
    $entry('service_monitoring.reports.submit', 30, 'Fill monitoring reports', 'የክትትል ሪፖርት ይሙሉ',
        'Fill, save and submit the monitoring report of an institution within your scope.',
        'በወሰንዎ ውስጥ ያለ ተቋም የክትትል ሪፖርት ይሙሉ፣ ያስቀምጡ እና ያቅርቡ።'),
    $entry('service_monitoring.reports.review', 40, 'Review monitoring reports', 'የክትትል ሪፖርቶችን ይገምግሙ',
        'Accept a submitted monitoring report or return it to the institution for correction.',
        'የቀረበ የክትትል ሪፖርትን ይቀበሉ ወይም ለእርማት ለተቋሙ ይመልሱ።'),
    $entry('service_monitoring.reports.view', 50, 'View monitoring reports', 'የክትትል ሪፖርቶችን ይመልከቱ',
        'View institutions\' monitoring reports and the consolidated summary within your scope.',
        'በወሰንዎ ውስጥ ያሉ የተቋማትን የክትትል ሪፖርቶች እና የተጠቃለለውን ሪፖርት ይመልከቱ።'),
];
