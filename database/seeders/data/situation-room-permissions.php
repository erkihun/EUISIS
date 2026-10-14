<?php

declare(strict_types=1);

/*
 * Executive Situation Room permissions (docs/situation-room-access-control.md).
 *
 * A permission says WHAT a viewer may see; OrganizationScopeService says
 * WHERE. Aggregate grants never imply detail: naming employees needs
 * situation_room.employee_breakdown.view, and no grant here ever exposes a
 * public submitter's identity, narrative or evidence.
 */
return [
    ['name' => 'situation_room.view', 'group' => 'situation_room', 'sort_order' => 10, 'is_system' => true,
        'label_en' => 'Open the Situation Room', 'label_am' => 'የሁኔታ መከታተያ ማዕከልን ይክፈቱ',
        'description_en' => 'Open the Executive Situation Room launcher and its overview, structure, trend, attention and data-quality views, inside the organization scope.',
        'description_am' => 'የአመራር ሁኔታ መከታተያ ማዕከልን እና አጠቃላይ፣ መዋቅር፣ አዝማሚያ፣ ትኩረት እና የመረጃ ጥራት ገጾቹን በተቋም ወሰን ውስጥ ማየት ያስችላል።'],
    ['name' => 'situation_room.wall.view', 'group' => 'situation_room', 'sort_order' => 20, 'is_system' => true,
        'label_en' => 'Open Wall Mode', 'label_am' => 'የግድግዳ ማሳያን ይክፈቱ',
        'description_en' => 'Open the full-screen wall display. It shows aggregates only, never a name.',
        'description_am' => 'ሙሉ ማያ የግድግዳ ማሳያን መክፈት ያስችላል። ድምር ቁጥሮችን ብቻ ያሳያል፤ ስም አያሳይም።'],
    ['name' => 'situation_room.reports.view', 'group' => 'situation_room', 'sort_order' => 30, 'is_system' => true,
        'label_en' => 'Export Situation Room reports', 'label_am' => 'የሁኔታ መከታተያ ሪፖርቶችን ያውጡ',
        'description_en' => 'Export a category the viewer may see as CSV, Excel or PDF, confined to their scope.',
        'description_am' => 'ተመልካቹ ሊያየው የሚችለውን ዘርፍ በወሰኑ ውስጥ እንደ CSV፣ Excel ወይም PDF ማውጣት ያስችላል።'],
    ['name' => 'situation_room.workforce.view', 'group' => 'situation_room', 'sort_order' => 40, 'is_system' => true,
        'label_en' => 'View workforce analytics', 'label_am' => 'የሰው ኃይል ትንተናን ይመልከቱ',
        'description_en' => 'View workforce composition, staffing, vacancy and movement aggregates.',
        'description_am' => 'የሰው ኃይል ስብጥር፣ የሥራ መደብ ሽፋን፣ ክፍት ቦታ እና የዝውውር ድምሮችን ማየት ያስችላል።'],
    ['name' => 'situation_room.public_voice.view', 'group' => 'situation_room', 'sort_order' => 50, 'is_system' => true,
        'label_en' => 'View community voice analytics', 'label_am' => 'የሕዝብ ድምፅ ትንተናን ይመልከቱ',
        'description_en' => 'View counts of public Feedback, Tip-offs and Complaints and of service delivery. Never a submitter or a narrative.',
        'description_am' => 'የሕዝብ አስተያየት፣ ጥቆማ፣ ቅሬታ እና የአገልግሎት አሰጣጥ ቁጥሮችን ማየት ያስችላል። አቅራቢን ወይም ዝርዝር ጽሑፍን አያሳይም።'],
    ['name' => 'situation_room.employee_breakdown.view', 'group' => 'situation_room', 'sort_order' => 60, 'is_system' => true,
        'label_en' => 'View employee-level breakdown', 'label_am' => 'በሠራተኛ ደረጃ ዝርዝርን ይመልከቱ',
        'description_en' => 'Inside one selected organization, list employees with the number of public submissions about them, in name order. Every opening is audited.',
        'description_am' => 'በአንድ በተመረጠ ተቋም ውስጥ ስለ ሠራተኞች የቀረቡ የሕዝብ ማቅረቢያዎችን ቁጥር በስም ቅደም ተከተል ማየት ያስችላል። እያንዳንዱ መክፈት ይመዘገባል።'],
    ['name' => 'situation_room.operations.view', 'group' => 'situation_room', 'sort_order' => 70, 'is_system' => true,
        'label_en' => 'View operations analytics', 'label_am' => 'የሥራ እንቅስቃሴ ትንተናን ይመልከቱ',
        'description_en' => 'View Daily Activity Register and Field Work aggregates.',
        'description_am' => 'የዕለት ተግባር መመዝገቢያ እና የመስክ ሥራ ድምሮችን ማየት ያስችላል።'],
    ['name' => 'situation_room.performance.view', 'group' => 'situation_room', 'sort_order' => 80, 'is_system' => true,
        'label_en' => 'View performance analytics', 'label_am' => 'የአፈጻጸም ትንተናን ይመልከቱ',
        'description_en' => 'View EPMS cycle progress and released rating distributions, small groups protected.',
        'description_am' => 'የአፈጻጸም ዑደት ሂደትን እና የተለቀቁ የደረጃ ስርጭቶችን ማየት ያስችላል፤ ትናንሽ ቡድኖች ይጠበቃሉ።'],
    ['name' => 'situation_room.cafeteria.view', 'group' => 'situation_room', 'sort_order' => 90, 'is_system' => true,
        'label_en' => 'View cafeteria analytics', 'label_am' => 'የካፌቴሪያ ትንተናን ይመልከቱ',
        'description_en' => 'View meals served, refused scans and subsidy totals.',
        'description_am' => 'የቀረቡ ምግቦችን፣ ውድቅ የተደረጉ ቅኝቶችን እና የድጎማ ድምሮችን ማየት ያስችላል።'],
    ['name' => 'situation_room.cases.view', 'group' => 'situation_room', 'sort_order' => 100, 'is_system' => true,
        'label_en' => 'View grievance, discipline and appeal summary', 'label_am' => 'የቅሬታ፣ የዲሲፕሊን እና የይግባኝ ማጠቃለያን ይመልከቱ',
        'description_en' => 'View counts of employee grievances, disciplinary cases and appeals. Counts only, small groups protected.',
        'description_am' => 'የሠራተኛ ቅሬታዎችን፣ የዲሲፕሊን ጉዳዮችን እና ይግባኞችን ቁጥር ማየት ያስችላል። ቁጥር ብቻ፤ ትናንሽ ቡድኖች ይጠበቃሉ።'],
    ['name' => 'situation_room.actions.view', 'group' => 'situation_room', 'sort_order' => 110, 'is_system' => true,
        'label_en' => 'View the executive action register', 'label_am' => 'የአመራር እርምጃ መዝገብን ይመልከቱ',
        'description_en' => 'View executive actions recorded for organizations in scope.',
        'description_am' => 'በወሰን ውስጥ ላሉ ተቋማት የተመዘገቡ የአመራር እርምጃዎችን ማየት ያስችላል።'],
    ['name' => 'situation_room.actions.manage', 'group' => 'situation_room', 'sort_order' => 120, 'is_system' => true,
        'label_en' => 'Manage the executive action register', 'label_am' => 'የአመራር እርምጃ መዝገብን ያስተዳድሩ',
        'description_en' => 'Record and update executive actions for organizations in scope. Never changes the records the action is about.',
        'description_am' => 'በወሰን ውስጥ ላሉ ተቋማት የአመራር እርምጃዎችን መመዝገብ እና ማሻሻል ያስችላል። እርምጃው የሚመለከታቸውን መዛግብት አይለውጥም።'],
    // The settings group is edited on System Settings, under that module's own grant.
    ['name' => 'system-settings.manageSituationRoom', 'group' => 'system-settings', 'sort_order' => 116, 'is_system' => true,
        'label_en' => 'Manage Situation Room settings', 'label_am' => 'የሁኔታ መከታተያ ቅንብሮችን ያስተዳድሩ',
        'description_en' => 'Configure the Situation Room default period, cache, wall timing, small-group privacy threshold and Critical Attention limits.',
        'description_am' => 'የሁኔታ መከታተያ ነባሪ ጊዜ፣ የመረጃ ማቆያ፣ የግድግዳ ማሳያ ጊዜ፣ የትንሽ ቡድን ግላዊነት ወሰን እና የትኩረት ገደቦችን ማዋቀር ያስችላል።'],
];
