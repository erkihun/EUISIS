<?php

declare(strict_types=1);

/*
 * Field Work Management permissions (docs/field-work-security.md).
 *
 * Separated duties, never one broad module permission:
 *   own        the requester / participant acting on their own field work
 *   team       the resolved immediate supervisor (needs a line-manager
 *              assignment as well as the permission)
 *   oversight  read-only, inside OrganizationScopeService scope
 *   location   GPS verification status vs. exact coordinates (privileged)
 *   config     the field work type catalog
 */
$entry = static fn (string $name, int $sortOrder, string $labelEn, string $labelAm, string $descriptionEn, string $descriptionAm): array => [
    'name' => $name,
    'group' => 'field_work',
    'sort_order' => $sortOrder,
    'is_system' => false,
    'label_en' => $labelEn,
    'label_am' => $labelAm,
    'description_en' => $descriptionEn,
    'description_am' => $descriptionAm,
];

return [
    // Employee self-service
    $entry('field_work.view_own', 10, 'View own field work', 'የራስን የመስክ ሥራ ይመልከቱ',
        'View your own field work requests and the field work you take part in.',
        'የራስዎን የመስክ ሥራ ጥያቄዎች እና የሚሳተፉበትን የመስክ ሥራ ይመልከቱ።'),
    $entry('field_work.create_own', 20, 'Request field work', 'የመስክ ሥራ ይጠይቁ',
        'Create a field work request for yourself.',
        'ለራስዎ የመስክ ሥራ ጥያቄ ይፍጠሩ።'),
    $entry('field_work.create_team', 25, 'Request team field work', 'የቡድን የመስክ ሥራ ይጠይቁ',
        'Add colleagues from your own unit as participants of your field work request.',
        'ከራስዎ ክፍል የሥራ ባልደረቦችን በመስክ ሥራ ጥያቄዎ ላይ ተሳታፊ አድርገው ይጨምሩ።'),
    $entry('field_work.edit_own_draft', 30, 'Edit own field work draft', 'የራስን ረቂቅ የመስክ ሥራ ያሻሽሉ',
        'Edit your own field work request while it is a draft or returned for correction.',
        'የመስክ ሥራ ጥያቄዎ ረቂቅ ወይም ለእርማት የተመለሰ ሲሆን ያሻሽሉ።'),
    $entry('field_work.submit_own', 40, 'Submit field work request', 'የመስክ ሥራ ጥያቄ ያስገቡ',
        'Submit your own field work request to your immediate supervisor.',
        'የራስዎን የመስክ ሥራ ጥያቄ ለቅርብ ኃላፊዎ ያስገቡ።'),
    $entry('field_work.cancel_own', 50, 'Cancel own field work', 'የራስን የመስክ ሥራ ይሰርዙ',
        'Cancel your own field work request before anyone has checked in.',
        'ማንም ተመዝግቦ ከመግባቱ በፊት የራስዎን የመስክ ሥራ ጥያቄ ይሰርዙ።'),
    $entry('field_work.check_in', 60, 'Field work GPS check-in', 'የመስክ ሥራ የጂፒኤስ መግቢያ',
        'Record your GPS check-in on approved field work you take part in.',
        'በሚሳተፉበት የጸደቀ የመስክ ሥራ ላይ የጂፒኤስ መግቢያዎን ይመዝግቡ።'),
    $entry('field_work.check_out', 70, 'Field work GPS check-out', 'የመስክ ሥራ የጂፒኤስ መውጫ',
        'Record your GPS check-out from field work you checked in to.',
        'ከገቡበት የመስክ ሥራ የጂፒኤስ መውጫዎን ይመዝግቡ።'),
    $entry('field_work.complete', 80, 'Complete field work', 'የመስክ ሥራ ያጠናቅቁ',
        'Close your own field work with the actual return and a completion note.',
        'የራስዎን የመስክ ሥራ በትክክለኛው የመመለሻ ጊዜ እና የማጠናቀቂያ ማስታወሻ ይዝጉ።'),

    // Immediate supervisor
    $entry('field_work.view_team', 110, 'View team field work', 'የቡድን የመስክ ሥራ ይመልከቱ',
        'View field work of the employees your line-manager assignment covers.',
        'የቅርብ ኃላፊነት ምደባዎ የሚሸፍናቸውን ሠራተኞች የመስክ ሥራ ይመልከቱ።'),
    $entry('field_work.approve', 120, 'Approve field work', 'የመስክ ሥራ ያጽድቁ',
        'Approve field work requests for which you are the resolved immediate supervisor.',
        'የቅርብ ኃላፊ ሆነው የተለዩባቸውን የመስክ ሥራ ጥያቄዎች ያጽድቁ።'),
    $entry('field_work.return', 130, 'Return field work for correction', 'የመስክ ሥራ ለእርማት ይመልሱ',
        'Return a field work request to its requester for correction, with a reason.',
        'የመስክ ሥራ ጥያቄን ከምክንያት ጋር ለእርማት ለጠያቂው ይመልሱ።'),
    $entry('field_work.reject', 140, 'Reject field work', 'የመስክ ሥራ ውድቅ ያድርጉ',
        'Reject a field work request, with a reason.',
        'የመስክ ሥራ ጥያቄን ከምክንያት ጋር ውድቅ ያድርጉ።'),

    // Scoped oversight
    $entry('field_work.view_org', 210, 'View organization field work', 'የተቋም የመስክ ሥራ ይመልከቱ',
        'Read-only view of field work in the organizations within your scope.',
        'በወሰንዎ ውስጥ ባሉ ተቋማት ያለውን የመስክ ሥራ በንባብ ብቻ ይመልከቱ።'),

    // GPS
    $entry('field_work.location.view_status', 310, 'View GPS verification status', 'የጂፒኤስ ማረጋገጫ ሁኔታ ይመልከቱ',
        'See whether a check-in or check-out was verified, outside the expected area or low accuracy. No coordinates.',
        'መግቢያ ወይም መውጫ መረጋገጡን፣ ከሚጠበቀው ቦታ ውጭ መሆኑን ወይም ትክክለኛነቱ ዝቅተኛ መሆኑን ይመልከቱ። መጋጠሚያ የለም።'),
    $entry('field_work.location.view_precise', 320, 'View exact GPS coordinates', 'ትክክለኛ የጂፒኤስ መጋጠሚያዎችን ይመልከቱ',
        'Privileged: see exact latitude, longitude, accuracy and distance of field work check-ins and check-outs within scope.',
        'ልዩ ፈቃድ፡ በወሰን ውስጥ ያሉ የመስክ ሥራ መግቢያ እና መውጫዎችን ትክክለኛ ኬክሮስ፣ ኬንትሮስ፣ ትክክለኛነት እና ርቀት ይመልከቱ።'),

    // Configuration
    $entry('field_work.manage_types', 410, 'Manage field work types', 'የመስክ ሥራ ዓይነቶችን ያስተዳድሩ',
        'Create, edit, activate and deactivate the field work type catalog.',
        'የመስክ ሥራ ዓይነቶችን ዝርዝር ይፍጠሩ፣ ያሻሽሉ፣ ያንቀሳቅሱ እና ያቦዝኑ።'),
];
