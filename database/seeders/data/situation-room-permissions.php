<?php

declare(strict_types=1);

/*
 * Executive Situation Room per-domain permissions (docs/situation-room-access-control.md).
 *
 * The room's core grants (situation_room.view, wall_mode, export,
 * view_employee_targeted, view_detail, manage_actions) are in permissions.php.
 * These open the categories that read other modules' data. A permission says
 * WHAT a viewer may see; OrganizationScopeService says WHERE.
 */
return [
    ['name' => 'situation_room.workforce.view', 'group' => 'situation_room', 'sort_order' => 70, 'is_system' => true,
        'label_en' => 'View workforce analytics', 'label_am' => 'የሰው ኃይል ትንተናን ይመልከቱ',
        'description_en' => 'View workforce composition, staffing, vacancy, movement, recruitment and ID card aggregates.',
        'description_am' => 'የሰው ኃይል ስብጥር፣ የሥራ መደብ ሽፋን፣ ክፍት ቦታ፣ የዝውውር፣ የቅጥር እና የመታወቂያ ካርድ ድምሮችን ማየት ያስችላል።'],
    ['name' => 'situation_room.public_voice.view', 'group' => 'situation_room', 'sort_order' => 80, 'is_system' => true,
        'label_en' => 'View community voice analytics', 'label_am' => 'የሕዝብ ድምፅ ትንተናን ይመልከቱ',
        'description_en' => 'View counts of public Feedback, Tip-offs and Complaints, of service delivery and of reception visits. Never a submitter, a visitor or a narrative.',
        'description_am' => 'የሕዝብ አስተያየት፣ ጥቆማ፣ ቅሬታ፣ የአገልግሎት አሰጣጥ እና የአቀባበል ጉብኝት ቁጥሮችን ማየት ያስችላል። አቅራቢን፣ ጎብኚን ወይም ዝርዝር ጽሑፍን አያሳይም።'],
    ['name' => 'situation_room.operations.view', 'group' => 'situation_room', 'sort_order' => 90, 'is_system' => true,
        'label_en' => 'View operations analytics', 'label_am' => 'የሥራ እንቅስቃሴ ትንተናን ይመልከቱ',
        'description_en' => 'View Daily Activity Register, Field Work, entitlement and internal communication aggregates.',
        'description_am' => 'የዕለት ተግባር መመዝገቢያ፣ የመስክ ሥራ፣ የመብት እና የውስጥ ግንኙነት ድምሮችን ማየት ያስችላል።'],
    ['name' => 'situation_room.performance.view', 'group' => 'situation_room', 'sort_order' => 100, 'is_system' => true,
        'label_en' => 'View performance analytics', 'label_am' => 'የአፈጻጸም ትንተናን ይመልከቱ',
        'description_en' => 'View EPMS cycle progress, released rating distributions and institutional assessment coverage, small groups protected.',
        'description_am' => 'የአፈጻጸም ዑደት ሂደትን፣ የተለቀቁ የደረጃ ስርጭቶችን እና የተቋም ግምገማ ሽፋንን ማየት ያስችላል፤ ትናንሽ ቡድኖች ይጠበቃሉ።'],
    ['name' => 'situation_room.cafeteria.view', 'group' => 'situation_room', 'sort_order' => 110, 'is_system' => true,
        'label_en' => 'View cafeteria analytics', 'label_am' => 'የካፌቴሪያ ትንተናን ይመልከቱ',
        'description_en' => 'View meals served, refused scans and subsidy totals.',
        'description_am' => 'የቀረቡ ምግቦችን፣ ውድቅ የተደረጉ ቅኝቶችን እና የድጎማ ድምሮችን ማየት ያስችላል።'],
    ['name' => 'situation_room.transport.view', 'group' => 'situation_room', 'sort_order' => 115, 'is_system' => true,
        'label_en' => 'View transport analytics', 'label_am' => 'የትራንስፖርት ትንተናን ይመልከቱ',
        'description_en' => 'View verified boardings, riders, trips run and refused scans. Counts only; no employee is named.',
        'description_am' => 'የተረጋገጡ መሳፈሮችን፣ ተሳፋሪዎችን፣ የተካሄዱ ጉዞዎችን እና ውድቅ የተደረጉ ቅኝቶችን ማየት ያስችላል። ብዛት ብቻ፤ ማንም ሠራተኛ አይጠቀስም።'],
    ['name' => 'situation_room.cases.view', 'group' => 'situation_room', 'sort_order' => 120, 'is_system' => true,
        'label_en' => 'View grievance, discipline and appeal summary', 'label_am' => 'የቅሬታ፣ የዲሲፕሊን እና የይግባኝ ማጠቃለያን ይመልከቱ',
        'description_en' => 'View counts of employee grievances, disciplinary cases, appeals and Administrative Tribunal cases. Counts only, small groups protected.',
        'description_am' => 'የሠራተኛ ቅሬታዎችን፣ የዲሲፕሊን ጉዳዮችን፣ ይግባኞችን እና የአስተዳደር ፍርድ ቤት ጉዳዮችን ቁጥር ማየት ያስችላል። ቁጥር ብቻ፤ ትናንሽ ቡድኖች ይጠበቃሉ።'],
    // The settings group is edited on System Settings, under that module's own grant.
    ['name' => 'system-settings.manageSituationRoom', 'group' => 'system-settings', 'sort_order' => 116, 'is_system' => true,
        'label_en' => 'Manage Situation Room settings', 'label_am' => 'የሁኔታ መከታተያ ቅንብሮችን ያስተዳድሩ',
        'description_en' => 'Configure the Situation Room default period, cache, wall timing, small-group privacy threshold and Critical Attention limits.',
        'description_am' => 'የሁኔታ መከታተያ ነባሪ ጊዜ፣ የመረጃ ማቆያ፣ የግድግዳ ማሳያ ጊዜ፣ የትንሽ ቡድን ግላዊነት ወሰን እና የትኩረት ገደቦችን ማዋቀር ያስችላል።'],
];
