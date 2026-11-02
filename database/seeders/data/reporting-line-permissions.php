<?php

declare(strict_types=1);

/*
 * Reporting hierarchy and supervisor resolution (ADR 0025).
 *
 * A permission says WHAT may be done; the reporting line says FOR WHOM.
 * Holding a head position grants nothing by itself: seeing one's team needs
 * reporting-lines.view_team AND the resolved relationship, and every
 * supervisor action in a workflow keeps that workflow's own permission.
 */
return [
    ['name' => 'reporting-lines.view_team', 'group' => 'reporting-lines', 'sort_order' => 10, 'is_system' => true,
        'label_en' => 'View Own Team', 'label_am' => 'የራስን ቡድን ይመልከቱ',
        'description_en' => 'See the employees who report to the signed-in employee, directly and through their units. Only resolved reports are shown; the permission alone lists nobody.',
        'description_am' => 'ለገባው ሠራተኛ በቀጥታና በሥራ ክፍሎቻቸው በኩል ሪፖርት የሚያደርጉ ሠራተኞችን ያያል። የተፈቱ ሪፖርቶች ብቻ ይታያሉ፤ ፈቃዱ ብቻውን ማንንም አይዘረዝርም።'],
    ['name' => 'reporting-lines.delegate', 'group' => 'reporting-lines', 'sort_order' => 20, 'is_system' => true,
        'label_en' => 'Delegate Own Supervisor Actions', 'label_am' => 'የራስን የኃላፊ ተግባራት ውክልና ይስጡ',
        'description_en' => 'Lend named supervisor actions over one\'s own reports to a colleague for a period. Delegated authority never exceeds the delegator\'s and cannot be delegated again.',
        'description_am' => 'በራስ ሪፖርት አድራጊዎች ላይ የተሰየሙ የኃላፊ ተግባራትን ለተወሰነ ጊዜ ለባልደረባ ያውላል። የተወከለ ሥልጣን ከወካዩ አይበልጥም፤ ዳግም ሊወከል አይችልም።'],
    ['name' => 'reporting-lines.view_any', 'group' => 'reporting-lines', 'sort_order' => 30, 'is_system' => true,
        'label_en' => 'View Reporting Lines', 'label_am' => 'የተጠሪነት መስመሮችን ይመልከቱ',
        'description_en' => 'See any employee\'s supervisor, reporting chain and reports within the organization scope, and the reporting-line integrity report.',
        'description_am' => 'በተቋም ወሰን ውስጥ የማንኛውንም ሠራተኛ ኃላፊ፣ የሪፖርት ሰንሰለትና ሪፖርት አድራጊዎች እንዲሁም የተጠሪነት መስመር ትክክለኛነት ሪፖርትን ያያል።'],
    ['name' => 'reporting-lines.manage', 'group' => 'reporting-lines', 'sort_order' => 40, 'is_system' => true,
        'label_en' => 'Manage Acting Appointments and Delegations', 'label_am' => 'ተጠባባቂ ሹመቶችንና ውክልናዎችን ያስተዳድሩ',
        'description_en' => 'Create and revoke acting appointments and supervisor delegations for employees within the organization scope.',
        'description_am' => 'በተቋም ወሰን ውስጥ ላሉ ሠራተኞች ተጠባባቂ ሹመቶችንና የኃላፊ ውክልናዎችን ይፈጥራል፣ ይሽራል።'],
];
